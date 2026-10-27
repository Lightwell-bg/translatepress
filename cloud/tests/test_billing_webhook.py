"""Stripe webhook processing through the real endpoint (signed requests, fake gateway)."""

from __future__ import annotations

import asyncio
from datetime import timedelta

import pytest

from app.billing.events import add_months
from tests import billing_fakes as bf
from tests.billing_fakes import PRICE_PACK, PRICE_STARTER, event, utcnow
from tests.conftest import auth

LC = {"lc_app": "lingocanvas"}


# --- a. first payment, both orders ---------------------------------------------------------


@pytest.mark.parametrize("order", ["checkout_first", "invoice_first"])
@pytest.mark.parametrize("shape", ["old", "new"])
async def test_first_payment_any_order_one_account_one_bucket(bill, order, shape):
    info = await bill.first_payment(order=order, shape=shape, email="buyer@example.com")

    assert (await bill.counts("accounts"))["accounts"] == 1
    account = await bill.one("SELECT id, plan, stripe_customer_id, email, name FROM accounts")
    assert account["plan"] == "starter" and account["stripe_customer_id"] == "cus_1"
    assert account["name"] == "Мой сайт example.com"  # user content stored exactly as entered

    buckets = await bill.buckets(account["id"])
    assert len(buckets) == 1
    b = buckets[0]
    assert (b["kind"], b["granted"], b["source_ref"]) == ("monthly", 100000, "stripe_invoice:in_1")
    assert b["valid_from"] == info["start"] and b["expires_at"] == info["end"]  # from the invoice line

    claim = await bill.one("SELECT status, account_id FROM checkout_claims WHERE id = :i", i=info["claim_id"])
    assert claim == {"status": "paid", "account_id": account["id"]}
    payment = await bill.one("SELECT kind, amount_cents, stripe_payment_intent_id AS pi, granted_chars FROM payments")
    assert payment == {"kind": "subscription", "amount_cents": 900, "pi": "pi_1", "granted_chars": 100000}
    sub = await bill.one("SELECT status, plan FROM subscriptions")
    assert sub == {"status": "active", "plan": "starter"}


async def test_claim_pickup_returns_token_once_and_token_translates(bill):
    info = await bill.first_payment()
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 200
    data = r.json()
    assert data["token"].startswith("lcc_") and data["site_id"] > 0
    assert (await bill.one("SELECT name AS v FROM sites"))["v"] == "Мой сайт example.com"

    confirm = await bill.client.post(
        "/v1/billing/claim/confirm", json={"claim_id": info["claim_id"]}, headers=auth(data["token"])
    )
    assert confirm.status_code == 200
    again = await bill.claim(info["claim_id"], info["claim_secret"])
    assert again.status_code == 409 and again.json()["error"]["code"] == "already_claimed"
    assert "token" not in again.text

    t = await bill.translate(data["token"])
    assert t.status_code == 200
    assert t.json()["billed_chars"] == 10
    acc = await bill.client.get("/v1/account", headers=auth(data["token"]))
    body = acc.json()
    assert body["subscription"]["status"] == "active" and body["subscription"]["cancel_at_period_end"] is False
    assert body["quota"]["monthly_remaining"] == 100000 - 10
    assert body["account"]["plan"] == "starter"


# --- b. renewal -----------------------------------------------------------------------------------


async def test_renewal_creates_second_bucket_and_first_keeps_its_end(bill):
    info = await bill.paid_account()
    start2, end2 = info["end"], info["end"] + timedelta(days=30)
    bill.gw.update_subscription("sub_1", current_period_start=start2, current_period_end=end2)
    await bill.send_ok(
        event("invoice.paid", bf.invoice(id="in_2", reason="subscription_cycle", pi="pi_2", start=start2, end=end2, metadata=LC))
    )
    buckets = await bill.buckets(info["account_id"])
    assert [(b["granted"], b["source_ref"]) for b in buckets] == [
        (100000, "stripe_invoice:in_1"),
        (100000, "stripe_invoice:in_2"),
    ]
    assert buckets[0]["expires_at"] == info["end"]  # the old period ends exactly at its own end
    assert buckets[1]["valid_from"] == start2 and buckets[1]["expires_at"] == end2
    assert (await bill.scalar("SELECT current_period_end AS v FROM subscriptions")) == end2
    # The future bucket is not spendable yet.
    acc = await bill.client.get("/v1/account", headers=auth(info["token"]))
    assert acc.json()["quota"]["monthly_remaining"] == 100000


