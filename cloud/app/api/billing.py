"""Billing HTTP routes: sign-up/claim, checkout, portal, pairing, Stripe webhook.

Rules:
- Every billing route answers 503 billing_disabled until the Stripe secret key
  and webhook secret are configured.
- Errors never echo client input; log lines carry only ids and exception class names.
- The site token is returned once (claim pickup / pairing) and never stored.
- Lock order is the same everywhere: claim (or pair code) first, then account.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import secrets
import uuid
from datetime import datetime, timedelta, timezone

import stripe
from fastapi import APIRouter, Depends, Request
from fastapi.responses import JSONResponse
from sqlalchemy import func, select, text

from app.api.schemas import (
    CheckoutBody,
    CheckoutOut,
    ClaimBody,
    ClaimConfirmBody,
    PairBody,
    PairCodeOut,
    PairOut,
    PortalOut,
    SignupBody,
    SignupOut,
)
from app.billing.events import new_claim_id
from app.billing.gateway import APP_TAG
from app.errors import ApiError, error_response
from app.models import Account, CheckoutClaim, PairCode, Site, Subscription
from app.security import _bearer, _unauthorized, current_site, hash_token, issue_token
from app.translate_service import SiteIdentity

log = logging.getLogger("lingocanvas.billing.api")

router = APIRouter()

WEBHOOK_PATH = "/v1/stripe/webhook"
SIGNATURE_TOLERANCE_SECONDS = 300
LIVE_FOR_ACCESS = ("active", "trialing")


def _now() -> datetime:
    return datetime.now(timezone.utc)


def require_billing(request: Request) -> None:
    if not request.app.state.billing_enabled:
        raise ApiError(503, "billing_disabled", "Billing is not available.")


def _provider_error() -> ApiError:
    return ApiError(502, "billing_provider_error", "The payment provider is unavailable. Try again later.")


async def _has_live_subscription(s, account_id: int, now: datetime) -> bool:
    row = (
        await s.execute(
            select(Subscription.id)
            .where(
                Subscription.account_id == account_id,
                Subscription.status.in_(LIVE_FOR_ACCESS),
                Subscription.current_period_end > now,
            )
            .limit(1)
        )
    ).first()
    return row is not None


async def _active_site_count(s, account_id: int) -> int:
    return (
        await s.execute(
            select(func.count()).select_from(Site).where(Site.account_id == account_id, Site.revoked_at.is_(None))
        )
    ).scalar_one()


# --- sign-up -----------------------------------------------------------------


@router.post("/v1/billing/signup", response_model=SignupOut, dependencies=[Depends(require_billing)])
async def signup(body: SignupBody, request: Request) -> SignupOut:
    state = request.app.state
    settings = state.settings
    bcfg = settings.cfg.billing
    pepper = settings.token_pepper.get_secret_value()
    now = _now()
    client_ip = request.client.host if request.client else "unknown"
    ip_hash = hash_token("ip:" + client_ip, pepper)
    claim_id = new_claim_id()
    expires_at = now + timedelta(hours=bcfg.claim_ttl_hours)

    limited = False
    async with state.sessionmaker() as s, s.begin():
        # Serialise sign-ups of one address so concurrent requests cannot all slip under the limit.
        await s.execute(select(func.pg_advisory_xact_lock(int.from_bytes(ip_hash[:8], "big", signed=True))))
        recent = (
            await s.execute(
                select(func.count())
                .select_from(CheckoutClaim)
                .where(CheckoutClaim.client_ip_hash == ip_hash, CheckoutClaim.created_at > now - timedelta(hours=1))
            )
        ).scalar_one()
        if recent >= bcfg.signups_per_ip_per_hour:
            limited = True
        else:
            s.add(
                CheckoutClaim(
                    id=claim_id,
                    secret_hash=bytes.fromhex(body.claim_secret_sha256),
                    plan=body.plan,
                    site_name=body.site_name,
                    client_ip_hash=ip_hash,
                    created_at=now,
                    expires_at=expires_at,
                )
            )
    if limited:
        raise ApiError(429, "rate_limited", "Too many sign-ups. Try again later.", headers={"Retry-After": "3600"})

    async def close_claim() -> None:
        async with state.sessionmaker() as s, s.begin():
            claim = await s.get(CheckoutClaim, claim_id, with_for_update=True)
            if claim is not None and claim.status == "open":
                claim.status = "expired"

    metadata = {"lc_app": APP_TAG, "lc_claim_id": claim_id}
    # Every LingoCanvas customer is created here with the lc_app marker: Stripe metadata is not
    # inherited by Checkout/Subscription/Invoice/Charge/Dispute, but all of them reference the customer.
    try:
        customer_id = await state.gateway.create_customer(body.email or None, dict(metadata), f"lc-customer:{claim_id}")
    except Exception as exc:  # network / Stripe error: details stay out of logs and responses
        log.error("signup customer failed claim=%s error=%s", claim_id, type(exc).__name__)
        await close_claim()
        raise _provider_error() from None
    async with state.sessionmaker() as s, s.begin():
        claim = await s.get(CheckoutClaim, claim_id, with_for_update=True)
        if claim is not None:
            claim.stripe_customer_id = customer_id

    params: dict = {
        "mode": "subscription",
        "line_items": [{"price": settings.stripe_price_starter, "quantity": 1}],
        "metadata": dict(metadata),
        "subscription_data": {"metadata": dict(metadata)},
        "client_reference_id": claim_id,
        "success_url": bcfg.success_url.replace("{CLAIM_ID}", claim_id),
        "cancel_url": bcfg.cancel_url,
    }
    params["customer"] = customer_id  # Stripe rejects customer together with customer_email
    if bcfg.consent_terms:
        params["consent_collection"] = {"terms_of_service": "required"}
    if bcfg.automatic_tax:
        params["automatic_tax"] = {"enabled": True}

    try:
        session_id, url = await state.gateway.create_checkout_session(params, claim_id)
    except Exception as exc:  # network / Stripe error: details stay out of logs and responses
        log.error("signup checkout failed claim=%s error=%s", claim_id, type(exc).__name__)
        await close_claim()
        raise _provider_error() from None

    async with state.sessionmaker() as s, s.begin():
        claim = await s.get(CheckoutClaim, claim_id, with_for_update=True)
        if claim is not None:
            claim.stripe_checkout_session_id = session_id
    return SignupOut(claim_id=claim_id, checkout_url=url, expires_at=expires_at)


# --- claim pickup ----------------------------------------------------------------


def _claim_not_found() -> ApiError:
    return ApiError(404, "claim_not_found", "Claim not found.")


@router.post("/v1/billing/claim", dependencies=[Depends(require_billing)])
async def claim(body: ClaimBody, request: Request) -> JSONResponse:
    state = request.app.state
    settings = state.settings
    bcfg = settings.cfg.billing
    now = _now()
    error: ApiError | None = None
    result: dict | None = None
    status = 200

    async with state.sessionmaker() as s, s.begin():
        row = (
            await s.execute(select(CheckoutClaim).where(CheckoutClaim.id == body.claim_id).with_for_update())
        ).scalar_one_or_none()
        if row is None or row.failed_attempts >= bcfg.claim_max_failed_attempts:
            error = _claim_not_found()
        else:
            digest = hashlib.sha256(body.claim_secret.encode("utf-8")).digest()
            if not hmac.compare_digest(digest, bytes(row.secret_hash)):
                row.failed_attempts += 1  # committed: the transaction ends normally
                error = _claim_not_found()
            elif row.status == "confirmed":
                error = ApiError(409, "already_claimed", "This claim was already picked up.")
            elif row.status == "consumed":
                # The response may have been lost: within the recovery window the same secret
                # rotates the token of the SAME site (the old token stops working at once).
                if row.recovery_until is None or now >= row.recovery_until or row.site_id is None:
                    error = ApiError(409, "already_claimed", "This claim was already picked up.")
                else:
                    site = (
                        await s.execute(select(Site).where(Site.id == row.site_id).with_for_update())
                    ).scalar_one_or_none()
                    if site is None:
                        error = ApiError(409, "already_claimed", "This claim was already picked up.")
                    elif site.revoked_at is not None:
                        error = ApiError(409, "site_revoked", "This site was disconnected.")
                    else:
                        account = (
                            await s.execute(select(Account).where(Account.id == site.account_id).with_for_update())
                        ).scalar_one()
                        if account.status != "active" or account.dispute_hold:
                            error = ApiError(403, "account_suspended", "This account is suspended.")
                        else:
                            token, prefix, token_hash = issue_token(settings.token_pepper.get_secret_value())
                            site.token_prefix = prefix
                            site.token_hash = token_hash
                            result = {
                                "token": token, "site_id": site.id, "account_id": account.id, "recovered": True,
                            }
                            log.info("claim token rotated claim=%s site=%s", row.id, site.id)
            elif row.status == "expired" or (row.status == "open" and row.expires_at <= now):
                error = ApiError(410, "claim_expired", "This claim has expired.")
            elif row.status == "open" or row.account_id is None:
                status, result = 202, {"status": "pending"}
            else:
                # Paid: claim is locked, now the account (global order: claim, then account).
                account = (
                    await s.execute(select(Account).where(Account.id == row.account_id).with_for_update())
                ).scalar_one()
                plan = settings.cfg.plans.get(account.plan) if account.plan else None
                if account.status != "active" or account.dispute_hold:
                    error = ApiError(403, "account_suspended", "This account is suspended.")
                elif plan is None:
                    error = ApiError(409, "subscription_required", "An active subscription is required.")
                elif await _active_site_count(s, account.id) >= plan.max_sites:
                    error = ApiError(409, "site_limit_reached", "The plan's site limit is reached.")
                else:
                    token, prefix, token_hash = issue_token(settings.token_pepper.get_secret_value())
                    site = Site(account_id=account.id, name=row.site_name, token_prefix=prefix, token_hash=token_hash)
                    s.add(site)
                    await s.flush()
                    row.status = "consumed"
                    row.site_id = site.id
                    row.consumed_at = now
                    row.recovery_until = now + timedelta(hours=bcfg.claim_recovery_hours)
                    result = {"token": token, "site_id": site.id, "account_id": account.id, "recovered": False}
    if error is not None:
        raise error
    return JSONResponse(result, status_code=status)


@router.post("/v1/billing/claim/confirm", dependencies=[Depends(require_billing)])
async def claim_confirm(body: ClaimConfirmBody, request: Request, site: SiteIdentity = Depends(current_site)) -> JSONResponse:
    """The site confirms it stored the token: the claim can no longer be used to rotate it."""
    state = request.app.state
    digest = hash_token(_bearer(request), state.settings.token_pepper.get_secret_value())
    stale_token = False
    found = False
    async with state.sessionmaker() as s, s.begin():
        row = (
            await s.execute(select(CheckoutClaim).where(CheckoutClaim.id == body.claim_id).with_for_update())
        ).scalar_one_or_none()
        if row is not None and row.site_id == site.site_id and row.status in ("consumed", "confirmed"):
            current = (await s.execute(select(Site.token_hash).where(Site.id == site.site_id))).scalar_one()
            if not hmac.compare_digest(bytes(current), digest):
                stale_token = True  # rotated by a parallel pickup after authentication
            else:
                found = True
                if row.status == "consumed":
                    row.status = "confirmed"
                    row.recovery_until = None
    if stale_token:
        raise _unauthorized()
    if not found:
        raise _claim_not_found()
    return JSONResponse({"status": "confirmed"})


# --- checkout / portal (site auth) ----------------------------------------------------


@router.post("/v1/billing/checkout", response_model=CheckoutOut, dependencies=[Depends(require_billing)])
async def checkout(body: CheckoutBody, request: Request, site: SiteIdentity = Depends(current_site)) -> CheckoutOut:
    state = request.app.state
    settings = state.settings
    bcfg = settings.cfg.billing
    now = _now()

    def finish(params: dict) -> dict:
        if bcfg.consent_terms:
            params["consent_collection"] = {"terms_of_service": "required"}
        if bcfg.automatic_tax:
            params["automatic_tax"] = {"enabled": True}
        return params

    if body.product != "pack_250k":
        return await _starter_checkout(state, settings, site.account_id, finish, now)

    async with state.sessionmaker() as s:
        account = (await s.execute(select(Account).where(Account.id == site.account_id))).scalar_one()
        live = await _has_live_subscription(s, account.id, now)
    customer = account.stripe_customer_id
    if not live:
        raise ApiError(409, "subscription_required", "An active subscription is required to buy a pack.")
    if not customer:
        raise ApiError(409, "no_billing_account", "This account has no billing account yet.")
    price = settings.stripe_price_pack_250k
    if not price:
        raise ApiError(503, "billing_disabled", "Billing is not available.")
    params: dict = {
        "mode": "payment",
        "line_items": [{"price": price, "quantity": 1}],
        "customer": customer,
        # Webhook payloads carry no line items: the price id travels in our own metadata.
        "metadata": {"lc_app": APP_TAG, "lc_account_id": str(account.id), "lc_price_id": price},
        "payment_intent_data": {"metadata": {"lc_app": APP_TAG, "lc_account_id": str(account.id)}},
        "success_url": bcfg.portal_return_url,
        "cancel_url": bcfg.portal_return_url,
    }
    try:
        await _tag_customer(state, customer)
        _, url = await state.gateway.create_checkout_session(finish(params), uuid.uuid4().hex)
    except Exception as exc:
        log.error("checkout failed account=%s error=%s", account.id, type(exc).__name__)
        raise _provider_error() from None
    return CheckoutOut(checkout_url=url)


async def _tag_customer(state, customer_id: str) -> None:
    """Make sure the customer carries lc_app=lingocanvas before anything is charged or opened for it."""
    await state.gateway.ensure_customer_tag(customer_id, f"lc-tag:{customer_id}:{uuid.uuid4().hex}")


# Stripe Checkout Sessions expire after 24 h; reuse ours for a little less.
PENDING_CHECKOUT_TTL = timedelta(hours=23)


async def _starter_checkout(state, settings, account_id: int, finish, now: datetime) -> CheckoutOut:
    """Starter subscription checkout, at most one open session per account.

    Serialised per account (advisory lock held for the whole read-create-store
    sequence, NOT the account row lock: translation requests take that one and
    must not wait for a Stripe round trip). A repeated or concurrent request
    returns the same session URL, so two paid subscriptions cannot be started.
    """
    price = settings.stripe_price_starter
    error: ApiError | None = None
    url: str | None = None
    async with state.sessionmaker() as s, s.begin():
        await s.execute(
            text("SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))"), {"k": f"lc-checkout:{account_id}"}
        )
        account = (await s.execute(select(Account).where(Account.id == account_id))).scalar_one()
        if not account.stripe_customer_id:
            error = ApiError(409, "no_billing_account", "This account has no billing account yet.")
        elif await _has_live_subscription(s, account.id, now):
            # The subscription exists now: the old pending session is of no use any more.
            account.pending_checkout_session_id = None
            account.pending_checkout_url = None
            account.pending_checkout_expires_at = None
            error = ApiError(409, "already_subscribed", "This account already has an active subscription.")
        elif not price:
            error = ApiError(503, "billing_disabled", "Billing is not available.")
        elif (
            account.pending_checkout_url
            and account.pending_checkout_expires_at is not None
            and account.pending_checkout_expires_at > now
        ):
            url = account.pending_checkout_url
        else:
            meta = {"lc_app": APP_TAG, "lc_account_id": str(account.id)}
            params = {
                "mode": "subscription",
                "line_items": [{"price": price, "quantity": 1}],
                "customer": account.stripe_customer_id,
                "metadata": dict(meta),
                "subscription_data": {"metadata": dict(meta)},
                "success_url": settings.cfg.billing.portal_return_url,
                "cancel_url": settings.cfg.billing.portal_return_url,
            }
            try:
                await _tag_customer(state, account.stripe_customer_id)
                session_id, url = await state.gateway.create_checkout_session(finish(params), uuid.uuid4().hex)
            except Exception as exc:
                log.error("checkout failed account=%s error=%s", account.id, type(exc).__name__)
                error = _provider_error()
                url = None
            else:
                account.pending_checkout_session_id = session_id
                account.pending_checkout_url = url
                account.pending_checkout_expires_at = now + PENDING_CHECKOUT_TTL
    if error is not None:
        raise error
    assert url is not None
    return CheckoutOut(checkout_url=url)


@router.post("/v1/billing/portal", response_model=PortalOut, dependencies=[Depends(require_billing)])
async def portal(request: Request, site: SiteIdentity = Depends(current_site)) -> PortalOut:
    state = request.app.state
    if not state.settings.stripe_portal_configuration:
        # Without our own portal configuration Stripe would open the account-wide default
        # (shared with the other product): refuse instead of falling back to it.
        raise ApiError(503, "portal_not_configured", "The billing portal is not available.")
    async with state.sessionmaker() as s:
        customer = (
            await s.execute(select(Account.stripe_customer_id).where(Account.id == site.account_id))
        ).scalar_one()
    if not customer:
        raise ApiError(409, "no_billing_account", "This account has no billing account yet.")
    try:
        await _tag_customer(state, customer)
        url = await state.gateway.create_portal_session(customer, state.settings.cfg.billing.portal_return_url)
    except Exception as exc:
        log.error("portal failed account=%s error=%s", site.account_id, type(exc).__name__)
        raise _provider_error() from None
    return PortalOut(url=url)


# --- pairing of more sites --------------------------------------------------------------


@router.post("/v1/sites/pair-code", response_model=PairCodeOut)
async def pair_code(request: Request, site: SiteIdentity = Depends(current_site)) -> PairCodeOut:
    state = request.app.state
    settings = state.settings
    bcfg = settings.cfg.billing
    now = _now()
    code = secrets.token_urlsafe(12)
    expires_at = now + timedelta(minutes=bcfg.pair_code_ttl_minutes)
    too_many = False
    async with state.sessionmaker() as s, s.begin():
        # The account row lock serialises concurrent requests, so the cap cannot be overshot.
        await s.execute(select(Account.id).where(Account.id == site.account_id).with_for_update())
        active = (
            await s.execute(
                select(func.count())
                .select_from(PairCode)
                .where(PairCode.account_id == site.account_id, PairCode.used_at.is_(None), PairCode.expires_at > now)
            )
        ).scalar_one()
        if active >= bcfg.max_active_pair_codes:
            too_many = True
        else:
            s.add(
                PairCode(
                    account_id=site.account_id,
                    code_hash=hash_token(code, settings.token_pepper.get_secret_value()),
                    expires_at=expires_at,
                    created_at=now,
                )
            )
    if too_many:
        raise ApiError(429, "too_many_pair_codes", "Too many unused pairing codes.", headers={"Retry-After": "60"})
    return PairCodeOut(code=code, expires_at=expires_at)


@router.post("/v1/sites/pair", response_model=PairOut)
async def pair(body: PairBody, request: Request) -> PairOut:
    state = request.app.state
    settings = state.settings
    now = _now()
    pepper = settings.token_pepper.get_secret_value()
    digest = hash_token(body.code, pepper)
    error: ApiError | None = None
    result: PairOut | None = None
    async with state.sessionmaker() as s, s.begin():
        row = (
            await s.execute(select(PairCode).where(PairCode.code_hash == digest).with_for_update())
        ).scalar_one_or_none()
        if row is None or row.used_at is not None or row.expires_at <= now:
            error = ApiError(404, "pair_code_invalid", "The pairing code is invalid or expired.")
        else:
            account = (
                await s.execute(select(Account).where(Account.id == row.account_id).with_for_update())
            ).scalar_one()
            plan = settings.cfg.plans.get(account.plan) if account.plan else None
            if account.status != "active" or account.dispute_hold:
                error = ApiError(403, "account_suspended", "This account is suspended.")
            elif plan is None or not await _has_live_subscription(s, account.id, now):
                error = ApiError(409, "subscription_required", "An active subscription is required.")
            elif await _active_site_count(s, account.id) >= plan.max_sites:
                error = ApiError(409, "site_limit_reached", "The plan's site limit is reached.")
            else:
                token, prefix, token_hash = issue_token(pepper)
                new_site = Site(account_id=account.id, name=body.site_name, token_prefix=prefix, token_hash=token_hash)
                s.add(new_site)
                await s.flush()
                row.used_at = now
                result = PairOut(token=token, site_id=new_site.id)
    if error is not None:
        raise error
    assert result is not None
    return result


# --- Stripe webhook ------------------------------------------------------------------------


@router.post(WEBHOOK_PATH, dependencies=[Depends(require_billing)])
async def stripe_webhook(request: Request) -> JSONResponse:
    state = request.app.state
    settings = state.settings
    payload = await request.body()
    if len(payload) > settings.cfg.billing.webhook_max_body_bytes:
        return error_response(413, "payload_too_large", "The request body is too large.")

    try:
        stripe.Webhook.construct_event(
            payload,
            request.headers.get("stripe-signature", ""),
            settings.stripe_webhook_secret.get_secret_value(),
            tolerance=SIGNATURE_TOLERANCE_SECONDS,
        )
        event = json.loads(payload)
    except (ValueError, stripe.SignatureVerificationError):
        return error_response(400, "invalid_signature", "Invalid signature.")
    if not isinstance(event, dict) or not isinstance(event.get("data"), dict) or not isinstance(event.get("id"), str):
        return error_response(400, "invalid_signature", "Invalid signature.")

    if bool(event.get("livemode")) != settings.stripe_livemode:
        return JSONResponse({"status": "ignored"})

    try:
        result = await state.processor.process(event, _now())
    except Exception as exc:
        # Only ids and the exception class: payloads hold customer data.
        log.error(
            "stripe webhook failed type=%s event=%s error=%s",
            str(event.get("type"))[:100], event["id"][:100], type(exc).__name__,
        )
        return error_response(500, "internal_error", "Internal error.")
    return JSONResponse({"status": result})
