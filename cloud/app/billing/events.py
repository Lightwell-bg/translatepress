"""Stripe webhook processing: the only place where payments turn into quota.

Rules that keep money and state correct:

- Ours or not: an event is handled only if its object carries
  metadata lc_app=lingocanvas or refers to an object already in our tables.
  Everything else (the other product on the shared Stripe account) is
  acknowledged WITHOUT any Stripe API call. Refunds/disputes of charges we
  cannot match yet are parked (ids and amounts only) and applied when the
  payment shows up, so a refund processed before its payment is not lost.
- Idempotency: the stripe_events row is inserted in the SAME transaction as
  the effects. A duplicate delivery finds the row and does nothing; a failed
  transaction leaves no row, so Stripe's retry processes it again.
- Order independence: subscription state is copied from the live object,
  retrieved INSIDE the transaction after a per-subscription advisory lock,
  so an older snapshot can never overwrite a newer one. Quota is granted per
  invoice / payment id (quota.grant is idempotent by source_ref).
- Only real money grants quota: amount > 0. A claim (sign-up) is unlocked
  only by a granted paid invoice, never by subscription creation alone.
- Refunds are cumulative (amount_refunded so far); disputes keep their own
  terminal state and never regress.
"""

from __future__ import annotations

import logging
import math
import secrets
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

from sqlalchemy import delete, func, or_, select, text
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker

from app import quota
from app.billing.gateway import (
    APP_TAG,
    ChargeInfo,
    CheckoutSessionInfo,
    DisputeInfo,
    InvoiceInfo,
    StripeGateway,
    SubscriptionInfo,
)
from app.models import (
    Account,
    CheckoutClaim,
    Dispute,
    Payment,
    StripeEvent,
    Subscription,
    UnmatchedChargeEvent,
)

log = logging.getLogger("lingocanvas.billing")

# Subscription statuses during which the account has a paid plan.
LIVE_STATUSES = frozenset({"active", "trialing", "past_due"})
GRANTING_BILLING_REASONS = frozenset({"subscription_create", "subscription_cycle"})
DISPUTE_TERMINAL = frozenset({"won", "lost", "warning_closed"})
DISPUTE_CLEARED = frozenset({"won", "warning_closed"})


@dataclass(frozen=True)
class BillingCatalog:
    """Stripe Price ids -> what they sell. Filled from .env / config.ini."""

    plan_by_price: dict[str, str]  # price id -> plan name ("starter")
    pack_by_price: dict[str, str]  # price id -> pack name ("pack_250k")
    plan_chars: dict[str, int]  # plan -> monthly characters
    pack_chars: dict[str, int]  # pack -> characters
    pack_valid_months: int = 12
    currencies: frozenset[str] = frozenset({"eur"})


class BillingError(Exception):
    """Our object in an unexpected shape: 500 so Stripe retries and we get alerted."""


def is_ours(metadata: dict[str, str] | None) -> bool:
    return bool(metadata) and metadata.get("lc_app") == APP_TAG


def add_months(value: datetime, months: int) -> datetime:
    month = value.month - 1 + months
    year = value.year + month // 12
    month = month % 12 + 1
    days = [31, 29 if year % 4 == 0 and (year % 100 != 0 or year % 400 == 0) else 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1]
    return value.replace(year=year, month=month, day=min(value.day, days))


@dataclass(frozen=True)
class _Prepared:
    """What the handler needs, decided before the transaction (no Stripe data yet
    for subscriptions: that is fetched under the lock)."""

    subscription_id: str | None = None
    checkout: CheckoutSessionInfo | None = None
    invoice: InvoiceInfo | None = None
    payment_intent_id: str | None = None
    charge: ChargeInfo | None = None
    dispute: DisputeInfo | None = None