# --- c. failed renewal ----------------------------------------------------------------------------


async def test_failed_renewal_marks_past_due_without_a_grant(bill):
    info = await bill.paid_account()
    bill.gw.update_subscription("sub_1", status="past_due")
    failed = bf.invoice(id="in_3", reason="subscription_cycle", status="open", pi=None, metadata=LC)
    await bill.send_ok(event("invoice.payment_failed", failed))
    assert (await bill.scalar("SELECT status AS v FROM subscriptions")) == "past_due"
    assert len(await bill.buckets(info["account_id"])) == 1
    assert (await bill.counts("payments"))["payments"] == 1


# --- d. cancellation ------------------------------------------------------------------------------


async def test_cancel_keeps_current_period_then_stops(bill, grant_pack, q):
    info = await bill.paid_account()
    bill.gw.update_subscription("sub_1", cancel_at_period_end=True)
    await bill.send_ok(event("customer.subscription.updated", bf.subscription_obj(cancel=True, metadata=LC)))
    row = await bill.one("SELECT status, cancel_at_period_end AS c FROM subscriptions")
    assert row == {"status": "active", "c": True}
    assert (await bill.client.get("/v1/account", headers=auth(info["token"]))).json()["subscription"]["cancel_at_period_end"] is True

    bill.gw.update_subscription("sub_1", status="canceled")
    await bill.send_ok(event("customer.subscription.deleted", bf.subscription_obj(status="canceled", metadata=LC)))
    assert (await bill.scalar("SELECT status AS v FROM subscriptions")) == "canceled"

    # The paid period is still usable.
    assert (await bill.translate(info["token"])).status_code == 200

    # After the period ends: no monthly bucket, so the pack cannot be spent either.
    await grant_pack(info["account_id"], ref="pack-x")
    await bill.execute(
        "UPDATE quota_buckets SET valid_from = now() - interval '40 days', expires_at = now() - interval '1 day' "
        "WHERE kind = 'monthly'"
    )
    r = await bill.translate(info["token"])
    assert r.status_code == 402 and r.json()["error"]["code"] == "insufficient_quota"


# --- e. refunds -----------------------------------------------------------------------------------


async def test_full_refund_revokes_unused_and_closes_bucket(bill):
    info = await bill.paid_account()
    assert (await bill.translate(info["token"])).status_code == 200  # 10 chars used
    await bill.send_ok(event("charge.refunded", bf.charge(refunded=900)))
    b = (await bill.buckets(info["account_id"]))[0]
    assert b["used"] == 10 and b["granted"] == 10 and b["revoked_at"] is not None
    assert (await bill.scalar("SELECT refunded_cents AS v FROM payments")) == 900
    assert (await bill.translate(info["token"])).status_code == 402


async def test_partial_refunds_are_cumulative_and_stale_events_change_nothing(bill):
    info = await bill.paid_account()
    await bill.send_ok(event("charge.refunded", bf.charge(refunded=270)))  # 30%
    b = (await bill.buckets(info["account_id"]))[0]
    assert b["granted"] == 70000 and b["revoked_at"] is None
    await bill.send_ok(event("charge.refunded", bf.charge(refunded=540)))  # 60% in total
    assert (await bill.buckets(info["account_id"]))[0]["granted"] == 40000
    payment = await bill.one("SELECT refunded_cents, revoked_chars FROM payments")
    assert payment == {"refunded_cents": 540, "revoked_chars": 60000}

    # A late event that reports less than we already accounted for.
    await bill.send_ok(event("charge.refunded", bf.charge(refunded=270)))
    assert (await bill.buckets(info["account_id"]))[0]["granted"] == 40000
    assert (await bill.scalar("SELECT refunded_cents AS v FROM payments")) == 540
    # Only the two real revokes are in the ledger.
    assert (await bill.scalar("SELECT count(*) AS v FROM ledger WHERE kind = 'revoke'")) == 2


