"""Normalised view of Stripe objects + the gateway contract.

The event processor (events.py) only ever sees these dataclasses, never raw
Stripe JSON: API-version differences (e.g. where an invoice keeps its
subscription id or a subscription its billing period) live in one place, the
gateway implementation (stripe_gateway.py), and the core stays testable with
a fake gateway.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime
from typing import Protocol

APP_TAG = "lingocanvas"  # metadata lc_app value on every object we create


@dataclass(frozen=True)
class SubscriptionInfo:
    id: str
    customer_id: str
    status: str
    price_id: str | None
    current_period_start: datetime | None
    current_period_end: datetime | None
    cancel_at_period_end: bool
    metadata: dict[str, str] = field(default_factory=dict)


@dataclass(frozen=True)
class InvoiceInfo:
    id: str
    customer_id: str
    subscription_id: str | None
    status: str  # paid, open, ...
    billing_reason: str | None
    amount_paid: int
    currency: str
    price_id: str | None
    period_start: datetime | None  # of the subscription line, i.e. the paid period
    period_end: datetime | None
    # Snapshot of the subscription metadata Stripe puts on the invoice.
    subscription_metadata: dict[str, str] = field(default_factory=dict)


@dataclass(frozen=True)
class CheckoutSessionInfo:
    id: str
    mode: str  # subscription | payment
    status: str | None  # open | complete | expired
    payment_status: str  # paid | unpaid | no_payment_required
    customer_id: str | None
    customer_email: str | None
    subscription_id: str | None
    payment_intent_id: str | None
    amount_total: int
    currency: str
    created: datetime
    price_id: str | None
    metadata: dict[str, str] = field(default_factory=dict)


@dataclass(frozen=True)
class ChargeInfo:
    id: str
    payment_intent_id: str | None
    amount: int
    amount_refunded: int  # cumulative


@dataclass(frozen=True)
class DisputeInfo:
    id: str
    charge_id: str | None
    payment_intent_id: str | None
    status: str  # warning_needs_response, needs_response, under_review, won, lost, ...


class StripeGateway(Protocol):
    """Everything the billing core needs from Stripe."""

    # --- parsing webhook payload objects (no network) ---
    def parse_checkout_session(self, obj: dict) -> CheckoutSessionInfo: ...
    def parse_subscription(self, obj: dict) -> SubscriptionInfo: ...
    def parse_charge(self, obj: dict) -> ChargeInfo: ...
    def parse_dispute(self, obj: dict) -> DisputeInfo: ...

    # --- network ---
    async def retrieve_subscription(self, subscription_id: str) -> SubscriptionInfo: ...
    # Invoice with the line period and the payment intent that paid it.
    async def invoice_info(self, obj: dict) -> tuple[InvoiceInfo, str | None]: ...
    def invoice_snapshot(self, obj: dict) -> InvoiceInfo: ...  # no network, for the "is it ours" test

    async def create_customer(self, email: str | None, metadata: dict[str, str], idempotency_key: str) -> str: ...
    # Merge metadata lc_app=lingocanvas into an existing customer (invariant: every customer we charge carries it).
    async def ensure_customer_tag(self, customer_id: str, idempotency_key: str) -> None: ...
    async def create_checkout_session(self, params: dict, idempotency_key: str) -> tuple[str, str]: ...  # (id, url)
    async def create_portal_session(self, customer_id: str, return_url: str) -> str: ...