class EventProcessor:
    def __init__(
        self,
        sessionmaker: async_sessionmaker[AsyncSession],
        gateway: StripeGateway,
        catalog: BillingCatalog,
    ) -> None:
        self._sm = sessionmaker
        self._gw = gateway
        self._catalog = catalog

    # --- entry point -------------------------------------------------------

    async def process(self, event: dict, now: datetime) -> str:
        """Returns processed | ignored | duplicate. Raises to make Stripe retry."""
        etype = event["type"]
        obj = event["data"]["object"]
        handler = {
            "checkout.session.completed": self._checkout_completed,
            "checkout.session.async_payment_succeeded": self._checkout_completed,
            "checkout.session.expired": self._checkout_expired,
            "invoice.paid": self._invoice_paid,
            "invoice.payment_failed": self._subscription_sync,
            "customer.subscription.created": self._subscription_sync,
            "customer.subscription.updated": self._subscription_sync,
            "customer.subscription.deleted": self._subscription_sync,
            "charge.refunded": self._charge_refunded,
            "charge.dispute.created": self._dispute,
            "charge.dispute.updated": self._dispute,
            "charge.dispute.closed": self._dispute,
        }.get(etype)
        if handler is None:
            return await self._record_only(event, "ignored")

        prepared = await self._prepare(etype, obj)
        if prepared is None:
            # Not ours: acknowledge without touching anything else.
            return await self._record_only(event, "ignored")

        async with self._sm() as s, s.begin():
            if not await self._claim_event(s, event, "processed"):
                return "duplicate"
            sub = None
            if prepared.subscription_id:
                # Serialise per subscription, THEN read the live state: whoever
                # commits later also read later.
                await s.execute(
                    text("SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))"),
                    {"k": "lc-sub:" + prepared.subscription_id},
                )
                sub = await self._gw.retrieve_subscription(prepared.subscription_id)
            await handler(s, event, prepared, sub, now)
        log.info("stripe event %s %s processed", etype, event["id"])
        return "processed"

    # --- ours? (outside the transaction, no Stripe calls for foreign objects) --

    async def _prepare(self, etype: str, obj: dict) -> _Prepared | None:
        if etype.startswith("checkout.session."):
            info = self._gw.parse_checkout_session(obj)
            if not is_ours(info.metadata):
                return None
            return _Prepared(subscription_id=info.subscription_id, checkout=info)

        if etype.startswith("invoice."):
            snap = self._gw.invoice_snapshot(obj)
            if snap.subscription_id is None:
                return None
            if not is_ours(snap.subscription_metadata) and not await self._known_subscription(snap.subscription_id):
                return None
            invoice, payment_intent_id = await self._gw.invoice_info(obj)
            return _Prepared(subscription_id=snap.subscription_id, invoice=invoice, payment_intent_id=payment_intent_id)

        if etype.startswith("customer.subscription."):
            parsed = self._gw.parse_subscription(obj)
            if not is_ours(parsed.metadata) and not await self._known_subscription(parsed.id):
                return None
            return _Prepared(subscription_id=parsed.id)

        if etype == "charge.refunded":
            return _Prepared(charge=self._gw.parse_charge(obj))

        if etype.startswith("charge.dispute."):
            return _Prepared(dispute=self._gw.parse_dispute(obj))
        return None

    async def _known_subscription(self, subscription_id: str) -> bool:
        async with self._sm() as s:
            found = await s.execute(
                select(Subscription.id).where(Subscription.stripe_subscription_id == subscription_id)
            )
            return found.scalar_one_or_none() is not None

    @staticmethod
    async def _payment_id(s: AsyncSession, charge_id: str | None, payment_intent_id: str | None) -> int | None:
        if payment_intent_id:
            found = (
                await s.execute(select(Payment.id).where(Payment.stripe_payment_intent_id == payment_intent_id))
            ).scalar_one_or_none()
            if found is not None:
                return found
        if charge_id:
            return (
                await s.execute(select(Payment.id).where(Payment.stripe_charge_id == charge_id))
            ).scalar_one_or_none()
        return None

    # --- idempotency -------------------------------------------------------

    async def _claim_event(self, s: AsyncSession, event: dict, status: str) -> bool:
        inserted = await s.execute(
            pg_insert(StripeEvent)
            .values(
                event_id=event["id"],
                type=event["type"],
                status=status,
                stripe_created=int(event.get("created") or 0),
            )
            .on_conflict_do_nothing(index_elements=[StripeEvent.event_id])
            .returning(StripeEvent.event_id)
        )
        return inserted.scalar_one_or_none() is not None

    async def _record_only(self, event: dict, status: str) -> str:
        async with self._sm() as s, s.begin():
            fresh = await self._claim_event(s, event, status)
        return status if fresh else "duplicate"

    # --- accounts ----------------------------------------------------------

    async def _ensure_account(
        self, s: AsyncSession, metadata: dict[str, str], customer_id: str, email: str | None, plan: str | None
    ) -> Account:
        """Account for one of our objects, created from the sign-up claim if needed.

        Every path can be first; all converge on one account through the claim
        row lock and the unique stripe_customer_id. Does NOT unlock the claim:
        only a paid invoice does (see _invoice_paid).
        """
        account_id = metadata.get("lc_account_id")
        if account_id:
            account = await s.get(Account, int(account_id), with_for_update=True)
            if account is None:
                raise BillingError(f"metadata names unknown account {account_id}")
            if account.stripe_customer_id is None:
                account.stripe_customer_id = customer_id
            elif account.stripe_customer_id != customer_id:
                raise BillingError("customer mismatch for account")
            return account

        claim = await self._lock_claim(s, metadata)
        if claim is None:
            raise BillingError("our object without lc_account_id / known lc_claim_id")
        if claim.account_id is not None:
            account = await s.get(Account, claim.account_id, with_for_update=True)
            assert account is not None
            return account

        account = (
            await s.execute(select(Account).where(Account.stripe_customer_id == customer_id).with_for_update())
        ).scalar_one_or_none()
        if account is None:
            account = Account(name=claim.site_name, email=email, plan=plan or claim.plan, stripe_customer_id=customer_id)
            s.add(account)
            await s.flush()
        claim.account_id = account.id
        await s.flush()
        return account

    @staticmethod
    async def _lock_claim(s: AsyncSession, metadata: dict[str, str]) -> CheckoutClaim | None:
        claim_id = metadata.get("lc_claim_id")
        if not claim_id:
            return None
        return (
            await s.execute(select(CheckoutClaim).where(CheckoutClaim.id == claim_id).with_for_update())
        ).scalar_one_or_none()

    async def _upsert_subscription(self, s: AsyncSession, account: Account, sub: SubscriptionInfo, now: datetime) -> None:
        plan = self._catalog.plan_by_price.get(sub.price_id or "")
        if plan is None:
            raise BillingError(f"subscription {sub.id} has an unknown price")
        row = (
            await s.execute(
                select(Subscription).where(Subscription.stripe_subscription_id == sub.id).with_for_update()
            )
        ).scalar_one_or_none()
        if row is None:
            row = Subscription(account_id=account.id, stripe_subscription_id=sub.id, plan=plan, status=sub.status)
            s.add(row)
        elif row.account_id != account.id:
            raise BillingError("subscription belongs to another account")
        row.plan = plan
        row.status = sub.status
        row.current_period_end = sub.current_period_end
        row.cancel_at_period_end = sub.cancel_at_period_end
        row.updated_at = now
        if sub.status in LIVE_STATUSES:
            account.plan = plan
        await s.flush()

    async def _account_for_subscription(self, s: AsyncSession, sub: SubscriptionInfo, email: str | None) -> Account:
        row = (
            await s.execute(select(Subscription).where(Subscription.stripe_subscription_id == sub.id))
        ).scalar_one_or_none()
        if row is not None:
            account = await s.get(Account, row.account_id, with_for_update=True)
            assert account is not None
            return account
        return await self._ensure_account(
            s, sub.metadata, sub.customer_id, email, self._catalog.plan_by_price.get(sub.price_id or "")
        )

    # --- handlers ----------------------------------------------------------

    async def _checkout_completed(self, s, event, p: _Prepared, sub: SubscriptionInfo | None, now) -> None:
        info = p.checkout
        assert info is not None
        if info.mode == "subscription":
            if sub is None or info.customer_id is None:
                raise BillingError("subscription checkout without subscription/customer")
            account = await self._ensure_account(
                s, info.metadata, info.customer_id, info.customer_email,
                self._catalog.plan_by_price.get(sub.price_id or ""),
            )
            await self._upsert_subscription(s, account, sub, now)
            return  # quota + claim unlock come from invoice.paid (the actual payment)

        if info.mode == "payment":
            if info.payment_status != "paid":
                return  # delayed method (e.g. SEPA): wait for async_payment_succeeded
            pack = self._catalog.pack_by_price.get(info.price_id or "")
            if pack is None or info.payment_intent_id is None or info.customer_id is None:
                raise BillingError("pack checkout with unknown price or without payment intent")
            if info.amount_total <= 0 or info.currency.lower() not in self._catalog.currencies:
                log.warning("pack checkout %s without real payment: not granted", info.id)
                return
            account = await self._ensure_account(s, info.metadata, info.customer_id, info.customer_email, None)
            chars = self._catalog.pack_chars[pack]
            # Dates derive from the session, not from "now": a redelivery
            # computes the same grant and quota.grant sees an identical repeat.
            bucket_id, _ = await quota.grant(
                s,
                account_id=account.id,
                kind="pack",
                chars=chars,
                now=now,
                source="stripe_payment",
                source_ref=f"stripe_pi:{info.payment_intent_id}",
                valid_from=info.created,
                expires_at=add_months(info.created, self._catalog.pack_valid_months),
            )
            await self._record_payment(
                s, account.id, "pack", None, info.payment_intent_id, bucket_id, info.amount_total, info.currency,
                chars, now,
            )
            return
        raise BillingError(f"unexpected checkout mode {info.mode}")

    async def _checkout_expired(self, s, event, p: _Prepared, sub, now) -> None:
        info = p.checkout
        assert info is not None
        claim = await self._lock_claim(s, info.metadata)
        if claim is not None and claim.status == "open" and claim.stripe_checkout_session_id == info.id:
            claim.status = "expired"

    async def _invoice_paid(self, s, event, p: _Prepared, sub: SubscriptionInfo | None, now) -> None:
        invoice = p.invoice
        assert invoice is not None and sub is not None
        account = await self._account_for_subscription(s, sub, None)
        await self._upsert_subscription(s, account, sub, now)
        if invoice.status != "paid" or invoice.billing_reason not in GRANTING_BILLING_REASONS:
            return  # e.g. proration invoices: no new period, nothing to grant
        if invoice.amount_paid <= 0 or invoice.currency.lower() not in self._catalog.currencies:
            # Trials / 100% coupons are not part of the approved model: no free quota.
            log.warning("invoice %s without real payment: not granted", invoice.id)
            return
        plan = self._catalog.plan_by_price.get(invoice.price_id or "")
        if plan is None or invoice.period_start is None or invoice.period_end is None:
            raise BillingError(f"invoice {invoice.id} without a known plan line")
        chars = self._catalog.plan_chars[plan]
        bucket_id, _ = await quota.grant(
            s,
            account_id=account.id,
            kind="monthly",
            chars=chars,
            now=now,
            source="stripe_invoice",
            source_ref=f"stripe_invoice:{invoice.id}",
            valid_from=invoice.period_start,
            expires_at=invoice.period_end,
        )
        await self._record_payment(
            s, account.id, "subscription", invoice.id, p.payment_intent_id, bucket_id,
            invoice.amount_paid, invoice.currency, chars, now,
        )
        # Money arrived: the sign-up claim (if any) may now hand out its token.
        claim = await self._lock_claim(s, sub.metadata)
        if claim is not None and claim.account_id == account.id and claim.status == "open":
            claim.status = "paid"

    async def _subscription_sync(self, s, event, p: _Prepared, sub: SubscriptionInfo | None, now) -> None:
        assert sub is not None
        account = await self._account_for_subscription(s, sub, None)
        await self._upsert_subscription(s, account, sub, now)

    async def _charge_refunded(self, s, event, p: _Prepared, sub, now) -> None:
        charge = p.charge
        assert charge is not None
        payment_id = await self._payment_id(s, charge.id, charge.payment_intent_id)
        if payment_id is None:
            await self._park(s, event, "refund", charge.id, charge.payment_intent_id, amount_refunded=charge.amount_refunded)
            return
        payment = await self._lock_payment(s, payment_id)
        await self._apply_refund(s, payment, charge.id, charge.amount_refunded, now)

    async def _dispute(self, s, event, p: _Prepared, sub, now) -> None:
        dispute = p.dispute
        assert dispute is not None
        payment_id = await self._payment_id(s, dispute.charge_id, dispute.payment_intent_id)
        if payment_id is None:
            await self._park(s, event, "dispute", dispute.charge_id, dispute.payment_intent_id,
                             dispute_id=dispute.id, dispute_status=dispute.status)
            return
        payment = await self._lock_payment(s, payment_id)
        await self._apply_dispute(s, payment, dispute.id, dispute.status, now)

    # --- refunds, disputes, parking -------------------------------------------

    async def _apply_refund(self, s: AsyncSession, payment: Payment, charge_id: str | None, amount_refunded: int, now) -> None:
        if payment.stripe_charge_id is None and charge_id:
            payment.stripe_charge_id = charge_id
        refunded = min(amount_refunded, payment.amount_cents)
        if refunded <= payment.refunded_cents:
            return  # stale or repeated: cumulative amount did not grow
        payment.refunded_cents = refunded
        if payment.bucket_id is None:
            return
        full = refunded >= payment.amount_cents
        target = payment.granted_chars if full else math.ceil(payment.granted_chars * refunded / payment.amount_cents)
        delta = target - payment.revoked_chars
        if delta > 0 or full:
            await quota.revoke_unused(
                s,
                account_id=payment.account_id,
                bucket_id=payment.bucket_id,
                chars=None if full else delta,
                now=now,
                note=f"refund {charge_id or ''}".strip(),
            )
            # The obligation is registered in full (immediately or as revoke_pending).
            payment.revoked_chars = max(payment.revoked_chars, target)
        await s.flush()

    async def _apply_dispute(self, s: AsyncSession, payment: Payment, dispute_id: str, status: str, now) -> None:
        row = await s.get(Dispute, dispute_id, with_for_update=True)
        if row is not None and row.terminal:
            return  # final decision already recorded; stale events change nothing
        if row is None:
            row = Dispute(stripe_dispute_id=dispute_id, payment_id=payment.id, status=status)
            s.add(row)
        row.status = status
        row.terminal = status in DISPUTE_TERMINAL
        row.updated_at = now
        await s.flush()

        if status not in DISPUTE_CLEARED and payment.bucket_id is not None and payment.revoked_chars < payment.granted_chars:
            await quota.revoke_unused(
                s, account_id=payment.account_id, bucket_id=payment.bucket_id, chars=None, now=now,
                note=f"dispute {dispute_id}",
            )
            payment.revoked_chars = payment.granted_chars

        # Hold while any dispute of this account is open or lost.
        open_disputes = (
            await s.execute(
                select(func.count())
                .select_from(Dispute)
                .join(Payment, Payment.id == Dispute.payment_id)
                .where(Payment.account_id == payment.account_id, Dispute.status.not_in(DISPUTE_CLEARED))
            )
        ).scalar_one()
        payment.disputed = (
            await s.execute(
                select(func.count())
                .select_from(Dispute)
                .where(Dispute.payment_id == payment.id, Dispute.status.not_in(DISPUTE_CLEARED))
            )
        ).scalar_one() > 0
        account = await s.get(Account, payment.account_id)
        assert account is not None
        account.dispute_hold = open_disputes > 0
        await s.flush()

    async def _park(self, s: AsyncSession, event: dict, kind: str, charge_id, payment_intent_id, **fields) -> None:
        if not charge_id and not payment_intent_id:
            return
        await s.execute(
            pg_insert(UnmatchedChargeEvent)
            .values(
                stripe_event_id=event["id"],
                kind=kind,
                charge_id=charge_id,
                payment_intent_id=payment_intent_id,
                **fields,
            )
            .on_conflict_do_nothing(index_elements=[UnmatchedChargeEvent.stripe_event_id])
        )

    async def _reconcile_parked(self, s: AsyncSession, payment: Payment, now: datetime) -> None:
        """Apply refunds/disputes that were processed before this payment existed."""
        conds = []
        if payment.stripe_payment_intent_id:
            conds.append(UnmatchedChargeEvent.payment_intent_id == payment.stripe_payment_intent_id)
        if payment.stripe_charge_id:
            conds.append(UnmatchedChargeEvent.charge_id == payment.stripe_charge_id)
        if not conds:
            return
        rows = list(
            (
                await s.execute(
                    select(UnmatchedChargeEvent)
                    .where(or_(*conds), UnmatchedChargeEvent.applied_at.is_(None))
                    .order_by(UnmatchedChargeEvent.id)
                    .with_for_update()
                )
            ).scalars()
        )
        for row in rows:
            if row.kind == "refund":
                await self._apply_refund(s, payment, row.charge_id, row.amount_refunded, now)
            elif row.kind == "dispute" and row.dispute_id and row.dispute_status:
                await self._apply_dispute(s, payment, row.dispute_id, row.dispute_status, now)
            row.applied_at = now
        await s.flush()

    # --- helpers -------------------------------------------------------------

    @staticmethod
    async def _lock_payment(s: AsyncSession, payment_id: int) -> Payment:
        # Account first (global lock order), then the payment row.
        account_id = (await s.execute(select(Payment.account_id).where(Payment.id == payment_id))).scalar_one()
        await s.execute(select(Account.id).where(Account.id == account_id).with_for_update())
        return (await s.execute(select(Payment).where(Payment.id == payment_id).with_for_update())).scalar_one()

    async def _record_payment(
        self,
        s: AsyncSession,
        account_id: int,
        kind: str,
        invoice_id: str | None,
        payment_intent_id: str | None,
        bucket_id: int,
        amount: int,
        currency: str,
        chars: int,
        now: datetime,
    ) -> None:
        inserted = (
            await s.execute(
                pg_insert(Payment)
                .values(
                    account_id=account_id,
                    kind=kind,
                    stripe_invoice_id=invoice_id,
                    stripe_payment_intent_id=payment_intent_id,
                    bucket_id=bucket_id,
                    amount_cents=amount,
                    currency=currency.lower(),
                    granted_chars=chars,
                )
                .on_conflict_do_nothing()
                .returning(Payment.id)
            )
        ).scalar_one_or_none()
        if inserted is not None:
            payment = await self._lock_payment(s, inserted)
            await self._reconcile_parked(s, payment, now)


async def prune_parked(s: AsyncSession, now: datetime, retention_days: int) -> int:
    """Drop parked charge facts that never matched (other product's charges)."""
    result = await s.execute(
        delete(UnmatchedChargeEvent).where(UnmatchedChargeEvent.created_at < now - timedelta(days=retention_days))
    )
    return result.rowcount or 0


def new_claim_id() -> str:
    return "lcc_claim_" + secrets.token_urlsafe(18)


def utcnow() -> datetime:
    return datetime.now(timezone.utc)
