"""HTTP routes: /v1/translate, /v1/account, /health."""

from __future__ import annotations

from datetime import datetime, timezone

from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse
from sqlalchemy import func, select, text

from app import quota
from app.api.schemas import (
    AccountInfo,
    AccountOut,
    QuotaOut,
    SitesInfo,
    SubscriptionOut,
    TranslateBody,
    TranslateOut,
)
from app.errors import ApiError
from app.models import Account, Reservation, Site, Subscription
from app.security import current_site
from app.translate_service import SiteIdentity, TranslateRequest, count_chars

router = APIRouter()


def _quota_out(q: quota.QuotaSummary) -> QuotaOut:
    return QuotaOut(
        monthly_remaining=q.monthly_remaining,
        packs_remaining=q.packs_remaining,
        packs_usable=q.packs_usable,
        total_remaining=q.total_remaining,
    )


def _too_large(message: str) -> ApiError:
    return ApiError(413, "payload_too_large", message)


@router.post("/v1/translate", response_model=TranslateOut)
async def translate(body: TranslateBody, request: Request, site: SiteIdentity = Depends(current_site)) -> TranslateOut:
    limits = request.app.state.settings.cfg.limits

    if len(body.items) > limits.max_items:
        raise _too_large("Too many items in one request.")
    total = 0
    for text_value in body.items.values():
        n = count_chars(text_value)
        if n > limits.max_chars_per_item:
            raise _too_large("One of the texts is too long.")
        total += n
    if total > limits.max_chars_per_request:
        raise _too_large("The request has too many characters.")
    if len(body.glossary) > limits.max_glossary_entries:
        raise ApiError(422, "validation_error", "Too many glossary entries.")
    # The glossary is sent to the paid provider with every call: bound its size
    # absolutely and relative to the texts that actually need translating.
    glossary_chars = sum(len(k) + len(v) for k, v in body.glossary.items())
    if glossary_chars > limits.glossary_max_chars or glossary_chars > 2 * total + 200:
        raise _too_large("The glossary is too large.")

    result = await request.app.state.service.translate(
        site,
        TranslateRequest(
            request_id=body.request_id,
            source_locale=body.source_locale,
            target_locale=body.target_locale,
            target_label=body.target_label or body.target_locale,
            items=body.items,
            glossary=body.glossary,
        ),
    )
    return TranslateOut(
        request_id=result.request_id,
        translations=result.translations,
        missing=result.missing,
        billed_chars=result.billed_chars,
        replay=result.replay,
        quota=_quota_out(result.quota),
    )


def _month_bounds(now: datetime) -> tuple[datetime, datetime]:
    start = now.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
    end = start.replace(year=start.year + 1, month=1) if start.month == 12 else start.replace(month=start.month + 1)
    return start, end


@router.get("/v1/account", response_model=AccountOut)
async def account(request: Request, site: SiteIdentity = Depends(current_site)) -> AccountOut:
    state = request.app.state
    now = datetime.now(timezone.utc)
    month_start, month_end = _month_bounds(now)
    async with state.sessionmaker() as s:
        acc = (await s.execute(select(Account).where(Account.id == site.account_id))).scalar_one()
        used = (
            await s.execute(
                select(func.count()).select_from(Site).where(Site.account_id == acc.id, Site.revoked_at.is_(None))
            )
        ).scalar_one()
        billed = (
            await s.execute(
                select(func.coalesce(func.sum(Reservation.billed_chars), 0)).where(
                    Reservation.account_id == acc.id,
                    Reservation.finished_at >= month_start,
                    Reservation.finished_at < month_end,
                )
            )
        ).scalar_one()
        summary = await quota.summary(s, acc.id, now)
        sub = (
            await s.execute(
                select(Subscription)
                .where(Subscription.account_id == acc.id)
                .order_by(Subscription.updated_at.desc(), Subscription.id.desc())
                .limit(1)
            )
        ).scalar_one_or_none()
    plan = state.settings.cfg.plans.get(acc.plan) if acc.plan else None
    return AccountOut(
        account=AccountInfo(id=acc.id, name=acc.name, plan=acc.plan, status=acc.status),
        sites=SitesInfo(used=used, max=plan.max_sites if plan else None),
        quota=_quota_out(summary),
        billed_chars_this_month=int(billed),
        subscription=(
            SubscriptionOut(
                status=sub.status,
                current_period_end=sub.current_period_end,
                cancel_at_period_end=sub.cancel_at_period_end,
            )
            if sub is not None
            else None
        ),
    )


@router.get("/health")
async def health(request: Request) -> JSONResponse:
    try:
        async with request.app.state.sessionmaker() as s:
            await s.execute(text("SELECT 1"))
    except Exception:  # any DB failure means "not healthy"; details stay in logs
        return JSONResponse({"status": "error", "db": "down"}, status_code=503)
    return JSONResponse({"status": "ok", "db": "ok"})