async def test_refund_of_a_pack_payment(bill):
    info = await bill.paid_account()
    await bill.send_ok(bill.pack_session_event(info["account_id"]))
    pack = [b for b in await bill.buckets(info["account_id"]) if b["kind"] == "pack"][0]
    assert pack["granted"] == 250000
    await bill.send_ok(event("charge.refunded", bf.charge(id="ch_pack", pi="pi_pack_1", amount=700, refunded=700)))
    pack = [b for b in await bill.buckets(info["account_id"]) if b["kind"] == "pack"][0]
    assert pack["granted"] == 0 and pack["revoked_at"] is not None
    # The subscription bucket is untouched.
    assert [b for b in await bill.buckets(info["account_id"]) if b["kind"] == "monthly"][0]["granted"] == 100000


# --- f. duplicates and concurrency ----------------------------------------------------------------


async def test_same_event_twice_is_a_duplicate(bill):
    info = await bill.first_payment()
    again = await bill.send(info["invoice_event"])
    assert again.status_code == 200 and again.json() == {"status": "duplicate"}
    assert (await bill.counts("quota_buckets", "payments", "accounts")) == {"quota_buckets": 1, "payments": 1, "accounts": 1}


async def test_two_event_ids_for_the_same_invoice_grant_once(bill):
    info = await bill.first_payment()
    second = dict(info["invoice_event"], id="evt_other_id_for_same_invoice")
    await bill.send_ok(second)  # a different event id: processed, but the invoice id is the grant key
    assert (await bill.counts("quota_buckets", "payments")) == {"quota_buckets": 1, "payments": 1}
    assert (await bill.scalar("SELECT count(*) AS v FROM ledger WHERE kind = 'grant'")) == 1


async def test_concurrent_delivery_of_one_event_has_one_effect(bill):
    info = await bill.first_payment()
    start2, end2 = info["end"], info["end"] + timedelta(days=30)
    bill.gw.update_subscription("sub_1", current_period_start=start2, current_period_end=end2)
    renewal = event("invoice.paid", bf.invoice(id="in_2", reason="subscription_cycle", pi="pi_2", start=start2, end=end2, metadata=LC))
    results = await asyncio.gather(*[bill.send(renewal) for _ in range(4)])
    statuses = sorted(r.json()["status"] for r in results)
    assert statuses == ["duplicate"] * 3 + ["processed"]
    assert (await bill.counts("quota_buckets", "payments")) == {"quota_buckets": 2, "payments": 2}


async def test_concurrent_checkout_and_invoice_for_a_new_claim_make_one_account(bill):
    claim_id, secret, session_id = await bill.signup()
    start = utcnow() - timedelta(days=1)
    end = start + timedelta(days=30)
    meta = {**LC, "lc_claim_id": claim_id}
    bill.gw.set_subscription(bf.sub_info(start=start, end=end, metadata=meta))
    checkout = event("checkout.session.completed", bf.checkout_session(id=session_id, metadata=meta))
    paid = event("invoice.paid", bf.invoice(start=start, end=end, metadata=meta))
    responses = await asyncio.gather(bill.send(checkout), bill.send(paid), bill.send(dict(paid, id="evt_dup_paid")))
    assert all(r.status_code == 200 for r in responses), [r.text for r in responses]
    assert (await bill.counts("accounts", "subscriptions", "quota_buckets", "payments")) == {
        "accounts": 1, "subscriptions": 1, "quota_buckets": 1, "payments": 1,
    }
    assert (await bill.claim(claim_id, secret)).status_code == 200


# --- g. out of order ------------------------------------------------------------------------------


async def test_stale_subscription_update_after_deleted_keeps_canceled(bill):
    await bill.paid_account()
    bill.gw.update_subscription("sub_1", status="canceled")
    await bill.send_ok(event("customer.subscription.deleted", bf.subscription_obj(status="canceled", metadata=LC)))
    # An older "active" payload arrives afterwards: the live object (canceled) wins.
    await bill.send_ok(event("customer.subscription.updated", bf.subscription_obj(status="active", metadata=LC)))
    assert (await bill.scalar("SELECT status AS v FROM subscriptions")) == "canceled"


# --- h. delayed payment methods -------------------------------------------------------------------


