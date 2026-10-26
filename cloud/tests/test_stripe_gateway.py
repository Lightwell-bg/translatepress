"""Stripe SDK gateway: parsers for older and newer API shapes, request building. No network."""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path

import pytest
import stripe

from app.billing import stripe_gateway as sg

FIXTURES = Path(__file__).parent / "fixtures" / "stripe"


def load(name: str) -> dict:
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


def utc(value: int) -> datetime:
    return datetime.fromtimestamp(value, tz=timezone.utc)


def test_api_version_is_the_sdk_default():
    assert sg.API_VERSION == stripe.api_version
    assert sg.API_VERSION


def test_invoice_old_shape():
    info = sg.parse_invoice(load("invoice_old.json"))
    assert info.id == "in_old_1"
    assert info.customer_id == "cus_old"
    assert info.subscription_id == "sub_old"
    assert info.billing_reason == "subscription_cycle"
    assert info.amount_paid == 900
    assert info.price_id == "price_starter_x"  # the proration line is skipped
    assert info.period_start == utc(1760000000) and info.period_end == utc(1762592000)
    assert info.period_start.tzinfo is timezone.utc
    assert info.subscription_metadata == {"lc_app": "lingocanvas", "lc_account_id": "7"}


def test_invoice_new_shape():
    info = sg.parse_invoice(load("invoice_new.json"))
    assert info.subscription_id == "sub_new"
    assert info.price_id == "price_starter_x"
    assert info.period_start == utc(1760000000) and info.period_end == utc(1762592000)
    assert info.subscription_metadata == {"lc_app": "lingocanvas", "lc_claim_id": "lcc_claim_abc"}


@pytest.mark.parametrize("fixture", ["invoice_multiline_old.json", "invoice_multiline_new.json"])
def test_invoice_line_selection_skips_prorations_other_kinds_and_unknown_prices(fixture):
    known = {"price_starter_x"}
    info = sg.parse_invoice(load(fixture), known)
    # Only the real plan line counts: not the proration (first), not the invoice item, not the other product's price.
    assert info.price_id == "price_starter_x"
    assert info.period_start == utc(1760000000) and info.period_end == utc(1762592000)


@pytest.mark.parametrize("fixture", ["invoice_multiline_old.json", "invoice_multiline_new.json"])
def test_invoice_without_a_known_plan_line_has_no_price(fixture):
    info = sg.parse_invoice(load(fixture), {"price_unrelated"})
    assert info.price_id is None and info.period_start is None and info.period_end is None
    # With no known prices configured at all nothing can match either.
    assert sg.parse_invoice(load(fixture), frozenset()).price_id is None


@pytest.mark.parametrize("fixture", ["invoice_multiline_old.json", "invoice_multiline_new.json"])
def test_only_proration_lines_leave_no_plan_line(fixture):
    raw = load(fixture)
    raw["lines"]["data"] = raw["lines"]["data"][:1]  # just the proration line
    assert sg.parse_invoice(raw, {"price_starter_x"}).price_id is None


async def test_gateway_passes_the_plan_prices_to_the_invoice_parser():
    gw = _gateway(_client(sessions=_Svc(), portal=_Svc(), payments=_Svc()), plan_price_ids=("price_unrelated",))
    assert gw.invoice_snapshot(load("invoice_multiline_old.json")).price_id is None
    info, _ = await gw.invoice_info(load("invoice_multiline_old.json"))
    assert info.price_id is None
    gw2 = _gateway(_client(sessions=_Svc(), portal=_Svc(), payments=_Svc()))
    assert gw2.invoice_snapshot(load("invoice_multiline_new.json")).period_end == utc(1762592000)


def test_invoice_without_subscription_is_not_a_subscription_invoice():
    info = sg.parse_invoice({"id": "in_x", "customer": "cus_x", "lines": {"data": []}})
    assert info.subscription_id is None and info.price_id is None and info.period_end is None


def test_subscription_old_shape_period_on_the_subscription():
    sub = sg.parse_subscription(load("subscription_old.json"))
    assert sub.id == "sub_old" and sub.customer_id == "cus_old" and sub.status == "active"
    assert sub.price_id == "price_starter_x"
    assert sub.current_period_start == utc(1760000000) and sub.current_period_end == utc(1762592000)
    assert sub.cancel_at_period_end is True
    assert sub.metadata["lc_app"] == "lingocanvas"


def test_subscription_new_shape_period_on_the_item_and_expanded_customer():
    sub = sg.parse_subscription(load("subscription_new.json"))
    assert sub.customer_id == "cus_new" and sub.status == "trialing"
    assert sub.current_period_end == utc(1762592000)
    assert sub.price_id == "price_starter_x"
    assert sub.cancel_at_period_end is False


def test_checkout_session_payment_mode():
    info = sg.parse_checkout_session(load("checkout_session_payment.json"))
    assert info.mode == "payment" and info.payment_status == "unpaid"
    assert info.payment_intent_id == "pi_pack_1" and info.subscription_id is None
    assert info.customer_email == "buyer@example.com"
    assert info.price_id == "price_pack_x"  # from our metadata: webhook payloads have no line items
    assert info.created == utc(1760000500)
    assert info.amount_total == 700


