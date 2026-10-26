"""Database schema for LingoCanvas Cloud.

Money-like invariants live in the database, not only in code:
- a quota bucket can never go negative or be over-spent
  (CHECK used + reserved <= granted, all >= 0);
- a client request id is unique per site, so a retried request can never
  create a second charge;
- every quota mutation also writes an append-only ledger row.

All quota mutations take the owning account row lock first
(``SELECT ... FOR UPDATE`` on ``accounts``), then touch buckets. One lock
order everywhere means no deadlocks and no lost updates.
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    BigInteger,
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    LargeBinary,
    String,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


class Base(DeclarativeBase):
    pass


class Account(Base):
    __tablename__ = "accounts"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    name: Mapped[str] = mapped_column(String(200))
    email: Mapped[str | None] = mapped_column(String(320), nullable=True)
    # active | suspended. Suspended accounts cannot translate (disputes, abuse).
    status: Mapped[str] = mapped_column(String(20), server_default="active")
    # starter | pro | business | NULL (no subscription). Limits come from config.ini.
    plan: Mapped[str | None] = mapped_column(String(20), nullable=True)
    # Filled by the Stripe stage; unique so one customer maps to one account.
    stripe_customer_id: Mapped[str | None] = mapped_column(String(64), unique=True, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (CheckConstraint("status IN ('active','suspended')", name="ck_accounts_status"),)


class Site(Base):
    """A connected WordPress site. Its bearer token is stored only as a keyed hash."""

    __tablename__ = "sites"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    name: Mapped[str] = mapped_column(String(255))
    # First characters of the token, shown in admin lists to tell tokens apart.
    token_prefix: Mapped[str] = mapped_column(String(16))
    # HMAC-SHA256(TOKEN_PEPPER, token). The token itself is never stored.
    token_hash: Mapped[bytes] = mapped_column(LargeBinary(32), unique=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    last_used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)


class QuotaBucket(Base):
    """A portion of characters: one subscription period, one pack, or a manual grant."""

    __tablename__ = "quota_buckets"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    # monthly: spent first, never carried over; pack: spent after monthly, survives renewals.
    kind: Mapped[str] = mapped_column(String(10))
    # manual | stripe_invoice | stripe_payment
    source: Mapped[str] = mapped_column(String(20), server_default="manual")
    # Idempotency of grants: one bucket per Stripe invoice / payment / manual key.
    source_ref: Mapped[str | None] = mapped_column(String(128), unique=True, nullable=True)
    granted: Mapped[int] = mapped_column(BigInteger)
    used: Mapped[int] = mapped_column(BigInteger, server_default="0")
    reserved: Mapped[int] = mapped_column(BigInteger, server_default="0")
    valid_from: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        CheckConstraint("kind IN ('monthly','pack')", name="ck_buckets_kind"),
        CheckConstraint("granted >= 0 AND used >= 0 AND reserved >= 0", name="ck_buckets_non_negative"),
        CheckConstraint("used + reserved <= granted", name="ck_buckets_not_overspent"),
    )


class Reservation(Base):
    """One client translation request (idempotency key = site + request_id)."""

    __tablename__ = "reservations"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    site_id: Mapped[int] = mapped_column(ForeignKey("sites.id", ondelete="RESTRICT"))
    request_id: Mapped[str] = mapped_column(String(64))
    # SHA-256 of the canonical request; the same request_id with another body is a conflict.
    request_hash: Mapped[bytes] = mapped_column(LargeBinary(32))
    chars: Mapped[int] = mapped_column(BigInteger)
    billed_chars: Mapped[int] = mapped_column(BigInteger, server_default="0")
    # pending -> committed | released. released may go back to pending on a retry.
    status: Mapped[str] = mapped_column(String(10))
    # Fencing token: settle() must name the attempt it belongs to, so a stale
    # attempt (swept, then retried) can never settle the newer one.
    attempts: Mapped[int] = mapped_column(Integer, server_default="1")
    replays: Mapped[int] = mapped_column(Integer, server_default="0")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    # Every admission (new, retry, replay) counts against the per-site rate limit.
    last_attempt_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    # Set while a free replay is running: it occupies an in-flight slot too.
    replay_started_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    __table_args__ = (
        UniqueConstraint("site_id", "request_id", name="uq_reservations_site_request"),
        CheckConstraint("status IN ('pending','committed','released')", name="ck_reservations_status"),
        CheckConstraint("chars >= 0 AND billed_chars >= 0 AND billed_chars <= chars", name="ck_reservations_chars"),
        Index("ix_reservations_pending_expiry", "expires_at", postgresql_where=text("status = 'pending'")),
        Index("ix_reservations_site_attempt", "site_id", "last_attempt_at"),
    )


class ReservationItem(Base):
    """How many characters of a reservation sit in which bucket."""

    __tablename__ = "reservation_items"

    reservation_id: Mapped[int] = mapped_column(ForeignKey("reservations.id", ondelete="CASCADE"), primary_key=True)
    bucket_id: Mapped[int] = mapped_column(ForeignKey("quota_buckets.id", ondelete="RESTRICT"), primary_key=True)
    chars: Mapped[int] = mapped_column(BigInteger)

    __table_args__ = (CheckConstraint("chars > 0", name="ck_reservation_items_positive"),)


class LedgerEntry(Base):
    """Append-only journal of every quota movement."""

    __tablename__ = "ledger"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    bucket_id: Mapped[int | None] = mapped_column(ForeignKey("quota_buckets.id", ondelete="RESTRICT"), nullable=True)
    reservation_id: Mapped[int | None] = mapped_column(
        ForeignKey("reservations.id", ondelete="SET NULL"), nullable=True
    )
    # grant | reserve | commit | release | revoke
    kind: Mapped[str] = mapped_column(String(10))
    chars: Mapped[int] = mapped_column(BigInteger)
    note: Mapped[str | None] = mapped_column(String(200), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class UsageLog(Base):
    """One provider call: sizes, tokens and cost. Never any client text."""

    __tablename__ = "usage_log"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    site_id: Mapped[int] = mapped_column(ForeignKey("sites.id", ondelete="RESTRICT"))
    reservation_id: Mapped[int | None] = mapped_column(
        ForeignKey("reservations.id", ondelete="SET NULL"), nullable=True
    )
    source_locale: Mapped[str] = mapped_column(String(16))
    target_locale: Mapped[str] = mapped_column(String(16))
    items: Mapped[int] = mapped_column(Integer)
    chars: Mapped[int] = mapped_column(BigInteger)
    # Characters the provider returned in this call. NOT what the customer paid:
    # replays and follow-ups are free; billing lives in reservations/ledger.
    returned_chars: Mapped[int] = mapped_column(BigInteger, server_default="0")
    model: Mapped[str] = mapped_column(String(64))
    prompt_tokens: Mapped[int] = mapped_column(Integer, server_default="0")
    completion_tokens: Mapped[int] = mapped_column(Integer, server_default="0")
    cache_hit_tokens: Mapped[int] = mapped_column(Integer, server_default="0")
    reasoning_tokens: Mapped[int] = mapped_column(Integer, server_default="0")
    cost_micro_usd: Mapped[int] = mapped_column(BigInteger, server_default="0")
    latency_ms: Mapped[int] = mapped_column(Integer, server_default="0")
    # ok | partial | replay | provider_error | timeout | bad_output | rate_limited
    outcome: Mapped[str] = mapped_column(String(20))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), index=True)