async def test_delayed_pack_payment_grants_once_with_session_based_expiry(bill):
    info = await bill.paid_account()
    created = utcnow() - timedelta(hours=2)
    unpaid = bill.pack_session_event(info["account_id"], payment_status="unpaid", created=created)
    await bill.send_ok(unpaid)
    assert [b["kind"] for b in await bill.buckets(info["account_id"])] == ["monthly"]  # nothing yet

    succeeded = bill.pack_session_event(
        info["account_id"], type_="checkout.session.async_payment_succeeded", created=created
    )
    await bill.send_ok(succeeded)
    packs = [b for b in await bill.buckets(info["account_id"]) if b["kind"] == "pack"]
    assert len(packs) == 1
    assert packs[0]["granted"] == 250000
    assert packs[0]["valid_from"] == created and packs[0]["expires_at"] == add_months(created, 12)

    # Stripe delivers both the completed(paid) and async_payment_succeeded for some flows, and may redeliver.
    await bill.send_ok(bill.pack_session_event(info["account_id"], created=created))
    await bill.send_ok(dict(succeeded, id="evt_redelivered_other_id"))
    assert len([b for b in await bill.buckets(info["account_id"]) if b["kind"] == "pack"]) == 1
    assert (await bill.counts("payments"))["payments"] == 2


# --- i. plan change / proration ---------------------------------------------------------------------


async def test_proration_invoice_syncs_subscription_without_grant(bill):
    info = await bill.paid_account()
    new_end = info["end"] + timedelta(days=5)
    bill.gw.update_subscription("sub_1", current_period_end=new_end)
    prorated = bf.invoice(id="in_prorata", reason="subscription_update", pi="pi_prorata", amount=150, metadata=LC)
    await bill.send_ok(event("invoice.paid", prorated))
    assert len(await bill.buckets(info["account_id"])) == 1
    assert (await bill.counts("payments"))["payments"] == 1
    assert (await bill.scalar("SELECT current_period_end AS v FROM subscriptions")) == new_end


# --- k. disputes ----------------------------------------------------------------------------------


async def test_dispute_holds_and_revokes_then_won_releases_the_hold(bill):
    info = await bill.paid_account()
    assert (await bill.translate(info["token"])).status_code == 200
    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    # The administrator's switch (status) is untouched; the hold is a separate flag.
    assert await bill.one("SELECT status, dispute_hold FROM accounts") == {"status": "active", "dispute_hold": True}
    b = (await bill.buckets(info["account_id"]))[0]
    assert b["granted"] == 10 and b["revoked_at"] is not None  # used part stays
    r = await bill.translate(info["token"])
    assert r.status_code == 403 and r.json()["error"]["code"] == "account_suspended"
    assert (await bill.scalar("SELECT disputed AS v FROM payments")) is True

    await bill.send_ok(event("charge.dispute.closed", bf.dispute(status="won")))
    assert await bill.one("SELECT status, dispute_hold FROM accounts") == {"status": "active", "dispute_hold": False}
    assert (await bill.scalar("SELECT disputed AS v FROM payments")) is False
    r = await bill.translate(info["token"])
    assert r.status_code == 402  # the revoked characters do not come back by themselves


async def test_lost_dispute_keeps_the_hold(bill):
    info = await bill.paid_account()
    await bill.send_ok(event("charge.dispute.created", bf.dispute()))
    await bill.send_ok(event("charge.dispute.closed", bf.dispute(status="lost")))
    assert await bill.one("SELECT status, dispute_hold FROM accounts") == {"status": "active", "dispute_hold": True}
    assert (await bill.translate(info["token"])).status_code == 403


# --- l. isolation from the other product on the shared Stripe account -------------------------------


