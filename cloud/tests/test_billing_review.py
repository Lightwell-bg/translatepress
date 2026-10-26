"""Stage 4 review fixes: refunds/disputes before the payment, reserved refunds, snapshot race,
free invoices, dispute hold, one open Starter checkout, portal configuration.

Everything goes through the real signed webhook and the real HTTP routes (fake Stripe gateway).
"""

from __future__ import annotations

import asyncio
from dataclasses import replace
from datetime import timedelta

from app.billing import events
from app.sweeper import prune_once
from tests import billing_fakes as bf
from tests.billing_fakes import STRIPE_SETTINGS, FakeGateway, event, utcnow
from tests.conftest import auth
from tests.helpers import new_rid, reserve, settle

LC = {"lc_app": "lingocanvas"}


def refund(refunded: int, *, ch="ch_1", pi="pi_1", amount=900) -> dict:
    return event("charge.refunded", bf.charge(id=ch, pi=pi, amount=amount, refunded=refunded))


async def _remaining(bill, token: str) -> int:
    r = await bill.client.get("/v1/account", headers=auth(token))
    return r.json()["quota"]["monthly_remaining"]


async def _pending_claim(bill):
    """A sign-up whose subscription exists at Stripe but whose payment has not arrived yet."""
    claim_id, secret, session_id = await bill.signup()
    start = utcnow() - timedelta(days=1)
    end = start + timedelta(days=30)
    meta = {**LC, "lc_claim_id": claim_id}
    bill.gw.set_subscription(bf.sub_info(start=start, end=end, metadata=meta))
    return claim_id, secret, session_id, meta, start, end


# --- a. refund / dispute BEFORE the payment is recorded -------------------------------------------------


async def test_full_refund_before_the_invoice_is_parked_then_closes_the_bucket(bill):
    await bill.send_ok(refund(900))
    assert (await bill.counts("payments", "unmatched_charge_events")) == {"payments": 0, "unmatched_charge_events": 1}

    await bill.first_payment()
    account_id = await bill.scalar("SELECT id AS v FROM accounts")
    bucket = (await bill.buckets(account_id))[0]
    assert bucket["granted"] == 0 and bucket["revoked_at"] is not None
    assert await bill.one("SELECT refunded_cents, revoked_chars FROM payments") == {
        "refunded_cents": 900, "revoked_chars": 100000,
    }
    assert (await bill.scalar("SELECT applied_at IS NOT NULL AS v FROM unmatched_charge_events")) is True


async def test_partial_refund_before_the_invoice_is_applied_proportionally(bill):
    await bill.send_ok(refund(450))
    await bill.first_payment()
    account_id = await bill.scalar("SELECT id AS v FROM accounts")
    bucket = (await bill.buckets(account_id))[0]
    assert bucket["granted"] == 50000 and bucket["revoked_at"] is None
    assert await bill.one("SELECT refunded_cents, revoked_chars FROM payments") == {
        "refunded_cents": 450, "revoked_chars": 50000,
    }


async def test_refund_before_a_pack_payment_is_reconciled(bill):
    info = await bill.paid_account()
    await bill.send_ok(refund(700, ch="ch_pack", pi="pi_pack_1", amount=700))
    await bill.send_ok(bill.pack_session_event(info["account_id"]))
    pack = [b for b in await bill.buckets(info["account_id"]) if b["kind"] == "pack"][0]
    assert pack["granted"] == 0 and pack["revoked_at"] is not None
    # The subscription payment is untouched.
    assert [b for b in await bill.buckets(info["account_id"]) if b["kind"] == "monthly"][0]["granted"] == 100000


async def test_dispute_before_the_invoice_puts_the_hold_on_when_the_payment_appears(bill):
    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    assert (await bill.counts("accounts", "disputes")) == {"accounts": 0, "disputes": 0}  # parked

    info = await bill.first_payment()
    assert await bill.one("SELECT status, dispute_hold FROM accounts") == {"status": "active", "dispute_hold": True}
    assert (await bill.scalar("SELECT disputed AS v FROM payments")) is True
    account_id = await bill.scalar("SELECT id AS v FROM accounts")
    assert (await bill.buckets(account_id))[0]["revoked_at"] is not None
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 403 and r.json()["error"]["code"] == "account_suspended" and "token" not in r.text
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 0


