"""Billing test doubles: a fake Stripe gateway, Stripe-shaped object builders, signed webhook requests.

The fake gateway reuses the real parsers (so parsing is exercised end to end)
and COUNTS every method that would hit the Stripe network: tests for foreign
(other product) events assert that this counter stays at zero.
"""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import itertools
import json
import time
from dataclasses import replace
from datetime import datetime, timedelta, timezone

from app.billing import stripe_gateway as real
from app.billing.gateway import InvoiceInfo, SubscriptionInfo

WHSEC = "whsec_test_signing_secret_0123456789"
PRICE_STARTER = "price_starter_test"
PRICE_PACK = "price_pack250k_test"

STRIPE_SETTINGS = {
    "stripe_secret_key": "sk_test_fake_key_not_real",
    "stripe_webhook_secret": WHSEC,
    "stripe_price_starter": PRICE_STARTER,
    "stripe_price_pack_250k": PRICE_PACK,
    "stripe_portal_configuration": "bpc_test_lingocanvas",
    "stripe_livemode": False,
}


class FakeGateway:
    NETWORK_METHODS = ("retrieve_subscription", "invoice_info", "create_customer", "ensure_customer_tag", "create_checkout_session", "create_portal_session")

    def __init__(self) -> None:
        self.subscriptions: dict[str, SubscriptionInfo] = {}
        self.invoice_payment_intents: dict[str, str] = {}  # invoice id -> pi (used when the invoice has none)
        self.calls: list[str] = []
        self.checkout_calls: list[tuple[dict, str]] = []
        self.portal_calls: list[tuple[str, str]] = []
        self.fail_checkout = False
        self.fail_customer = False
        self.fail_tag = False
        self.tag_calls: list[tuple[str, str]] = []
        self.customer_calls: list[tuple[dict, str]] = []
        # Price ids the (real) gateway would be configured with for invoice line selection.
        self.plan_price_ids = frozenset({PRICE_STARTER})
        # Seconds a checkout session creation takes (to make concurrent requests overlap).
        self.checkout_delay = 0.0
        # Scripted retrieve_subscription answers, consumed in CALL order: (info, gate).
        # The answer is fixed at call time; the call then waits for ``gate`` (an asyncio.Event) if given.
        self.retrieve_script: list[tuple[SubscriptionInfo, asyncio.Event | None]] = []
        self._sessions = itertools.count(1)

    @property
    def network_calls(self) -> int:
        return len(self.calls)

    # --- parsing: the real parsers, no network ---
    def parse_checkout_session(self, obj):
        return real.parse_checkout_session(obj)

    def parse_subscription(self, obj):
        return real.parse_subscription(obj)

    def parse_charge(self, obj):
        return real.parse_charge(obj)

    def parse_dispute(self, obj):
        return real.parse_dispute(obj)

    def invoice_snapshot(self, obj) -> InvoiceInfo:
        return real.parse_invoice(obj, self.plan_price_ids)

    # --- network (counted) ---
    async def retrieve_subscription(self, subscription_id: str) -> SubscriptionInfo:
        self.calls.append("retrieve_subscription")
        if self.retrieve_script:
            info, gate = self.retrieve_script.pop(0)
            if gate is not None:
                await gate.wait()
            return info
        return self.subscriptions[subscription_id]

    async def invoice_info(self, obj):
        self.calls.append("invoice_info")
        info = real.parse_invoice(obj, self.plan_price_ids)
        pi = real._id(obj.get("payment_intent")) or self.invoice_payment_intents.get(info.id)
        return info, pi

    async def create_customer(self, email, metadata, idempotency_key):
        self.calls.append("create_customer")
        if self.fail_customer:
            raise RuntimeError("stripe down (secret-looking text sk_test_leak)")
        self.customer_calls.append(({"email": email, "metadata": dict(metadata)}, idempotency_key))
        # Same idempotency key -> same customer, like Stripe.
        return f"cus_new_{idempotency_key}"

    async def ensure_customer_tag(self, customer_id: str, idempotency_key: str) -> None:
        self.calls.append("ensure_customer_tag")
        if self.fail_tag:
            raise RuntimeError("stripe down (secret-looking text sk_test_leak)")
        self.tag_calls.append((customer_id, idempotency_key))

    async def create_checkout_session(self, params: dict, idempotency_key: str):
        self.calls.append("create_checkout_session")
        if self.checkout_delay:
            await asyncio.sleep(self.checkout_delay)
        if self.fail_checkout:
            raise RuntimeError("stripe down (secret-looking text sk_test_leak)")
        self.checkout_calls.append((params, idempotency_key))
        n = next(self._sessions)
        return f"cs_test_{n}", f"https://checkout.test/pay/{n}"

    async def create_portal_session(self, customer_id: str, return_url: str) -> str:
        self.calls.append("create_portal_session")
        self.portal_calls.append((customer_id, return_url))
        return f"https://billing.test/portal/{customer_id}"

    # --- test helpers ---
    def set_subscription(self, sub: SubscriptionInfo) -> SubscriptionInfo:
        self.subscriptions[sub.id] = sub
        return sub

    def update_subscription(self, sub_id: str, **changes) -> SubscriptionInfo:
        self.subscriptions[sub_id] = replace(self.subscriptions[sub_id], **changes)
        return self.subscriptions[sub_id]