def _foreign_events() -> list[dict]:
    vira = {"user_id": "5b0c5e0e-3a53-4f0e-9a62-0f6a8c1d7e11", "product": "vira"}
    return [
        event("checkout.session.completed", bf.checkout_session(id="cs_v1", subscription="sub_vira", customer="cus_vira")),
        event("checkout.session.completed", bf.checkout_session(id="cs_v2", subscription="sub_vira", customer="cus_vira", metadata=vira)),
        event("checkout.session.completed", bf.checkout_session(id="cs_v3", mode="payment", subscription=None, payment_intent="pi_vira", metadata=vira)),
        event("checkout.session.async_payment_succeeded", bf.checkout_session(id="cs_v4", mode="payment", subscription=None, payment_intent="pi_vira2", metadata=vira)),
        event("checkout.session.expired", bf.checkout_session(id="cs_v5", metadata=vira)),
        event("invoice.paid", bf.invoice(id="in_v1", subscription="sub_vira", customer="cus_vira", pi="pi_vira", metadata=vira)),
        event("invoice.paid", bf.invoice(id="in_v2", subscription="sub_vira", customer="cus_vira", pi="pi_vira", metadata={}, shape="new")),
        event("invoice.payment_failed", bf.invoice(id="in_v3", subscription="sub_vira", status="open", metadata=vira)),
        event("customer.subscription.created", bf.subscription_obj(id="sub_vira", customer="cus_vira", metadata=vira)),
        event("customer.subscription.updated", bf.subscription_obj(id="sub_vira", customer="cus_vira", metadata=vira)),
        event("customer.subscription.deleted", bf.subscription_obj(id="sub_vira", customer="cus_vira", status="canceled", metadata=vira)),
        event("customer.created", {"id": "cus_vira", "object": "customer", "metadata": vira}),
        event("payment_intent.succeeded", {"id": "pi_vira", "object": "payment_intent", "metadata": vira}),
    ]


def _foreign_charge_events() -> list[dict]:
    """Refund/dispute payloads carry no metadata: they cannot be told apart from ours, so they are PARKED."""
    return [
        event("charge.refunded", bf.charge(id="ch_vira", pi="pi_vira", refunded=100)),
        event("charge.dispute.created", bf.dispute(id="dp_vira", charge_id="ch_vira", pi="pi_vira")),
        event("charge.dispute.closed", bf.dispute(id="dp_vira", charge_id="ch_vira", pi="pi_vira", status="won")),
    ]


async def test_foreign_events_are_ignored_with_zero_stripe_calls_and_no_side_effects(bill):
    tables = ("accounts", "sites", "quota_buckets", "subscriptions", "payments", "checkout_claims", "ledger", "pair_codes")
    before = await bill.counts(*tables)
    events = _foreign_events()
    for evt in events:
        r = await bill.send(evt)
        assert r.status_code == 200 and r.json() == {"status": "ignored"}, (evt["type"], r.text)
    assert bill.gw.network_calls == 0, bill.gw.calls
    assert await bill.counts(*tables) == before
    rows = await bill.q("SELECT status FROM stripe_events")
    assert len(rows) == len(events) and {r["status"] for r in rows} == {"ignored"}


async def test_foreign_events_do_not_disturb_our_data(bill):
    info = await bill.paid_account()
    calls_before = bill.gw.network_calls
    snapshot = (await bill.buckets(info["account_id"]), await bill.q("SELECT * FROM subscriptions"), await bill.q("SELECT * FROM payments"))
    for evt in _foreign_events():
        await bill.send_ok(evt, expect="ignored")
    assert bill.gw.network_calls == calls_before
    assert (await bill.buckets(info["account_id"]), await bill.q("SELECT * FROM subscriptions"), await bill.q("SELECT * FROM payments")) == snapshot


async def test_foreign_charge_events_are_parked_without_effects_or_stripe_calls(bill):
    info = await bill.paid_account()
    calls_before = bill.gw.network_calls
    tables = ("accounts", "quota_buckets", "subscriptions", "payments", "ledger", "disputes")
    before = await bill.counts(*tables)
    buckets_before = await bill.buckets(info["account_id"])
    for evt in _foreign_charge_events():
        await bill.send_ok(evt, expect="processed")  # recorded as processed: parked, not applied
    assert bill.gw.network_calls == calls_before
    assert await bill.counts(*tables) == before
    assert await bill.buckets(info["account_id"]) == buckets_before
    assert await bill.one("SELECT refunded_cents, disputed FROM payments") == {"refunded_cents": 0, "disputed": False}
    assert await bill.one("SELECT status, dispute_hold FROM accounts") == {"status": "active", "dispute_hold": False}
    parked = await bill.q("SELECT kind, charge_id, payment_intent_id, applied_at FROM unmatched_charge_events ORDER BY id")
    assert [(p["kind"], p["charge_id"], p["payment_intent_id"]) for p in parked] == [
        ("refund", "ch_vira", "pi_vira"), ("dispute", "ch_vira", "pi_vira"), ("dispute", "ch_vira", "pi_vira"),
    ]
    assert all(p["applied_at"] is None for p in parked)


