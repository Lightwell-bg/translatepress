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
    Boolean,
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
    # Open/lost card disputes block paid usage independently of ``status``
    # (which stays the administrator's switch). Recomputed from ``disputes``.
    dispute_hold: Mapped[bool] = mapped_column(Boolean, server_default="false")
    # The Starter Checkout Session handed out and not yet finished. Under the
    # account row lock a repeated "subscribe" request returns the same URL
    # instead of creating a second session (two paid subscriptions otherwise).
    # Stripe sessions live 24 h; the URL is a Stripe-hosted page, not a secret of ours.
    pending_checkout_session_id: Mapped[str | None] = mapped_column(String(255), nullable=True)
    pending_checkout_url: Mapped[str | None] = mapped_column(String(2048), nullable=True)
    pending_checkout_expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
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
    # Refunded characters still inside reservations; revoked when they are released.
    revoke_pending: Mapped[int] = mapped_column(BigInteger, server_default="0")
    valid_from: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        CheckConstraint("kind IN ('monthly','pack')", name="ck_buckets_kind"),
        CheckConstraint("granted >= 0 AND used >= 0 AND reserved >= 0", name="ck_buckets_non_negative"),
        CheckConstraint("used + reserved <= granted", name="ck_buckets_not_overspent"),
        CheckConstraint("revoke_pending >= 0 AND revoke_pending <= reserved", name="ck_buckets_revoke_pending"),
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


# --- billing (Stripe) -------------------------------------------------------
#
# The Stripe account is shared with another product (Vira). Every object we
# create carries metadata lc_app=lingocanvas, and every webhook object is
# matched against these tables first: anything we did not create is ignored.


class StripeEvent(Base):
    """Webhook idempotency: one row per Stripe event id we have seen."""

    __tablename__ = "stripe_events"

    event_id: Mapped[str] = mapped_column(String(255), primary_key=True)
    type: Mapped[str] = mapped_column(String(100))
    # processed | ignored (not ours / not relevant)
    status: Mapped[str] = mapped_column(String(20))
    stripe_created: Mapped[int] = mapped_column(BigInteger)
    received_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class CheckoutClaim(Base):
    """A paid sign-up waiting for its WordPress site to pick up the token.

    The site generates a random claim secret, sends only its SHA-256 to us when
    it starts checkout, and later proves possession of the secret to receive
    the site token. The token therefore never travels through a browser URL.
    """

    __tablename__ = "checkout_claims"

    id: Mapped[str] = mapped_column(String(64), primary_key=True)  # opaque public id
    secret_hash: Mapped[bytes] = mapped_column(LargeBinary(32))
    plan: Mapped[str] = mapped_column(String(20))
    site_name: Mapped[str] = mapped_column(String(255))
    stripe_checkout_session_id: Mapped[str | None] = mapped_column(String(255), unique=True, nullable=True)
    # Customer created by the backend (metadata lc_app) before the Checkout Session.
    stripe_customer_id: Mapped[str | None] = mapped_column(String(255), nullable=True)
    account_id: Mapped[int | None] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), nullable=True)
    # open -> paid -> consumed -> confirmed; open -> expired
    status: Mapped[str] = mapped_column(String(10), server_default="open")
    # The site created by the pickup, when the pickup happened; the token can be re-issued for it
    # (rotation) until recovery_until unless the site confirmed receipt (status confirmed).
    site_id: Mapped[int | None] = mapped_column(ForeignKey("sites.id", ondelete="RESTRICT"), nullable=True)
    consumed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    recovery_until: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    failed_attempts: Mapped[int] = mapped_column(Integer, server_default="0")
    # HMAC(TOKEN_PEPPER, client IP): sign-up rate limit without storing the address.
    client_ip_hash: Mapped[bytes | None] = mapped_column(LargeBinary(32), nullable=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        CheckConstraint("status IN ('open','paid','consumed','confirmed','expired')", name="ck_claims_status"),
        Index("ix_checkout_claims_ip_created", "client_ip_hash", "created_at"),
    )