async def test_prune_parked_removes_only_old_rows(bill):
    await bill.send_ok(refund(100, ch="ch_old", pi="pi_old"))
    await bill.send_ok(refund(100, ch="ch_new", pi="pi_new"))
    await bill.execute("UPDATE unmatched_charge_events SET created_at = now() - interval '100 days' WHERE charge_id = 'ch_old'")
    async with bill.sm() as s, s.begin():
        assert await events.prune_parked(s, utcnow(), 90) == 1
    assert [r["charge_id"] for r in await bill.q("SELECT charge_id FROM unmatched_charge_events")] == ["ch_new"]


async def test_sweeper_prune_once_uses_its_own_transaction(bill):
    await bill.send_ok(refund(100, ch="ch_old", pi="pi_old"))
    await bill.execute("UPDATE unmatched_charge_events SET created_at = now() - interval '91 days'")
    assert await prune_once(bill.sm, 90) == 1
    assert (await bill.counts("unmatched_charge_events"))["unmatched_charge_events"] == 0
    assert await prune_once(bill.sm, 90) == 0


# --- b. refund while the characters are reserved ---------------------------------------------------------


async def _reserve_all(bill):
    info = await bill.paid_account()
    res = await reserve(bill.sm, info["account_id"], info["site_id"], new_rid(), 100000)
    return info, res


async def test_partial_refund_while_reserved_is_deferred_until_release(bill):
    info, res = await _reserve_all(bill)
    account_id = info["account_id"]

    await bill.send_ok(refund(450))  # 50 %
    b = (await bill.buckets(account_id))[0]
    assert (b["granted"], b["reserved"], b["revoke_pending"]) == (100000, 100000, 50000)
    assert await _remaining(bill, info["token"]) == 0
    r = await bill.translate(info["token"])
    assert r.status_code == 402  # nothing spendable during the reservation

    # The provider failed: the reservation is released and the refunded half does not come back.
    await settle(bill.sm, account_id, res.reservation_id, 0, attempt=res.attempt)
    b = (await bill.buckets(account_id))[0]
    assert (b["granted"], b["used"], b["reserved"], b["revoke_pending"]) == (50000, 0, 0, 0)
    assert await _remaining(bill, info["token"]) == 50000
    assert (await bill.scalar("SELECT count(*) AS v FROM ledger WHERE kind = 'revoke'")) == 1

    # A second, larger partial refund works on top of the first.
    await bill.send_ok(refund(675))  # 75 % in total
    b = (await bill.buckets(account_id))[0]
    assert (b["granted"], b["revoke_pending"]) == (25000, 0)
    assert await bill.one("SELECT refunded_cents, revoked_chars FROM payments") == {
        "refunded_cents": 675, "revoked_chars": 75000,
    }
    assert await _remaining(bill, info["token"]) == 25000


async def test_two_partial_refunds_while_reserved_accumulate(bill):
    info, res = await _reserve_all(bill)
    await bill.send_ok(refund(450))
    await bill.send_ok(refund(675))
    b = (await bill.buckets(info["account_id"]))[0]
    assert (b["granted"], b["reserved"], b["revoke_pending"]) == (100000, 100000, 75000)
    await settle(bill.sm, info["account_id"], res.reservation_id, 0, attempt=res.attempt)
    b = (await bill.buckets(info["account_id"]))[0]
    assert (b["granted"], b["reserved"], b["revoke_pending"]) == (25000, 0, 0)


async def test_refund_while_reserved_then_charged_caps_the_pending_without_a_check_violation(bill):
    info, res = await _reserve_all(bill)
    await bill.send_ok(refund(450))
    assert (await bill.buckets(info["account_id"]))[0]["revoke_pending"] == 50000

    out = await settle(bill.sm, info["account_id"], res.reservation_id, 100000, attempt=res.attempt)
    assert out.settled and out.billed == 100000
    b = (await bill.buckets(info["account_id"]))[0]
    # The characters were legitimately spent before the refund took effect.
    assert (b["granted"], b["used"], b["reserved"], b["revoke_pending"]) == (100000, 100000, 0, 0)
    assert await _remaining(bill, info["token"]) == 0


# --- c. subscription snapshot race ------------------------------------------------------------------------