async def test_refund_of_an_unknown_charge_is_parked_and_changes_nothing(bill):
    await bill.paid_account()
    await bill.send_ok(event("charge.refunded", bf.charge(id="ch_unknown", pi="pi_unknown", refunded=900)))
    assert (await bill.scalar("SELECT refunded_cents AS v FROM payments")) == 0
    assert (await bill.scalar("SELECT count(*) AS v FROM unmatched_charge_events")) == 1


# --- n. unknown price -------------------------------------------------------------------------------


async def test_unknown_price_on_our_subscription_fails_so_stripe_retries(bill):
    claim_id, secret, session_id = await bill.signup()
    meta = {**LC, "lc_claim_id": claim_id}
    bill.gw.set_subscription(bf.sub_info(price="price_not_in_catalog", metadata=meta))
    evt = event("checkout.session.completed", bf.checkout_session(id=session_id, metadata=meta))
    r = await bill.send(evt)
    assert r.status_code == 500 and r.json()["error"]["code"] == "internal_error"
    # Nothing is persisted, not even the event row or the account: the retry starts clean.
    assert (await bill.counts("stripe_events", "accounts", "subscriptions")) == {"stripe_events": 0, "accounts": 0, "subscriptions": 0}
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "open"

    # After the catalog is fixed (price now known) the very same event succeeds.
    bill.gw.set_subscription(bf.sub_info(price=PRICE_STARTER, metadata=meta))
    await bill.send_ok(evt)


async def test_checkout_expired_marks_the_open_claim_expired(bill):
    claim_id, secret, session_id = await bill.signup()
    meta = {**LC, "lc_claim_id": claim_id}
    expired = bf.checkout_session(id=session_id, status="expired", payment_status="unpaid", customer=None, subscription=None, metadata=meta)
    await bill.send_ok(event("checkout.session.expired", expired))
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "expired"
    r = await bill.claim(claim_id, secret)
    assert r.status_code == 410 and r.json()["error"]["code"] == "claim_expired"


# --- CLI ------------------------------------------------------------------------------------------------


async def test_cli_billing_status_shows_state_without_secrets(bill, settings, capsys):
    from app import cli

    info = await bill.paid_account(email="buyer@example.com")
    await bill.send_ok(event("charge.refunded", bf.charge(refunded=270)))
    capsys.readouterr()
    args = cli.build_parser().parse_args(["billing-status", "--account", str(info["account_id"])])
    await args.func(bill.sm, settings, args)
    out = capsys.readouterr().out
    lines = out.splitlines()
    # Each section is checked on its own line, field by field (the account line prints status before plan).
    account_line = next(ln for ln in lines if ln.startswith("account "))
    for field in (f"id={info['account_id']}", "status=active", "plan=starter", "stripe_customer=yes"):
        assert field in account_line.split(), account_line
    sub_line = next(ln for ln in lines if ln.strip().startswith("id=") and "cancel_at_period_end=" in ln)
    for field in ("plan=starter", "status=active", "cancel_at_period_end=False"):
        assert field in sub_line.split(), sub_line
    payment_line = next(ln for ln in lines if "kind=subscription" in ln)
    for field in ("kind=subscription", "amount=900eur", "granted=100000", "refunded=270", "revoked=30000", "disputed=False"):
        assert field in payment_line.split(), payment_line
    bucket_line = next(ln for ln in lines if "kind=monthly" in ln)
    for field in ("kind=monthly", "granted=70000", "used=0", "reserved=0", "live"):
        assert field in bucket_line.split(), bucket_line
    for secret in ("cus_1", "buyer@example.com", info["token"], info["claim_secret"]):
        assert secret not in out


async def test_cli_billing_status_unknown_account(bill, settings):
    from app import cli

    args = cli.build_parser().parse_args(["billing-status", "--account", "99999"])
    with pytest.raises(SystemExit):
        await args.func(bill.sm, settings, args)