class PairCode(Base):
    """One-time code to connect one more site to an existing account."""

    __tablename__ = "pair_codes"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    code_hash: Mapped[bytes] = mapped_column(LargeBinary(32), unique=True)
    expires_at: Mapped[datetime] = mapped_column(DateTime(timezone=True))
    used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class Subscription(Base):
    """Mirror of a Stripe subscription; always refreshed from the live object."""

    __tablename__ = "subscriptions"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    stripe_subscription_id: Mapped[str] = mapped_column(String(255), unique=True)
    plan: Mapped[str] = mapped_column(String(20))
    # Stripe status: incomplete, active, past_due, canceled, unpaid, ...
    status: Mapped[str] = mapped_column(String(30))
    current_period_end: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    cancel_at_period_end: Mapped[bool] = mapped_column(Boolean, server_default="false")
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class Payment(Base):
    """One paid Stripe object that granted quota (an invoice or a pack payment)."""

    __tablename__ = "payments"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    account_id: Mapped[int] = mapped_column(ForeignKey("accounts.id", ondelete="RESTRICT"), index=True)
    # subscription | pack
    kind: Mapped[str] = mapped_column(String(20))
    stripe_invoice_id: Mapped[str | None] = mapped_column(String(255), unique=True, nullable=True)
    stripe_payment_intent_id: Mapped[str | None] = mapped_column(String(255), unique=True, nullable=True)
    stripe_charge_id: Mapped[str | None] = mapped_column(String(255), unique=True, nullable=True)
    bucket_id: Mapped[int | None] = mapped_column(ForeignKey("quota_buckets.id", ondelete="RESTRICT"), nullable=True)
    amount_cents: Mapped[int] = mapped_column(BigInteger)
    currency: Mapped[str] = mapped_column(String(3))
    granted_chars: Mapped[int] = mapped_column(BigInteger)
    # Cumulative, monotonic: refunds are applied as deltas against these.
    refunded_cents: Mapped[int] = mapped_column(BigInteger, server_default="0")
    revoked_chars: Mapped[int] = mapped_column(BigInteger, server_default="0")
    disputed: Mapped[bool] = mapped_column(Boolean, server_default="false")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    __table_args__ = (
        CheckConstraint("kind IN ('subscription','pack')", name="ck_payments_kind"),
        CheckConstraint("amount_cents > 0", name="ck_payments_paid"),
        CheckConstraint(
            "refunded_cents >= 0 AND refunded_cents <= amount_cents AND revoked_chars >= 0 "
            "AND revoked_chars <= granted_chars",
            name="ck_payments_refunds",
        ),
    )


class Dispute(Base):
    """One Stripe dispute; terminal states never regress (stale events are ignored)."""

    __tablename__ = "disputes"

    stripe_dispute_id: Mapped[str] = mapped_column(String(255), primary_key=True)
    payment_id: Mapped[int] = mapped_column(ForeignKey("payments.id", ondelete="RESTRICT"), index=True)
    status: Mapped[str] = mapped_column(String(40))
    # won | lost | warning_closed: final, later "created/updated" events change nothing.
    terminal: Mapped[bool] = mapped_column(Boolean, server_default="false")
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())


class UnmatchedChargeEvent(Base):
    """Refund/dispute facts for charges we could not match (yet).

    A refund or dispute can be processed before the payment it belongs to
    (e.g. our invoice.paid was delayed by retries). Only ids and amounts are
    kept — no customer data — and they are applied when the payment appears.
    Rows of the other product on the shared account simply never match and
    are pruned after ``unmatched_retention_days``.
    """

    __tablename__ = "unmatched_charge_events"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)
    stripe_event_id: Mapped[str] = mapped_column(String(255), unique=True)
    # refund | dispute
    kind: Mapped[str] = mapped_column(String(10))
    charge_id: Mapped[str | None] = mapped_column(String(255), nullable=True, index=True)
    payment_intent_id: Mapped[str | None] = mapped_column(String(255), nullable=True, index=True)
    amount_refunded: Mapped[int] = mapped_column(BigInteger, server_default="0")
    dispute_id: Mapped[str | None] = mapped_column(String(255), nullable=True)
    dispute_status: Mapped[str | None] = mapped_column(String(40), nullable=True)
    applied_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), index=True)
