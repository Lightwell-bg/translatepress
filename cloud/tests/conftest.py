"""Shared fixtures: embedded PostgreSQL (pgserver), schema via alembic, app client, factories.

The test cluster lives in a temp directory and is separate from the dev
cluster in .pgdata. The schema is created once per session with
``alembic upgrade head``; every test starts from empty tables.
"""

from __future__ import annotations

import configparser
import os
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

import httpx
import pgserver
import pytest
import pytest_asyncio
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from app import quota
from app.config import Settings
from app.main import create_app
from app.models import Account, Site
from app.providers.mock import MockProvider
from app.security import issue_token

ROOT = Path(__file__).resolve().parent.parent
PEPPER = "test-pepper-" + "x" * 40

TABLES = (
    "stripe_events, unmatched_charge_events, disputes, payments, subscriptions, pair_codes, checkout_claims, "
    "usage_log, ledger, reservation_items, reservations, quota_buckets, sites, accounts"
)


@pytest.fixture(scope="session")
def database_url() -> str:
    """Start a throw-away PostgreSQL, migrate it, return its asyncpg URL."""
    workdir = Path(tempfile.mkdtemp(prefix="lingocanvas-pg-"))
    server = pgserver.get_server(workdir / "pgdata", cleanup_mode="stop")
    server.psql("CREATE DATABASE lctest")
    url = server.get_uri("lctest").replace("postgresql://", "postgresql+asyncpg://", 1)
    env = {**os.environ, "DATABASE_URL": url, "TOKEN_PEPPER": PEPPER}
    result = subprocess.run(
        [sys.executable, "-m", "alembic", "upgrade", "head"],
        cwd=ROOT, env=env, capture_output=True, text=True,
    )
    assert result.returncode == 0, result.stderr[-2000:]
    try:
        yield url
    finally:
        server.cleanup()
        shutil.rmtree(workdir, ignore_errors=True)


@pytest.fixture(scope="session")
def settings(database_url: str) -> Settings:
    return Settings(
        database_url=database_url,
        token_pepper=PEPPER,
        provider="mock",
        daily_spend_limit_usd=5,
        _env_file=None,
    )


@pytest_asyncio.fixture(scope="session")
async def engine(database_url: str):
    eng = create_async_engine(database_url)
    yield eng
    await eng.dispose()


@pytest.fixture(scope="session")
def sessionmaker(engine):
    return async_sessionmaker(engine, expire_on_commit=False)


@pytest_asyncio.fixture(autouse=True)
async def clean_db(engine):
    async with engine.begin() as conn:
        await conn.execute(text(f"TRUNCATE {TABLES} RESTART IDENTITY CASCADE"))
    yield


@pytest.fixture
def mock_provider() -> MockProvider:
    return MockProvider()


@pytest_asyncio.fixture
async def app(settings: Settings, mock_provider: MockProvider):
    application = create_app(settings, mock_provider)
    yield application
    await application.state.engine.dispose()


@pytest_asyncio.fixture
async def client(app):
    # raise_app_exceptions=False: unhandled errors come back as the real 500 response.
    transport = httpx.ASGITransport(app=app, raise_app_exceptions=False)
    async with httpx.AsyncClient(transport=transport, base_url="http://test") as c:
        yield c


@pytest.fixture
def make_account(sessionmaker):
    async def _make(plan: str | None = "starter", name: str = "Test account", status: str = "active") -> int:
        async with sessionmaker() as s, s.begin():
            acc = Account(name=name, plan=plan, status=status)
            s.add(acc)
            await s.flush()
            return acc.id

    return _make


@pytest.fixture
def make_site(sessionmaker):
    async def _make(account_id: int, name: str = "example.com") -> tuple[int, str]:
        """Returns (site_id, plaintext token)."""
        token, prefix, digest = issue_token(PEPPER)
        async with sessionmaker() as s, s.begin():
            site = Site(account_id=account_id, name=name, token_prefix=prefix, token_hash=digest)
            s.add(site)
            await s.flush()
            return site.id, token

    return _make


