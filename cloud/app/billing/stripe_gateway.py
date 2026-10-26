"""Stripe SDK implementation of the gateway contract.

Parsers read plain dicts (the webhook JSON, or ``StripeObject.to_dict()`` of an
API response) and tolerate both the older and the newer Stripe API shapes
(e.g. ``invoice.subscription`` vs ``invoice.parent.subscription_details``).
They never raise on missing keys: a foreign (other product) event may be
shaped differently and must still reach the ownership test.

Network methods use ``stripe.StripeClient`` async calls. Nothing here logs
payloads: they contain customer data.
"""

from __future__ import annotations

from collections.abc import Collection
from datetime import datetime, timezone
from typing import Any

import stripe

from app.billing.gateway import (
    APP_TAG,
    ChargeInfo,
    CheckoutSessionInfo,
    DisputeInfo,
    InvoiceInfo,
    SubscriptionInfo,
)

# The API version this code was written against: the SDK's own default.
# The webhook endpoint in the Stripe dashboard must use the same version.
API_VERSION = stripe.api_version


# --- tiny helpers -------------------------------------------------------------


def _plain(value: Any) -> Any:
    """StripeObject / dict -> plain dict (recursively handled by ``to_dict``)."""
    if hasattr(value, "to_dict"):
        return value.to_dict()
    return value


def _get(obj: Any, *path: str) -> Any:
    """Safe nested lookup: returns None when any step is missing or not a dict."""
    cur = obj
    for key in path:
        if not isinstance(cur, dict):
            return None
        cur = cur.get(key)
        if cur is None:
            return None
    return cur


def _id(value: Any) -> str | None:
    """An id that may arrive as a string or as an expanded object."""
    if isinstance(value, str):
        return value or None
    if isinstance(value, dict):
        inner = value.get("id")
        return inner if isinstance(inner, str) and inner else None
    return None


def _ts(value: Any) -> datetime | None:
    if value is None:
        return None
    try:
        return datetime.fromtimestamp(int(value), tz=timezone.utc)
    except (TypeError, ValueError, OverflowError, OSError):
        return None


def _meta(value: Any) -> dict[str, str]:
    if not isinstance(value, dict):
        return {}
    return {str(k): str(v) for k, v in value.items() if v is not None}


def _int(value: Any) -> int:
    try:
        return int(value or 0)
    except (TypeError, ValueError):
        return 0


# --- parsers (no network) ------------------------------------------------------


def parse_subscription(raw: Any) -> SubscriptionInfo:
    obj = _plain(raw)
    first_item = _get(obj, "items", "data")
    first_item = first_item[0] if isinstance(first_item, list) and first_item else {}
    # Newer API versions moved the period from the subscription to its items.
    start = obj.get("current_period_start") or first_item.get("current_period_start")
    end = obj.get("current_period_end") or first_item.get("current_period_end")
    price_id = _get(first_item, "price", "id") or _get(first_item, "plan", "id")
    return SubscriptionInfo(
        id=str(obj.get("id") or ""),
        customer_id=_id(obj.get("customer")) or "",
        status=str(obj.get("status") or ""),
        price_id=price_id,
        current_period_start=_ts(start),
        current_period_end=_ts(end),
        cancel_at_period_end=bool(obj.get("cancel_at_period_end")),
        metadata=_meta(obj.get("metadata")),
    )


def parse_checkout_session(raw: Any) -> CheckoutSessionInfo:
    obj = _plain(raw)
    metadata = _meta(obj.get("metadata"))
    price_id = None
    lines = _get(obj, "line_items", "data")
    if isinstance(lines, list) and lines:
        price_id = _get(lines[0], "price", "id")
    if price_id is None:
        # Webhook payloads carry no line items: our pack checkouts stamp the price in metadata.
        price_id = metadata.get("lc_price_id")
    return CheckoutSessionInfo(
        id=str(obj.get("id") or ""),
        mode=str(obj.get("mode") or ""),
        status=obj.get("status"),
        payment_status=str(obj.get("payment_status") or ""),
        customer_id=_id(obj.get("customer")),
        customer_email=_get(obj, "customer_details", "email") or obj.get("customer_email"),
        subscription_id=_id(obj.get("subscription")),
        payment_intent_id=_id(obj.get("payment_intent")),
        amount_total=_int(obj.get("amount_total")),
        currency=str(obj.get("currency") or ""),
        created=_ts(obj.get("created")) or datetime.fromtimestamp(0, tz=timezone.utc),
        price_id=price_id,
        metadata=metadata,
    )


def parse_charge(raw: Any) -> ChargeInfo:
    obj = _plain(raw)
    return ChargeInfo(
        id=str(obj.get("id") or ""),
        payment_intent_id=_id(obj.get("payment_intent")),
        amount=_int(obj.get("amount")),
        amount_refunded=_int(obj.get("amount_refunded")),
    )


def parse_dispute(raw: Any) -> DisputeInfo:
    obj = _plain(raw)
    return DisputeInfo(
        id=str(obj.get("id") or ""),
        charge_id=_id(obj.get("charge")),
        payment_intent_id=_id(obj.get("payment_intent")),
        status=str(obj.get("status") or ""),
    )


def _line_price_id(line: dict) -> str | None:
    return (
        _get(line, "price", "id")
        or _id(_get(line, "pricing", "price_details", "price"))
        or _get(line, "plan", "id")
    )