# --- object builders --------------------------------------------------------------


def ts(value: datetime) -> int:
    return int(value.timestamp())


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(microsecond=0)


def sub_info(
    sub_id="sub_1", customer="cus_1", status="active", price=PRICE_STARTER, start=None, end=None,
    cancel=False, metadata=None,
) -> SubscriptionInfo:
    start = start or utcnow() - timedelta(days=1)
    return SubscriptionInfo(
        id=sub_id, customer_id=customer, status=status, price_id=price,
        current_period_start=start, current_period_end=end or start + timedelta(days=30),
        cancel_at_period_end=cancel, metadata=metadata or {},
    )


def checkout_session(
    *, id="cs_1", mode="subscription", payment_status="paid", status="complete", customer="cus_1",
    subscription="sub_1", payment_intent=None, amount_total=900, metadata=None, created=None, email=None,
) -> dict:
    return {
        "id": id, "object": "checkout.session", "mode": mode, "status": status, "payment_status": payment_status,
        "customer": customer, "subscription": subscription, "payment_intent": payment_intent,
        "amount_total": amount_total, "currency": "eur", "created": ts(created or utcnow()),
        "customer_details": {"email": email} if email else None, "metadata": metadata or {},
    }


def invoice(
    *, id="in_1", customer="cus_1", subscription="sub_1", reason="subscription_create", status="paid", amount=900,
    pi="pi_1", start=None, end=None, price=PRICE_STARTER, metadata=None, shape="old", currency="eur",
) -> dict:
    start = start or utcnow() - timedelta(days=1)
    end = end or start + timedelta(days=30)
    period = {"start": ts(start), "end": ts(end)}
    meta = metadata or {}
    obj = {
        "id": id, "object": "invoice", "customer": customer, "status": status, "billing_reason": reason,
        "amount_paid": amount if status == "paid" else 0, "currency": currency,
    }
    if shape == "old":
        obj["subscription"] = subscription
        obj["payment_intent"] = pi
        obj["subscription_details"] = {"metadata": meta}
        obj["lines"] = {"data": [{"type": "subscription", "price": {"id": price}, "period": period}]}
    else:  # newer API: parent.subscription_details, pricing.price_details, no payment_intent field
        obj["parent"] = {"type": "subscription_details", "subscription_details": {"subscription": subscription, "metadata": meta}}
        obj["lines"] = {"data": [{"pricing": {"price_details": {"price": price}}, "period": period}]}
    return obj


def subscription_obj(
    *, id="sub_1", customer="cus_1", status="active", price=PRICE_STARTER, start=None, end=None, cancel=False,
    metadata=None, shape="old",
) -> dict:
    start = start or utcnow() - timedelta(days=1)
    end = end or start + timedelta(days=30)
    obj = {
        "id": id, "object": "subscription", "customer": customer, "status": status,
        "cancel_at_period_end": cancel, "metadata": metadata or {},
    }
    item = {"price": {"id": price}}
    if shape == "old":
        obj["current_period_start"] = ts(start)
        obj["current_period_end"] = ts(end)
    else:
        item["current_period_start"] = ts(start)
        item["current_period_end"] = ts(end)
    obj["items"] = {"data": [item]}
    return obj


def charge(*, id="ch_1", pi="pi_1", amount=900, refunded=900) -> dict:
    return {"id": id, "object": "charge", "payment_intent": pi, "amount": amount, "amount_refunded": refunded}


def dispute(*, id="dp_1", charge_id="ch_1", pi="pi_1", status="needs_response") -> dict:
    return {"id": id, "object": "dispute", "charge": charge_id, "payment_intent": pi, "status": status}


_event_ids = itertools.count(1)


def event(type_: str, obj: dict, *, id: str | None = None, livemode: bool = False, created: datetime | None = None) -> dict:
    return {
        "id": id or f"evt_test_{next(_event_ids):06d}", "object": "event", "type": type_,
        "created": ts(created or utcnow()), "livemode": livemode, "data": {"object": obj},
    }


# --- signed requests -----------------------------------------------------------------


def sign(payload: bytes, secret: str = WHSEC, timestamp: int | None = None) -> str:
    t = int(time.time()) if timestamp is None else timestamp
    digest = hmac.new(secret.encode(), f"{t}.".encode() + payload, hashlib.sha256).hexdigest()
    return f"t={t},v1={digest}"


async def post_event(client, evt: dict, *, secret: str = WHSEC, timestamp: int | None = None, payload: bytes | None = None):
    body = payload if payload is not None else json.dumps(evt).encode()
    return await client.post(
        "/v1/stripe/webhook",
        content=body,
        headers={"Stripe-Signature": sign(body, secret, timestamp), "Content-Type": "application/json"},
    )