@pytest.fixture
def grant_month(sessionmaker):
    async def _grant(account_id: int, chars: int = 100_000, *, ref: str | None = None) -> int:
        now = datetime.now(timezone.utc)
        async with sessionmaker() as s, s.begin():
            bucket_id, _ = await quota.grant(
                s,
                account_id=account_id,
                kind="monthly",
                chars=chars,
                now=now,
                source_ref=ref,
                valid_from=now - timedelta(minutes=1),
                expires_at=now + timedelta(days=30),
            )
            return bucket_id

    return _grant


@pytest.fixture
def grant_pack(sessionmaker):
    async def _grant(account_id: int, chars: int = 250_000, *, ref: str | None = None) -> int:
        now = datetime.now(timezone.utc)
        async with sessionmaker() as s, s.begin():
            bucket_id, _ = await quota.grant(
                s,
                account_id=account_id,
                kind="pack",
                chars=chars,
                now=now,
                source_ref=ref,
                valid_from=now - timedelta(minutes=1),
                expires_at=now + timedelta(days=365),
            )
            return bucket_id

    return _grant


@pytest.fixture
def q(sessionmaker):
    """Run raw SQL and return rows as dicts (for assertions on the real database)."""

    async def _q(sql: str, **params):
        async with sessionmaker() as s:
            result = await s.execute(text(sql), params)
            return [dict(r._mapping) for r in result]

    return _q


@pytest_asyncio.fixture
async def make_client(database_url: str, tmp_path):
    """Build an app + HTTP client with custom config.ini limits, provider or settings.

    ``limits`` overrides [limits] keys of config.ini, ``pricing`` overrides [pricing].
    Returns ``(client, app)``. Everything is closed after the test.
    """
    created: list[tuple[object, httpx.AsyncClient]] = []

    async def _make(
        *,
        limits: dict | None = None,
        pricing: dict | None = None,
        provider=None,
        daily_spend_limit_usd: float | int = 5,
        deepseek_api_key: str = "",
        billing: dict | None = None,
        gateway=None,
        stripe: dict | None = None,
    ):
        parser = configparser.ConfigParser(inline_comment_prefixes=None, interpolation=None)
        parser.read(ROOT / "config.ini", encoding="utf-8")
        for key, value in (limits or {}).items():
            parser["limits"][key] = str(value)
        for key, value in (pricing or {}).items():
            parser["pricing"][key] = str(value)
        for key, value in (billing or {}).items():
            parser["billing"][key] = str(value)
        path = tmp_path / f"config{len(created)}.ini"
        with open(path, "w", encoding="utf-8") as fh:
            parser.write(fh)
        st = Settings(
            database_url=database_url,
            token_pepper=PEPPER,
            provider="mock",
            daily_spend_limit_usd=daily_spend_limit_usd,
            deepseek_api_key=deepseek_api_key,
            config_ini=path,
            _env_file=None,
            **(stripe or {}),
        )
        application = create_app(st, provider if provider is not None else MockProvider(), gateway)
        transport = httpx.ASGITransport(app=application, raise_app_exceptions=False)
        http = httpx.AsyncClient(transport=transport, base_url="http://test")
        created.append((application, http))
        return http, application

    yield _make
    for application, http in created:
        await http.aclose()
        await application.state.engine.dispose()


def auth(token: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


@pytest_asyncio.fixture
async def bill(make_client, sessionmaker, make_site):
    """App with billing enabled and a counting fake Stripe gateway."""
    from tests.billing_env import BillingEnv
    from tests.billing_fakes import STRIPE_SETTINGS, FakeGateway

    gw = FakeGateway()
    client, application = await make_client(gateway=gw, stripe=STRIPE_SETTINGS)
    return BillingEnv(client, application, gw, sessionmaker, make_site)
