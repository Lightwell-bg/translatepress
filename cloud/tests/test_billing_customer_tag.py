"""Invariant: every Stripe customer we charge carries metadata lc_app=lingocanvas (re-applied before account-level sessions)."""

from __future__ import annotations

from tests.conftest import auth


def _order(gw, *names):
    seen = [c for c in gw.calls if c in names]
    return seen


async def test_pack_checkout_tags_customer_first(bill):
    info = await bill.paid_account()
    bill.gw.calls.clear()
    bill.gw.tag_calls.clear()
    r = await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(info["token"]))
    assert r.status_code == 200
    assert [c[0] for c in bill.gw.tag_calls] == ["cus_1"] and bill.gw.tag_calls[0][1].startswith("lc-tag:cus_1:")
    assert _order(bill.gw, "ensure_customer_tag", "create_checkout_session") == ["ensure_customer_tag", "create_checkout_session"]


async def test_starter_resubscribe_tags_customer_first(bill, make_account, make_site):
    account_id = await make_account(plan=None)
    _, token = await make_site(account_id)
    await bill.execute("UPDATE accounts SET stripe_customer_id = 'cus_returning' WHERE id = :a", a=account_id)
    bill.gw.calls.clear()
    r = await bill.client.post("/v1/billing/checkout", json={"product": "starter"}, headers=auth(token))
    assert r.status_code == 200
    assert [c[0] for c in bill.gw.tag_calls] == ["cus_returning"]
    assert _order(bill.gw, "ensure_customer_tag", "create_checkout_session") == ["ensure_customer_tag", "create_checkout_session"]


async def test_portal_tags_customer_first(bill):
    info = await bill.paid_account()
    bill.gw.calls.clear()
    r = await bill.client.post("/v1/billing/portal", headers=auth(info["token"]))
    assert r.status_code == 200
    assert [c[0] for c in bill.gw.tag_calls] == ["cus_1"]
    assert _order(bill.gw, "ensure_customer_tag", "create_portal_session") == ["ensure_customer_tag", "create_portal_session"]


async def test_tag_failure_is_502_and_no_session(bill, make_account, make_site):
    info = await bill.paid_account()
    bill.gw.fail_tag = True
    bill.gw.checkout_calls.clear()
    bill.gw.portal_calls.clear()
    pack = await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(info["token"]))
    portal = await bill.client.post("/v1/billing/portal", headers=auth(info["token"]))

    account_id = await make_account(plan=None)
    _, token = await make_site(account_id)
    await bill.execute("UPDATE accounts SET stripe_customer_id = 'cus_returning' WHERE id = :a", a=account_id)
    starter = await bill.client.post("/v1/billing/checkout", json={"product": "starter"}, headers=auth(token))

    for r in (pack, portal, starter):
        assert r.status_code == 502 and r.json()["error"]["code"] == "billing_provider_error"
        assert "sk_test_leak" not in r.text
    assert bill.gw.checkout_calls == [] and bill.gw.portal_calls == []