def test_checkout_session_prefers_expanded_line_items():
    raw = load("checkout_session_payment.json")
    raw["line_items"] = {"data": [{"price": {"id": "price_from_lines"}}]}
    assert sg.parse_checkout_session(raw).price_id == "price_from_lines"


def test_foreign_objects_with_missing_fields_do_not_raise():
    assert sg.parse_checkout_session({"id": "cs_x", "metadata": {"user_id": "u", "product": "vira"}}).mode == ""
    assert sg.parse_subscription({"id": "sub_x"}).price_id is None
    assert sg.parse_charge({"id": "ch_x"}).amount_refunded == 0
    assert sg.parse_dispute({"id": "dp_x"}).charge_id is None


def test_charge_and_dispute_expanded_references():
    assert sg.parse_charge({"id": "ch_1", "payment_intent": {"id": "pi_1"}, "amount": 5, "amount_refunded": 2}).payment_intent_id == "pi_1"
    d = sg.parse_dispute({"id": "dp_1", "charge": {"id": "ch_9"}, "payment_intent": "pi_9", "status": "won"})
    assert (d.charge_id, d.payment_intent_id, d.status) == ("ch_9", "pi_9", "won")


def test_paid_payment_intent_from_invoice_payments():
    payments = [
        {"status": "open", "payment": {"type": "payment_intent", "payment_intent": "pi_open"}},
        {"status": "paid", "payment": {"type": "payment_intent", "payment_intent": "pi_paid"}},
    ]
    assert sg.paid_payment_intent_from_invoice_payments(payments) == "pi_paid"
    assert sg.paid_payment_intent_from_invoice_payments([]) is None


# --- request building with a stub SDK client -------------------------------------------


class _Svc:
    def __init__(self, result=None):
        self.result = result
        self.args: list = []

    async def create_async(self, params=None, options=None):
        self.args.append((params, options))
        return self.result

    async def list_async(self, params=None):
        self.args.append((params, None))
        return self.result


class _Obj:
    def __init__(self, **kw):
        self.__dict__.update(kw)


def _gateway(client, portal_configuration=None, plan_price_ids=("price_starter_x",)) -> sg.StripeSdkGateway:
    gw = sg.StripeSdkGateway.__new__(sg.StripeSdkGateway)
    gw._client = client
    gw._portal_configuration = portal_configuration
    gw._plan_price_ids = frozenset(plan_price_ids)
    return gw


def _client(**services):
    v1 = _Obj(
        checkout=_Obj(sessions=services["sessions"]),
        billing_portal=_Obj(sessions=services["portal"]),
        invoice_payments=services["payments"],
    )
    return _Obj(v1=v1)


async def test_create_checkout_session_passes_params_and_idempotency_key():
    sessions = _Svc(_Obj(id="cs_1", url="https://checkout.stripe.test/c/1"))
    gw = _gateway(_client(sessions=sessions, portal=_Svc(), payments=_Svc()))
    assert await gw.create_checkout_session({"mode": "payment"}, "idem-1") == ("cs_1", "https://checkout.stripe.test/c/1")
    assert sessions.args == [({"mode": "payment"}, {"idempotency_key": "idem-1"})]


async def test_portal_session_always_passes_the_configuration_and_refuses_without_one():
    portal = _Svc(_Obj(url="https://billing.stripe.test/p/1"))
    gw = _gateway(_client(sessions=_Svc(), portal=portal, payments=_Svc()), portal_configuration="bpc_123")
    assert await gw.create_portal_session("cus_1", "https://x.test/back") == "https://billing.stripe.test/p/1"
    assert portal.args[-1][0] == {"customer": "cus_1", "return_url": "https://x.test/back", "configuration": "bpc_123"}

    # No fallback to the account-wide default portal (shared with the other product).
    calls_before = len(portal.args)
    plain = _gateway(_client(sessions=_Svc(), portal=portal, payments=_Svc()))
    with pytest.raises(RuntimeError):
        await plain.create_portal_session("cus_1", "https://x.test/back")
    assert len(portal.args) == calls_before


async def test_invoice_info_uses_payment_intent_field_without_a_call():
    payments = _Svc({"data": []})
    gw = _gateway(_client(sessions=_Svc(), portal=_Svc(), payments=payments))
    info, pi = await gw.invoice_info(load("invoice_old.json"))
    assert pi == "pi_old" and info.id == "in_old_1"
    assert payments.args == []


async def test_invoice_info_lists_invoice_payments_for_the_new_shape():
    payments = _Svc({"data": [{"status": "paid", "payment": {"type": "payment_intent", "payment_intent": "pi_new"}}]})
    gw = _gateway(_client(sessions=_Svc(), portal=_Svc(), payments=payments))
    info, pi = await gw.invoice_info(load("invoice_new.json"))
    assert pi == "pi_new"
    assert payments.args[0][0]["invoice"] == "in_new_1"


async def test_invoice_info_zero_amount_needs_no_lookup():
    payments = _Svc({"data": []})
    gw = _gateway(_client(sessions=_Svc(), portal=_Svc(), payments=payments))
    raw = load("invoice_new.json")
    raw["amount_paid"] = 0
    _, pi = await gw.invoice_info(raw)
    assert pi is None and payments.args == []