async def test_concurrent_events_for_one_subscription_end_with_the_snapshot_read_last(bill):
    await bill.paid_account()
    active = bill.gw.subscriptions["sub_1"]
    canceled = replace(active, status="canceled")
    gate = asyncio.Event()
    # The first retrieve answers "active" and is then held up; the second would answer "canceled".
    bill.gw.retrieve_script = [(active, gate), (canceled, None)]
    n0 = bill.gw.calls.count("retrieve_subscription")

    e1 = event("customer.subscription.updated", bf.subscription_obj(metadata=LC))
    e2 = event("customer.subscription.deleted", bf.subscription_obj(status="canceled", metadata=LC))
    t1 = asyncio.create_task(bill.send(e1))
    for _ in range(200):
        if bill.gw.calls.count("retrieve_subscription") == n0 + 1:
            break
        await asyncio.sleep(0.05)
    assert bill.gw.calls.count("retrieve_subscription") == n0 + 1

    t2 = asyncio.create_task(bill.send(e2))
    waiting = 0
    for _ in range(200):
        waiting = await bill.scalar(
            "SELECT count(*) AS v FROM pg_locks WHERE locktype = 'advisory' AND NOT granted"
        )
        if waiting:
            break
        await asyncio.sleep(0.05)
    # The second event is parked on the per-subscription lock and has NOT read the subscription yet.
    assert waiting == 1
    assert bill.gw.calls.count("retrieve_subscription") == n0 + 1
    assert not t2.done()

    gate.set()
    r1, r2 = await asyncio.gather(t1, t2)
    assert r1.status_code == r2.status_code == 200
    assert bill.gw.calls.count("retrieve_subscription") == n0 + 2
    assert (await bill.scalar("SELECT status AS v FROM subscriptions")) == "canceled"


# --- d. free / foreign-currency invoices grant nothing ----------------------------------------------------------


async def test_zero_amount_and_non_eur_invoices_grant_nothing_and_keep_the_claim_open(bill):
    claim_id, secret, _, meta, start, end = await _pending_claim(bill)

    await bill.send_ok(event("invoice.paid", bf.invoice(id="in_free", amount=0, start=start, end=end, metadata=meta)))
    await bill.send_ok(event("invoice.paid", bf.invoice(id="in_usd", currency="usd", start=start, end=end, metadata=meta)))
    assert (await bill.counts("quota_buckets", "payments")) == {"quota_buckets": 0, "payments": 0}
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "open"
    r = await bill.claim(claim_id, secret)
    assert r.status_code == 202 and r.json() == {"status": "pending"} and "token" not in r.text

    # The real payment still works afterwards and only then unlocks the claim.
    await bill.send_ok(event("invoice.paid", bf.invoice(id="in_real", start=start, end=end, metadata=meta)))
    assert (await bill.counts("quota_buckets", "payments")) == {"quota_buckets": 1, "payments": 1}
    assert (await bill.claim(claim_id, secret)).status_code == 200


async def test_free_pack_checkout_grants_nothing(bill):
    info = await bill.paid_account()
    await bill.send_ok(bill.pack_session_event(info["account_id"], amount=0))
    assert [b["kind"] for b in await bill.buckets(info["account_id"])] == ["monthly"]
    assert (await bill.counts("payments"))["payments"] == 1


# --- e. a claim becomes ready only through a paid invoice ---------------------------------------------------------


async def test_subscription_created_and_checkout_completed_do_not_unlock_the_claim(bill):
    claim_id, secret, session_id, meta, start, end = await _pending_claim(bill)

    await bill.send_ok(event("customer.subscription.created", bf.subscription_obj(metadata=meta, start=start, end=end)))
    await bill.send_ok(event("checkout.session.completed", bf.checkout_session(id=session_id, metadata=meta)))
    assert (await bill.counts("subscriptions", "quota_buckets"))["quota_buckets"] == 0
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "open"
    r = await bill.claim(claim_id, secret)
    assert r.status_code == 202 and r.json() == {"status": "pending"} and "token" not in r.text
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 0


# --- f. disputes -----------------------------------------------------------------------------------------------------


async def test_won_dispute_processed_before_an_older_created_event_stays_cleared(bill):
    info = await bill.paid_account()
    await bill.send_ok(event("charge.dispute.closed", bf.dispute(status="won")))
    await bill.send_ok(event("charge.dispute.created", bf.dispute(status="needs_response")))  # older, arrives late
    assert await bill.one("SELECT status, terminal FROM disputes") == {"status": "won", "terminal": True}
    assert (await bill.scalar("SELECT dispute_hold AS v FROM accounts")) is False
    b = (await bill.buckets(info["account_id"]))[0]
    assert b["granted"] == 100000 and b["revoked_at"] is None
    assert (await bill.translate(info["token"])).status_code == 200


