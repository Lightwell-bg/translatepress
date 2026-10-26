"""Site tokens: issuing, hashing and the FastAPI authentication dependency."""

from __future__ import annotations

import hashlib
import hmac
import secrets
from datetime import datetime, timedelta, timezone

from fastapi import Request
from sqlalchemy import or_, select, update
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker

from app.errors import ApiError
from app.models import Account, Site
from app.translate_service import SiteIdentity

TOKEN_PREFIX = "lcc_"
_LAST_USED_GRANULARITY = timedelta(minutes=1)


def hash_token(token: str, pepper: str) -> bytes:
    return hmac.new(pepper.encode("utf-8"), token.encode("utf-8"), hashlib.sha256).digest()


def issue_token(pepper: str) -> tuple[str, str, bytes]:
    """Returns (token, display prefix, hash). Only the prefix and hash are stored."""
    token = TOKEN_PREFIX + secrets.token_urlsafe(32)
    return token, token[:12], hash_token(token, pepper)


def _unauthorized() -> ApiError:
    return ApiError(401, "invalid_token", "Missing or invalid token.", headers={"WWW-Authenticate": "Bearer"})


def _bearer(request: Request) -> str:
    header = request.headers.get("authorization", "")
    scheme, _, value = header.partition(" ")
    value = value.strip()
    if scheme.lower() != "bearer" or not value or len(value) > 200:
        raise _unauthorized()
    return value


async def current_site(request: Request) -> SiteIdentity:
    """Authenticate by ``Authorization: Bearer lcc_...`` only."""
    token = _bearer(request)
    state = request.app.state
    sessionmaker: async_sessionmaker[AsyncSession] = state.sessionmaker
    digest = hash_token(token, state.settings.token_pepper.get_secret_value())
    now = datetime.now(timezone.utc)

    async with sessionmaker() as s, s.begin():
        row = (await s.execute(select_site(digest))).first()
        if row is None or row.revoked_at is not None:
            raise _unauthorized()
        if row.status != "active" or row.dispute_hold:
            raise ApiError(403, "account_suspended", "This account is suspended.")
        # At most one write per minute per site.
        await s.execute(
            update(Site)
            .where(
                Site.id == row.site_id,
                or_(Site.last_used_at.is_(None), Site.last_used_at < now - _LAST_USED_GRANULARITY),
            )
            .values(last_used_at=now)
        )
    return SiteIdentity(site_id=row.site_id, account_id=row.account_id)


def select_site(digest: bytes):
    return (
        select(
            Site.id.label("site_id"),
            Site.account_id,
            Site.revoked_at,
            Account.status,
            Account.dispute_hold,
        )
        .join(Account, Account.id == Site.account_id)
        .where(Site.token_hash == digest)
    )