def _is_proration(line: dict) -> bool:
    """A proration / non-subscription line, in the older and the newer API shape."""
    if line.get("proration") is True:  # older: line.proration
        return True
    if line.get("type") not in (None, "subscription"):  # older: line.type is "subscription" for plan lines
        return True
    if _get(line, "parent", "subscription_item_details", "proration") is True:  # newer
        return True
    parent_type = _get(line, "parent", "type")  # newer: line.parent.type
    return parent_type not in (None, "subscription_item_details")


def _subscription_line(obj: dict, plan_price_ids: Collection[str] | None) -> dict:
    """The invoice line that bills a KNOWN plan price and is not a proration, else {}.

    ``plan_price_ids=None`` skips the known-price test (parser use without a catalog).
    """
    lines = _get(obj, "lines", "data")
    if not isinstance(lines, list):
        return {}
    for line in lines:
        if not isinstance(line, dict) or _is_proration(line):
            continue
        price_id = _line_price_id(line)
        if price_id and (plan_price_ids is None or price_id in plan_price_ids):
            return line
    return {}


def parse_invoice(raw: Any, plan_price_ids: Collection[str] | None = None) -> InvoiceInfo:
    obj = _plain(raw)
    subscription_id = (
        _id(obj.get("subscription"))
        or _id(_get(obj, "parent", "subscription_details", "subscription"))
    )
    metadata = _meta(
        _get(obj, "subscription_details", "metadata") or _get(obj, "parent", "subscription_details", "metadata")
    )
    line = _subscription_line(obj, plan_price_ids)
    price_id = _line_price_id(line) if line else None
    return InvoiceInfo(
        id=str(obj.get("id") or ""),
        customer_id=_id(obj.get("customer")) or "",
        subscription_id=subscription_id,
        status=str(obj.get("status") or ""),
        billing_reason=obj.get("billing_reason"),
        amount_paid=_int(obj.get("amount_paid")),
        currency=str(obj.get("currency") or ""),
        price_id=price_id,
        period_start=_ts(_get(line, "period", "start")),
        period_end=_ts(_get(line, "period", "end")),
        subscription_metadata=metadata,
    )


def paid_payment_intent_from_invoice_payments(payments: list[dict]) -> str | None:
    """From ``invoice_payments`` list items, the payment intent of the paid one."""
    for item in payments:
        if item.get("status") != "paid":
            continue
        payment = item.get("payment") or {}
        if payment.get("type", "payment_intent") == "payment_intent":
            found = _id(payment.get("payment_intent"))
            if found:
                return found
    return None


# --- the gateway -----------------------------------------------------------------


class StripeSdkGateway:
    def __init__(
        self,
        secret_key: str,
        api_version: str = API_VERSION,
        portal_configuration: str | None = None,
        plan_price_ids: Collection[str] = (),
    ) -> None:
        self._client = stripe.StripeClient(secret_key, stripe_version=api_version, max_network_retries=2)
        self._portal_configuration = portal_configuration or None
        # Price ids of the plans we sell: an invoice line is a plan line only if its price is one of these.
        self._plan_price_ids = frozenset(plan_price_ids)

    # parsing
    def parse_checkout_session(self, obj: dict) -> CheckoutSessionInfo:
        return parse_checkout_session(obj)

    def parse_subscription(self, obj: dict) -> SubscriptionInfo:
        return parse_subscription(obj)

    def parse_charge(self, obj: dict) -> ChargeInfo:
        return parse_charge(obj)

    def parse_dispute(self, obj: dict) -> DisputeInfo:
        return parse_dispute(obj)

    def invoice_snapshot(self, obj: dict) -> InvoiceInfo:
        return parse_invoice(obj, self._plan_price_ids)

    # network
    async def retrieve_subscription(self, subscription_id: str) -> SubscriptionInfo:
        sub = await self._client.v1.subscriptions.retrieve_async(subscription_id)
        return parse_subscription(sub)

    async def invoice_info(self, obj: dict) -> tuple[InvoiceInfo, str | None]:
        info = parse_invoice(obj, self._plan_price_ids)
        payment_intent = _id(obj.get("payment_intent"))
        if payment_intent is None and info.amount_paid > 0:
            listing = await self._client.v1.invoice_payments.list_async({"invoice": info.id, "limit": 20})
            payments = [_plain(p) for p in (_plain(listing).get("data") or [])]
            payment_intent = paid_payment_intent_from_invoice_payments(payments)
        return info, payment_intent

    async def create_customer(self, email: str | None, metadata: dict[str, str], idempotency_key: str) -> str:
        params: dict[str, Any] = {"metadata": dict(metadata)}
        if email:
            params["email"] = email
        customer = await self._client.v1.customers.create_async(
            params=params, options={"idempotency_key": idempotency_key}
        )
        return customer.id

    async def ensure_customer_tag(self, customer_id: str, idempotency_key: str) -> None:
        # Stripe merges metadata keys on update, so other keys are kept.
        await self._client.v1.customers.update_async(
            customer_id,
            params={"metadata": {"lc_app": APP_TAG}},
            options={"idempotency_key": idempotency_key},
        )

    async def create_checkout_session(self, params: dict, idempotency_key: str) -> tuple[str, str]:
        session = await self._client.v1.checkout.sessions.create_async(
            params=params, options={"idempotency_key": idempotency_key}
        )
        return session.id, session.url

    async def create_portal_session(self, customer_id: str, return_url: str) -> str:
        # The configuration is mandatory: without it Stripe would use the account-wide
        # default portal, which the other product on this Stripe account also uses.
        if not self._portal_configuration:
            raise RuntimeError("portal configuration is not set")
        params: dict[str, Any] = {
            "customer": customer_id,
            "return_url": return_url,
            "configuration": self._portal_configuration,
        }
        session = await self._client.v1.billing_portal.sessions.create_async(params=params)
        return session.url