async def test_open_dispute_blocks_translate_claim_and_pair_and_revokes_unused(bill):
    paid = await bill.paid_account()
    await bill.execute("UPDATE accounts SET plan = 'pro'")  # room for a second site
    code = (await bill.client.post("/v1/sites/pair-code", headers=auth(paid["token"]))).json()["code"]

    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    assert (await bill.scalar("SELECT dispute_hold AS v FROM accounts")) is True
    b = (await bill.buckets(paid["account_id"]))[0]
    assert b["granted"] == 0 and b["revoked_at"] is not None  # nothing was used, everything unused is taken back

    r = await bill.translate(paid["token"])
    assert r.status_code == 403 and r.json()["error"]["code"] == "account_suspended"
    assert (await bill.client.post("/v1/sites/pair-code", headers=auth(paid["token"]))).status_code == 403
    paired = await bill.client.post("/v1/sites/pair", json={"code": code, "site_name": "second"})
    assert paired.status_code == 403 and paired.json()["error"]["code"] == "account_suspended"
    assert "token" not in paired.text
    assert (await bill.scalar("SELECT used_at IS NULL AS v FROM pair_codes")) is True  # the code stays usable later


async def test_lost_dispute_keeps_the_hold_and_dispute_updated_cannot_regress_a_final_state(bill):
    await bill.paid_account()
    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    await bill.send_ok(event("charge.dispute.updated", bf.dispute(status="under_review")))
    assert (await bill.scalar("SELECT status AS v FROM disputes")) == "under_review"
    await bill.send_ok(event("charge.dispute.closed", bf.dispute(status="lost")))
    assert (await bill.scalar("SELECT dispute_hold AS v FROM accounts")) is True

    # A stale non-terminal update after the final decision changes nothing.
    await bill.send_ok(event("charge.dispute.updated", bf.dispute(status="needs_response")))
    assert await bill.one("SELECT status, terminal FROM disputes") == {"status": "lost", "terminal": True}
    assert (await bill.scalar("SELECT dispute_hold AS v FROM accounts")) is True


async def test_won_dispute_does_not_reactivate_an_administrator_suspension(bill):
    info = await bill.paid_account()
    await bill.execute("UPDATE accounts SET status = 'suspended'")
    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    await bill.send_ok(event("charge.dispute.closed", bf.dispute(status="won")))
    assert await bill.one("SELECT status, dispute_hold FROM accounts") == {"status": "suspended", "dispute_hold": False}
    assert (await bill.translate(info["token"])).status_code == 403


async def test_dispute_updated_non_terminal_after_won_is_ignored(bill):
    await bill.paid_account()
    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    await bill.send_ok(event("charge.dispute.closed", bf.dispute(status="won")))
    await bill.send_ok(event("charge.dispute.updated", bf.dispute(status="under_review")))
    assert await bill.one("SELECT status, terminal FROM disputes") == {"status": "won", "terminal": True}
    assert (await bill.scalar("SELECT dispute_hold AS v FROM accounts")) is False


# --- g. one open Starter checkout per account -----------------------------------------------------------------------------


async def _customer_account(bill, make_account, name="cus_checkout"):
    account_id = await make_account(plan=None)
    await bill.execute("UPDATE accounts SET stripe_customer_id = :c WHERE id = :a", c=name, a=account_id)
    _, token = await bill.make_site(account_id)
    return account_id, token


async def _starter(bill, token):
    return await bill.client.post("/v1/billing/checkout", json={"product": "starter"}, headers=auth(token))


async def test_concurrent_starter_checkouts_create_exactly_one_session(bill, make_account):
    account_id, token = await _customer_account(bill, make_account)
    bill.gw.checkout_delay = 0.3  # the requests overlap while Stripe "works"

    responses = await asyncio.gather(*[_starter(bill, token) for _ in range(5)])
    assert [r.status_code for r in responses] == [200] * 5, [r.text for r in responses]
    assert len({r.json()["checkout_url"] for r in responses}) == 1
    assert len(bill.gw.checkout_calls) == 1
    row = await bill.one(
        "SELECT pending_checkout_session_id AS sid, pending_checkout_url AS url, "
        "pending_checkout_expires_at > now() AS fresh FROM accounts WHERE id = :a", a=account_id,
    )
    assert row["sid"] == "cs_test_1" and row["url"] == responses[0].json()["checkout_url"] and row["fresh"] is True

    # A later request still reuses the open session.
    again = await _starter(bill, token)
    assert again.json()["checkout_url"] == responses[0].json()["checkout_url"]
    assert len(bill.gw.checkout_calls) == 1


