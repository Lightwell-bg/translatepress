"""FastAPI application factory. Run: ``uvicorn app.main:app``."""

from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager
from datetime import timedelta

from fastapi import FastAPI
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, async_sessionmaker
from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app import quota
from app.api.billing import WEBHOOK_PATH
from app.api.billing import router as billing_router
from app.api.routes import router
from app.billing.events import BillingCatalog, EventProcessor
from app.billing.gateway import StripeGateway
from app.config import Settings
from app.db import make_engine, make_sessionmaker
from app.errors import error_response, install_handlers
from app.pricing import Pricing
from app.providers.base import TranslationProvider
from app.sweeper import sweeper_loop
from app.translate_service import ServiceConfig, TranslationService

log = logging.getLogger("lingocanvas")


class BodySizeLimitMiddleware:
    """Rejects bodies over ``max_bytes`` with 413 (Content-Length and streamed size)."""

    def __init__(self, app: ASGIApp, max_bytes: int, path_limits: dict[str, int] | None = None) -> None:
        self.app = app
        self.max_bytes = max_bytes
        # Paths with their own limit (the Stripe webhook): the global limit does not apply to them.
        self.path_limits = path_limits or {}

    @staticmethod
    def _too_large_response() -> "object":
        return error_response(413, "payload_too_large", "The request body is too large.")

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        max_bytes = self.path_limits.get(scope.get("path", ""), self.max_bytes)
        declared = dict(scope["headers"]).get(b"content-length")
        if declared is not None:
            try:
                if int(declared) > max_bytes:
                    await self._too_large_response()(scope, receive, send)
                    return
            except ValueError:
                pass  # malformed header: the streamed check below still applies

        # Buffer the body (bounded by max_bytes) so an over-size stream is
        # rejected before the application sees any of it.
        messages: list[Message] = []
        total = 0
        while True:
            message = await receive()
            messages.append(message)
            if message["type"] == "http.disconnect":
                break
            total += len(message.get("body", b""))
            if total > max_bytes:
                await self._too_large_response()(scope, receive, send)
                return
            if not message.get("more_body", False):
                break

        replay = iter(messages)

        async def replaying_receive() -> Message:
            try:
                return next(replay)
            except StopIteration:
                return await receive()

        await self.app(scope, replaying_receive, send)


def build_catalog(settings: Settings) -> BillingCatalog:
    """Stripe Price ids (from .env) -> plans and packs (characters from config.ini)."""
    cfg = settings.cfg
    plan_by_price = {settings.stripe_price_starter: "starter"} if settings.stripe_price_starter else {}
    pack_by_price = {settings.stripe_price_pack_250k: "pack_250k"} if settings.stripe_price_pack_250k else {}
    return BillingCatalog(
        plan_by_price=plan_by_price,
        pack_by_price=pack_by_price,
        plan_chars={name: plan.chars_per_month for name, plan in cfg.plans.items()},
        pack_chars=dict(cfg.packs),
        pack_valid_months=cfg.billing.pack_valid_months,
    )


def create_app(
    settings: Settings | None = None,
    provider: TranslationProvider | None = None,
    gateway: StripeGateway | None = None,
) -> FastAPI:
    settings = settings or Settings()  # type: ignore[call-arg]
    cfg = settings.cfg
    engine: AsyncEngine = make_engine(settings)
    sessionmaker: async_sessionmaker[AsyncSession] = make_sessionmaker(engine)

    if provider is None:
        if settings.provider == "deepseek":
            from app.providers.deepseek import DeepSeekProvider

            key = settings.deepseek_api_key.get_secret_value()
            if not key:
                raise RuntimeError("DEEPSEEK_API_KEY is required when PROVIDER=deepseek")
            provider = DeepSeekProvider(
                api_key=key,
                base_url=cfg.deepseek.base_url,
                model=cfg.deepseek.model,
                timeout_seconds=cfg.deepseek.timeout_seconds,
            )
        else:
            from app.providers.mock import MockProvider

            provider = MockProvider()

    limits = cfg.limits
    service = TranslationService(
        sessionmaker,
        provider,
        Pricing(cfg.pricing),
        ServiceConfig(
            limits=quota.Limits(
                reservation_ttl=timedelta(seconds=limits.reservation_ttl_seconds),
                per_site_requests_per_minute=limits.per_site_requests_per_minute,
                max_in_flight_per_account=limits.max_in_flight_per_account,
                max_replays=limits.max_replays,
                max_attempts=limits.max_attempts,
            ),
            daily_spend_limit_micro_usd=settings.daily_spend_limit_micro_usd,
            max_output_tokens=cfg.deepseek.max_output_tokens,
            call_deadline_seconds=limits.call_deadline_seconds,
        ),
    )

    billing_enabled = settings.billing_enabled
    catalog = build_catalog(settings)
    processor: EventProcessor | None = None
    if billing_enabled:
        if gateway is None:
            from app.billing.stripe_gateway import API_VERSION, StripeSdkGateway

            gateway = StripeSdkGateway(
                settings.stripe_secret_key.get_secret_value(),
                API_VERSION,
                settings.stripe_portal_configuration or None,
                list(catalog.plan_by_price),
            )
        processor = EventProcessor(sessionmaker, gateway, catalog)

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        task = asyncio.create_task(
            sweeper_loop(sessionmaker, limits.sweeper_interval_seconds, cfg.billing.unmatched_retention_days)
        )
        try:
            yield
        finally:
            task.cancel()
            try:
                await task
            except asyncio.CancelledError:
                pass
            close = getattr(provider, "aclose", None)
            if close is not None:
                await close()
            await engine.dispose()

    app = FastAPI(title="LingoCanvas Cloud", lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)
    app.state.settings = settings
    app.state.engine = engine
    app.state.sessionmaker = sessionmaker
    app.state.provider = provider
    app.state.service = service
    app.state.billing_enabled = billing_enabled
    app.state.catalog = catalog
    app.state.gateway = gateway
    app.state.processor = processor
    install_handlers(app)
    app.include_router(router)
    app.include_router(billing_router)
    app.add_middleware(
        BodySizeLimitMiddleware,
        max_bytes=limits.max_body_bytes,
        path_limits={WEBHOOK_PATH: cfg.billing.webhook_max_body_bytes},
    )
    return app


def __getattr__(name: str):
    # ``uvicorn app.main:app`` builds the app lazily so importing this module
    # (tests, CLI) does not require a configured environment.
    if name == "app":
        global app
        app = create_app()
        return app
    raise AttributeError(name)
