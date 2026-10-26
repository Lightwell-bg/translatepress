"""Atomic quota accounting: reserve -> commit | release.

Every function here must run inside a transaction opened by the caller
(``async with session.begin():``) and starts by locking the account row.
That single lock serialises all quota changes of one account, so:

- two parallel requests cannot both spend the last characters;
- a retried request id is checked and claimed under the same lock;
- buckets are always locked after the account, never before (no deadlocks).

The database CHECK constraints (see models.py) are the last line of defence:
even a bug here cannot push a bucket below zero or above its grant.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta

from sqlalchemy import and_, case, func, or_, select
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Account, LedgerEntry, QuotaBucket, Reservation, ReservationItem


# --- errors ---------------------------------------------------------------


class QuotaError(Exception):
    """Base class; ``code`` is the stable API error code."""

    code = "quota_error"


class AccountSuspended(QuotaError):
    code = "account_suspended"


class InsufficientQuota(QuotaError):
    code = "insufficient_quota"

    def __init__(self, needed: int, available: int) -> None:
        super().__init__(f"needed {needed}, available {available}")
        self.needed = needed
        self.available = available


class RateLimited(QuotaError):
    code = "rate_limited"

    def __init__(self, retry_after: int) -> None:
        super().__init__(f"retry after {retry_after}s")
        self.retry_after = retry_after


class TooManyInFlight(RateLimited):
    code = "too_many_in_flight"


class RequestConflict(QuotaError):
    """Same request_id, different request body."""

    code = "request_id_conflict"


class RequestInProgress(QuotaError):
    code = "request_in_progress"


class ReplayLimit(QuotaError):
    code = "replay_limit"


class RetryLimit(QuotaError):
    code = "retry_limit"


class GrantConflict(ValueError):
    """source_ref already used for a different account or different grant terms."""


# --- data -----------------------------------------------------------------


@dataclass(frozen=True)
class Limits:
    reservation_ttl: timedelta
    per_site_requests_per_minute: int
    max_in_flight_per_account: int
    max_replays: int
    max_attempts: int


@dataclass(frozen=True)
class ReserveOutcome:
    # new: fresh charge reserved; retry: a failed request reserved again;
    # replay: already committed earlier, deliver again without charging.
    kind: str
    reservation_id: int
    chars: int
    # Fencing token for settle(); the attempt number this caller owns.
    attempt: int


@dataclass(frozen=True)
class SettleOutcome:
    billed: int
    released: int
    # False when the reservation was no longer pending (e.g. the sweeper
    # released it after a very slow provider call): nothing was charged.
    settled: bool


@dataclass(frozen=True)
class QuotaSummary:
    monthly_remaining: int
    packs_remaining: int
    packs_usable: bool

    @property
    def total_remaining(self) -> int:
        return self.monthly_remaining + (self.packs_remaining if self.packs_usable else 0)


# --- helpers --------------------------------------------------------------


async def _lock_account(s: AsyncSession, account_id: int) -> Account:
    account = (
        await s.execute(select(Account).where(Account.id == account_id).with_for_update())
    ).scalar_one()
    return account


def _live_bucket_filter(account_id: int, now: datetime):
    return and_(
        QuotaBucket.account_id == account_id,
        QuotaBucket.revoked_at.is_(None),
        QuotaBucket.valid_from <= now,
        or_(QuotaBucket.expires_at.is_(None), QuotaBucket.expires_at > now),
    )


_SPEND_ORDER = (
    case((QuotaBucket.kind == "monthly", 0), else_=1),
    QuotaBucket.expires_at.asc().nulls_last(),
    QuotaBucket.id.asc(),
)


async def _spendable_buckets(s: AsyncSession, account_id: int, now: datetime) -> list[QuotaBucket]:
    """Buckets in spend order: monthly first, then packs, earliest expiry first.

    Packs are spendable only while a paid period is running (a live monthly
    bucket exists): unused packs survive a lapse but wait for a renewal.
    """
    buckets = list(
        (
            await s.execute(
                select(QuotaBucket)
                .where(_live_bucket_filter(account_id, now))
                .order_by(*_SPEND_ORDER)
                .with_for_update()
            )
        ).scalars()
    )
    has_period = any(b.kind == "monthly" for b in buckets)
    return [b for b in buckets if b.kind == "monthly" or has_period]


def _available(b: QuotaBucket) -> int:
    # revoke_pending is already promised back (refund while reserved): not spendable.
    return b.granted - b.used - b.reserved - b.revoke_pending


def _ledger(s: AsyncSession, account_id: int, kind: str, chars: int, *, bucket_id=None, reservation_id=None, note=None, now=None) -> None:
    s.add(
        LedgerEntry(
            account_id=account_id,
            bucket_id=bucket_id,
            reservation_id=reservation_id,
            kind=kind,
            chars=chars,
            note=note,
            created_at=now,
        )
    )


async def _allocate(
    s: AsyncSession, account_id: int, reservation_id: int, chars: int, now: datetime
) -> None:
    """Move ``chars`` from available to reserved across buckets, or raise."""
    buckets = await _spendable_buckets(s, account_id, now)
    available = sum(max(0, _available(b)) for b in buckets)
    if chars > available:
        raise InsufficientQuota(chars, available)

    left = chars
    for b in buckets:
        if left == 0:
            break
        take = min(left, _available(b))
        if take <= 0:
            continue
        b.reserved += take
        left -= take
        s.add(ReservationItem(reservation_id=reservation_id, bucket_id=b.id, chars=take))
        _ledger(s, account_id, "reserve", take, bucket_id=b.id, reservation_id=reservation_id, now=now)
    await s.flush()


# --- public API -----------------------------------------------------------


async def reserve(
    s: AsyncSession,
    *,
    account_id: int,
    site_id: int,
    request_id: str,
    request_hash: bytes,
    chars: int,
    now: datetime,
    limits: Limits,
) -> ReserveOutcome:
    """Claim quota for one request, idempotently.

    - unknown request id: checks limits, reserves ``chars`` (InsufficientQuota
      before any provider call, so an unpaid request never reaches DeepSeek);
    - committed earlier: a replay, delivered again without a charge (capped);
    - pending: another call with this id is still running -> RequestInProgress;
    - released (failed earlier): reserved again as a retry (capped).
    """
    account = await _lock_account(s, account_id)
    if account.status != "active" or account.dispute_hold:
        raise AccountSuspended("account is suspended")

    existing = (
        await s.execute(
            select(Reservation)
            .where(Reservation.site_id == site_id, Reservation.request_id == request_id)
            .with_for_update()
        )
    ).scalar_one_or_none()

    if existing is not None:
        if existing.request_hash != request_hash:
            raise RequestConflict("request_id was already used for a different request")
        if existing.status == "pending":
            raise RequestInProgress("this request is still being processed")
        if existing.status == "committed":
            if existing.replays >= limits.max_replays:
                raise ReplayLimit("this request was already delivered")
            # A replay is a real provider call: same rate and in-flight limits.
            await _check_rates(s, account_id, site_id, now, limits)
            existing.replays += 1
            existing.replay_started_at = now
            existing.last_attempt_at = now
            await s.flush()
            return ReserveOutcome("replay", existing.id, 0, existing.attempts)
        # released: a failed attempt may be retried with the same id.
        if existing.attempts >= limits.max_attempts:
            raise RetryLimit("too many attempts for this request id")
        await _check_rates(s, account_id, site_id, now, limits)
        await _allocate(s, account_id, existing.id, chars, now)
        existing.status = "pending"
        existing.attempts += 1
        existing.billed_chars = 0
        existing.finished_at = None
        existing.last_attempt_at = now
        existing.expires_at = now + limits.reservation_ttl
        await s.flush()
        return ReserveOutcome("retry", existing.id, chars, existing.attempts)

    await _check_rates(s, account_id, site_id, now, limits)

    reservation = Reservation(
        account_id=account_id,
        site_id=site_id,
        request_id=request_id,
        request_hash=request_hash,
        chars=chars,
        status="pending",
        attempts=1,
        created_at=now,
        last_attempt_at=now,
        expires_at=now + limits.reservation_ttl,
    )
    s.add(reservation)
    await s.flush()
    await _allocate(s, account_id, reservation.id, chars, now)
    return ReserveOutcome("new", reservation.id, chars, 1)


async def finish_replay(s: AsyncSession, *, account_id: int, reservation_id: int, attempt: int) -> None:
    """Free the in-flight slot a replay occupied (charges nothing)."""
    await _lock_account(s, account_id)
    reservation = (
        await s.execute(select(Reservation).where(Reservation.id == reservation_id).with_for_update())
    ).scalar_one_or_none()
    if reservation is not None and reservation.attempts == attempt:
        reservation.replay_started_at = None
        await s.flush()


async def _check_rates(
    s: AsyncSession, account_id: int, site_id: int, now: datetime, limits: Limits
) -> None:
    # Runs under the account lock, so the counts cannot race with each other.
    # A replay that crashed without finish_replay() frees its slot after the TTL.
    in_flight = (
        await s.execute(
            select(func.count())
            .select_from(Reservation)
            .where(
                Reservation.account_id == account_id,
                or_(
                    Reservation.status == "pending",
                    Reservation.replay_started_at > now - limits.reservation_ttl,
                ),
            )
        )
    ).scalar_one()
    if in_flight >= limits.max_in_flight_per_account:
        raise TooManyInFlight(5)

    recent = (
        await s.execute(
            select(func.count())
            .select_from(Reservation)
            .where(Reservation.site_id == site_id, Reservation.last_attempt_at > now - timedelta(minutes=1))
        )
    ).scalar_one()
    if recent >= limits.per_site_requests_per_minute:
        raise RateLimited(60)


async def settle(
    s: AsyncSession,
    *,
    account_id: int,
    reservation_id: int,
    attempt: int,
    billed_chars: int,
    now: datetime,
) -> SettleOutcome:
    """Charge ``billed_chars`` of a pending reservation and return the rest.

    ``billed_chars == 0`` releases everything (provider failure). Characters
    are charged in the original spend order, so monthly quota goes first.
    ``attempt`` must match: a stale caller whose attempt was swept and then
    retried by someone else settles nothing.
    """
    await _lock_account(s, account_id)
    reservation = (
        await s.execute(select(Reservation).where(Reservation.id == reservation_id).with_for_update())
    ).scalar_one()
    if reservation.status != "pending" or reservation.attempts != attempt:
        return SettleOutcome(0, 0, False)

    billed_chars = max(0, min(billed_chars, reservation.chars))

    rows = (
        await s.execute(
            select(ReservationItem, QuotaBucket)
            .join(QuotaBucket, QuotaBucket.id == ReservationItem.bucket_id)
            .where(ReservationItem.reservation_id == reservation_id)
            .order_by(*_SPEND_ORDER)
            .with_for_update(of=QuotaBucket)
        )
    ).all()

    left = billed_chars
    released = 0
    for item, bucket in rows:
        take = min(item.chars, left)
        give_back = item.chars - take
        bucket.reserved -= item.chars
        bucket.used += take
        left -= take
        released += give_back
        if take:
            _ledger(s, account_id, "commit", take, bucket_id=bucket.id, reservation_id=reservation_id, now=now)
        if give_back:
            _ledger(s, account_id, "release", give_back, bucket_id=bucket.id, reservation_id=reservation_id, now=now)
            # A refund that arrived while these characters were reserved is
            # applied now that they come back, instead of becoming spendable.
            apply = min(bucket.revoke_pending, give_back)
            if apply:
                bucket.granted -= apply
                bucket.revoke_pending -= apply
                _ledger(s, account_id, "revoke", apply, bucket_id=bucket.id, note="deferred revoke", now=now)
        # What is still reserved can no longer cover a larger pending revoke
        # (the rest was legitimately spent before the refund).
        bucket.revoke_pending = min(bucket.revoke_pending, bucket.reserved)
        # Items describe the current attempt only; a later retry allocates afresh.
        await s.delete(item)

    reservation.status = "committed" if billed_chars > 0 else "released"
    reservation.billed_chars = billed_chars
    reservation.finished_at = now
    await s.flush()
    return SettleOutcome(billed_chars, released, True)


async def expired_pending_ids(s: AsyncSession, now: datetime, limit: int = 100) -> list[tuple[int, int]]:
    """(account_id, reservation_id) of stuck reservations, read without locks."""
    rows = await s.execute(
        select(Reservation.account_id, Reservation.id)
        .where(Reservation.status == "pending", Reservation.expires_at < now)
        .order_by(Reservation.expires_at)
        .limit(limit)
    )
    return [(a, r) for a, r in rows.all()]


async def release_if_expired(s: AsyncSession, *, account_id: int, reservation_id: int, now: datetime) -> bool:
    """Sweeper step: release one stuck reservation (account lock taken first)."""
    await _lock_account(s, account_id)
    reservation = (
        await s.execute(select(Reservation).where(Reservation.id == reservation_id).with_for_update())
    ).scalar_one_or_none()
    if reservation is None or reservation.status != "pending" or reservation.expires_at >= now:
        return False
    outcome = await settle(
        s,
        account_id=account_id,
        reservation_id=reservation_id,
        attempt=reservation.attempts,
        billed_chars=0,
        now=now,
    )
    return outcome.settled


async def grant(
    s: AsyncSession,
    *,
    account_id: int,
    kind: str,
    chars: int,
    now: datetime,
    source: str = "manual",
    source_ref: str | None = None,
    valid_from: datetime | None = None,
    expires_at: datetime | None = None,
) -> tuple[int, bool]:
    """Create a bucket; idempotent by ``source_ref``. Returns (bucket_id, created).

    The Stripe stage calls this with the invoice / payment id as source_ref,
    so a webhook delivered twice can never grant twice.
    """
    if kind not in ("monthly", "pack") or chars <= 0:
        raise ValueError("bad grant")
    await _lock_account(s, account_id)
    stmt = (
        pg_insert(QuotaBucket)
        .values(
            account_id=account_id,
            kind=kind,
            source=source,
            source_ref=source_ref,
            granted=chars,
            valid_from=valid_from or now,
            expires_at=expires_at,
            created_at=now,
        )
        .on_conflict_do_nothing(index_elements=[QuotaBucket.source_ref])
        .returning(QuotaBucket.id)
    )
    bucket_id = (await s.execute(stmt)).scalar_one_or_none()
    if bucket_id is None:
        existing = (
            await s.execute(select(QuotaBucket).where(QuotaBucket.source_ref == source_ref))
        ).scalar_one()
        # The original grant amount is in the ledger (granted shrinks on refunds).
        original = (
            await s.execute(
                select(LedgerEntry.chars)
                .where(LedgerEntry.bucket_id == existing.id, LedgerEntry.kind == "grant")
                .order_by(LedgerEntry.id)
                .limit(1)
            )
        ).scalar_one_or_none()
        if (
            existing.account_id != account_id
            or existing.kind != kind
            or existing.source != source
            or original != chars
            or existing.valid_from != (valid_from or existing.valid_from)
            or existing.expires_at != expires_at
        ):
            raise GrantConflict(f"source_ref {source_ref!r} is already used for a different grant")
        return existing.id, False
    _ledger(s, account_id, "grant", chars, bucket_id=bucket_id, note=source_ref, now=now)
    await s.flush()
    return bucket_id, True


async def revoke_unused(
    s: AsyncSession, *, account_id: int, bucket_id: int, chars: int | None, now: datetime, note: str | None = None
) -> int:
    """Take back unused characters (refunds). ``None`` = everything unused, and close the bucket.

    Never goes below what is already used. Characters that are free now are
    revoked immediately; the part sitting in reservations becomes
    ``revoke_pending`` and is revoked by settle() when those reservations are
    released (if they are charged instead, they were legitimately spent).
    Returns what was revoked immediately; the obligation is now registered in
    full either way.
    """
    await _lock_account(s, account_id)
    bucket = (
        await s.execute(
            select(QuotaBucket)
            .where(QuotaBucket.id == bucket_id, QuotaBucket.account_id == account_id)
            .with_for_update()
        )
    ).scalar_one()
    free = max(0, _available(bucket))
    if chars is None:
        # Closing the bucket makes reserved characters unspendable after release anyway.
        take = free
        bucket.revoked_at = now
    else:
        want = max(0, chars)
        take = min(want, free)
        bucket.revoke_pending = min(bucket.reserved, bucket.revoke_pending + (want - take))
    bucket.granted -= take
    if take:
        _ledger(s, account_id, "revoke", take, bucket_id=bucket.id, note=note, now=now)
    await s.flush()
    return take


async def summary(s: AsyncSession, account_id: int, now: datetime) -> QuotaSummary:
    """Remaining characters, read without locks (display only)."""
    buckets = list((await s.execute(select(QuotaBucket).where(_live_bucket_filter(account_id, now)))).scalars())
    monthly = sum(max(0, _available(b)) for b in buckets if b.kind == "monthly")
    packs = sum(max(0, _available(b)) for b in buckets if b.kind == "pack")
    has_period = any(b.kind == "monthly" for b in buckets)
    return QuotaSummary(monthly, packs, has_period)