async def test_expired_pending_checkout_is_replaced(bill, make_account):
    account_id, token = await _customer_account(bill, make_account)
    first = (await _starter(bill, token)).json()["checkout_url"]
    await bill.execute("UPDATE accounts SET pending_checkout_expires_at = now() - interval '1 minute'")
    second = await _starter(bill, token)
    assert second.status_code == 200 and second.json()["checkout_url"] != first
    assert len(bill.gw.checkout_calls) == 2
    assert (await bill.scalar("SELECT pending_checkout_session_id AS v FROM accounts")) == "cs_test_2"


async def test_pending_checkout_is_cleared_once_a_live_subscription_exists(bill, make_account):
    account_id, token = await _customer_account(bill, make_account)
    assert (await _starter(bill, token)).status_code == 200
    await bill.execute(
        "INSERT INTO subscriptions (account_id, stripe_subscription_id, plan, status, current_period_end) "
        "VALUES (:a, 'sub_paid', 'starter', 'active', now() + interval '10 days')", a=account_id,
    )
    r = await _starter(bill, token)
    assert r.status_code == 409 and r.json()["error"]["code"] == "already_subscribed"
    assert await bill.one(
        "SELECT pending_checkout_session_id AS sid, pending_checkout_url AS url, pending_checkout_expires_at AS exp FROM accounts"
    ) == {"sid": None, "url": None, "exp": None}
    assert len(bill.gw.checkout_calls) == 1


async def test_failed_session_creation_stores_no_pending_checkout(bill, make_account):
    account_id, token = await _customer_account(bill, make_account)
    bill.gw.fail_checkout = True
    r = await _starter(bill, token)
    assert r.status_code == 502 and r.json()["error"]["code"] == "billing_provider_error"
    assert (await bill.scalar("SELECT pending_checkout_url AS v FROM accounts")) is None
    bill.gw.fail_checkout = False
    assert (await _starter(bill, token)).status_code == 200


# --- h. invoice line selection end to end ---------------------------------------------------------------------------------


async def test_invoice_with_a_proration_line_first_grants_for_the_plan_line_period(bill):
    claim_id, secret, session_id, meta, start, end = await _pending_claim(bill)
    obj = bf.invoice(id="in_multi", start=start, end=end, metadata=meta, shape="new")
    bill.gw.invoice_payment_intents["in_multi"] = "pi_multi"
    plan_line = obj["lines"]["data"][0]
    wrong = {"start": bf.ts(start - timedelta(days=400)), "end": bf.ts(start - timedelta(days=399))}
    proration = {
        "pricing": {"price_details": {"price": bf.PRICE_STARTER}}, "period": wrong,
        "parent": {"type": "subscription_item_details", "subscription_item_details": {"proration": True}},
    }
    obj["lines"]["data"] = [proration, plan_line]
    await bill.send_ok(event("invoice.paid", obj))
    b = (await bill.buckets(await bill.scalar("SELECT id AS v FROM accounts")))[0]
    assert b["valid_from"] == start and b["expires_at"] == end and b["granted"] == 100000


async def test_invoice_without_a_known_plan_line_fails_so_stripe_retries(bill):
    claim_id, secret, session_id, meta, start, end = await _pending_claim(bill)
    obj = bf.invoice(id="in_unknown", start=start, end=end, metadata=meta, price="price_not_ours")
    r = await bill.send(event("invoice.paid", obj))
    assert r.status_code == 500 and r.json()["error"]["code"] == "internal_error"
    assert (await bill.counts("quota_buckets", "payments", "stripe_events")) == {
        "quota_buckets": 0, "payments": 0, "stripe_events": 0,
    }


# --- i. portal needs its own configuration -----------------------------------------------------------------------------------


async def test_portal_without_configuration_is_503_and_never_calls_the_gateway(make_client, make_account, make_site):
    gw = FakeGateway()
    client, _ = await make_client(gateway=gw, stripe={**STRIPE_SETTINGS, "stripe_portal_configuration": ""})
    account_id = await make_account(plan="starter")
    _, token = await make_site(account_id)
    r = await client.post("/v1/billing/portal", headers=auth(token))
    assert r.status_code == 503 and r.json()["error"]["code"] == "portal_not_configured"
    assert gw.portal_calls == [] and gw.network_calls == 0
