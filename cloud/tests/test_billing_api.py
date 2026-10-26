"""Billing HTTP API: sign-up, claim pickup, checkout, portal, pairing, webhook security, privacy."""

from __future__ import annotations

import hashlib
import json
import logging
from datetime import timedelta

import pytest

from tests import billing_fakes as bf
from tests.billing_fakes import STRIPE_SETTINGS, FakeGateway, event
from tests.conftest import auth

LC = {"lc_app": "lingocanvas"}


def keys_of(value) -> set[str]:
    """Every dict key anywhere inside a nested structure."""
    found: set[str] = set()
    if isinstance(value, dict):
        for k, v in value.items():
            found.add(k)
            found |= keys_of(v)
    elif isinstance(value, list):
        for v in value:
            found |= keys_of(v)
    return found


def sha(secret: str) -> str:
    return hashlib.sha256(secret.encode()).hexdigest()


# --- sign-up -------------------------------------------------------------------------------------


async def test_signup_builds_checkout_with_our_metadata_only(bill):
    r = await bill.client.post(
        "/v1/billing/signup",
        json={"plan": "starter", "site_name": "example.com", "claim_secret_sha256": sha("s"), "email": "me@example.com"},
    )
    assert r.status_code == 200
    data = r.json()
    assert data["checkout_url"].startswith("https://checkout.test/") and data["claim_id"].startswith("lcc_claim_")
    params, idem = bill.gw.checkout_calls[0]
    assert idem == data["claim_id"]
    assert params["mode"] == "subscription"
    assert params["line_items"] == [{"price": bf.PRICE_STARTER, "quantity": 1}]
    expected = {"lc_app": "lingocanvas", "lc_claim_id": data["claim_id"]}
    assert params["metadata"] == expected and params["subscription_data"]["metadata"] == expected
    assert params["client_reference_id"] == data["claim_id"]
    assert params["success_url"] == f"https://centerai.eu/lingocanvas/thanks/?claim={data['claim_id']}"
    assert params["cancel_url"] == "https://centerai.eu/lingocanvas/"
    assert "customer_email" not in params  # Stripe rejects it together with customer
    assert params["customer"] == f"cus_new_lc-customer:{data['claim_id']}"
    assert "consent_collection" not in params and "automatic_tax" not in params
    assert not ({"user_id", "product"} & keys_of(params))
    claim = await bill.one(
        "SELECT status, stripe_checkout_session_id AS sid, plan, stripe_customer_id AS cus FROM checkout_claims"
    )
    assert claim == {"status": "open", "sid": "cs_test_1", "plan": "starter", "cus": params["customer"]}


async def test_signup_creates_one_customer_with_lc_app_metadata(bill):
    r = await bill.client.post(
        "/v1/billing/signup",
        json={"plan": "starter", "site_name": "example.com", "claim_secret_sha256": sha("s"), "email": "me@example.com"},
    )
    assert r.status_code == 200
    claim_id = r.json()["claim_id"]
    assert len(bill.gw.customer_calls) == 1
    fields, idem = bill.gw.customer_calls[0]
    assert fields == {"email": "me@example.com", "metadata": {"lc_app": "lingocanvas", "lc_claim_id": claim_id}}
    assert idem == f"lc-customer:{claim_id}"  # derived from the claim: a retry of this claim cannot make a second customer
    assert bill.gw.checkout_calls[0][0]["customer"] == f"cus_new_{idem}"


async def test_signup_without_email_creates_customer_without_email(bill):
    r = await bill.client.post(
        "/v1/billing/signup", json={"plan": "starter", "site_name": "x", "claim_secret_sha256": sha("s")}
    )
    assert r.status_code == 200
    fields, _ = bill.gw.customer_calls[0]
    assert fields["email"] is None and fields["metadata"]["lc_app"] == "lingocanvas"


async def test_signup_customer_failure_is_a_clean_502_and_closes_the_claim(bill, caplog):
    caplog.set_level(logging.DEBUG)
    bill.gw.fail_customer = True
    r = await bill.client.post(
        "/v1/billing/signup", json={"plan": "starter", "site_name": "x", "claim_secret_sha256": sha("s")}
    )
    assert r.status_code == 502 and r.json()["error"]["code"] == "billing_provider_error"
    assert "sk_test_leak" not in r.text and "sk_test_leak" not in caplog.text
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "expired"
    assert bill.gw.checkout_calls == []


async def test_signup_optional_consent_and_tax_flags(make_client):
    gw = FakeGateway()
    client, _ = await make_client(gateway=gw, stripe=STRIPE_SETTINGS, billing={"consent_terms": "true", "automatic_tax": "true"})
    r = await client.post("/v1/billing/signup", json={"plan": "starter", "site_name": "x", "claim_secret_sha256": sha("s")})
    assert r.status_code == 200
    params = gw.checkout_calls[0][0]
    assert params["consent_collection"] == {"terms_of_service": "required"}
    assert params["automatic_tax"] == {"enabled": True}
    assert "customer_email" not in params


@pytest.mark.parametrize(
    "payload",
    [
        {"plan": "pro", "site_name": "x", "claim_secret_sha256": "a" * 64},
        {"plan": "starter", "site_name": "", "claim_secret_sha256": "a" * 64},
        {"plan": "starter", "site_name": "   ", "claim_secret_sha256": "a" * 64},
        {"plan": "starter", "site_name": "x" * 256, "claim_secret_sha256": "a" * 64},
        {"plan": "starter", "site_name": "x", "claim_secret_sha256": "zz" * 32},
        {"plan": "starter", "site_name": "x", "claim_secret_sha256": "a" * 63},
        {"plan": "starter", "site_name": "x", "claim_secret_sha256": "a" * 64, "email": "not-an-email"},
        {"plan": "starter", "site_name": "x", "claim_secret_sha256": "a" * 64, "email": "a@b.co" + "m" * 320},
    ],
)
async def test_signup_validation_never_echoes_input(bill, payload):
    r = await bill.client.post("/v1/billing/signup", json=payload)
    assert r.status_code == 422 and r.json()["error"]["code"] == "validation_error"
    for value in payload.values():
        if isinstance(value, str) and len(value) > 12:
            assert value not in r.text
    assert bill.gw.network_calls == 0


async def test_signup_ip_rate_limit(make_client):
    gw = FakeGateway()
    client, _ = await make_client(gateway=gw, stripe=STRIPE_SETTINGS, billing={"signups_per_ip_per_hour": 2})
    payload = {"plan": "starter", "site_name": "x", "claim_secret_sha256": sha("s")}
    assert (await client.post("/v1/billing/signup", json=payload)).status_code == 200
    assert (await client.post("/v1/billing/signup", json=payload)).status_code == 200
    r = await client.post("/v1/billing/signup", json=payload)
    assert r.status_code == 429 and r.json()["error"]["code"] == "rate_limited"
    assert r.headers["retry-after"] == "3600"
    assert len(gw.checkout_calls) == 2


async def test_signup_stripe_failure_is_a_clean_502_and_closes_the_claim(bill, caplog):
    caplog.set_level(logging.DEBUG)
    bill.gw.fail_checkout = True
    r = await bill.client.post(
        "/v1/billing/signup", json={"plan": "starter", "site_name": "x", "claim_secret_sha256": sha("s")}
    )
    assert r.status_code == 502 and r.json()["error"]["code"] == "billing_provider_error"
    assert "sk_test_leak" not in r.text and "sk_test_leak" not in caplog.text
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "expired"


# --- claim pickup ------------------------------------------------------------------------------------


async def test_claim_pending_then_paid_and_unknown_id_looks_like_wrong_secret(bill):
    claim_id, secret, _ = await bill.signup()
    pending = await bill.claim(claim_id, secret)
    assert pending.status_code == 202 and pending.json() == {"status": "pending"}

    wrong = await bill.claim(claim_id, "not-the-secret")
    unknown = await bill.claim("lcc_claim_does_not_exist", secret)
    assert wrong.status_code == unknown.status_code == 404
    assert wrong.json() == unknown.json() and wrong.json()["error"]["code"] == "claim_not_found"
    assert (await bill.scalar("SELECT failed_attempts AS v FROM checkout_claims")) == 1


async def test_claim_brute_force_locks_the_claim_for_good(make_client):
    gw = FakeGateway()
    client, app = await make_client(gateway=gw, stripe=STRIPE_SETTINGS, billing={"claim_max_failed_attempts": 3})
    from tests.billing_env import BillingEnv

    env = BillingEnv(client, app, gw, app.state.sessionmaker, None)
    claim_id, secret, _ = await env.signup()
    for _ in range(3):
        assert (await env.claim(claim_id, "guess")).status_code == 404
    # Locked: even the right secret gets the same 404.
    r = await env.claim(claim_id, secret)
    assert r.status_code == 404 and r.json()["error"]["code"] == "claim_not_found"
    assert (await env.scalar("SELECT status AS v FROM checkout_claims")) == "open"


async def test_claim_locked_even_when_paid(make_client):
    gw = FakeGateway()
    client, app = await make_client(gateway=gw, stripe=STRIPE_SETTINGS, billing={"claim_max_failed_attempts": 2})
    from tests.billing_env import BillingEnv

    env = BillingEnv(client, app, gw, app.state.sessionmaker, None)
    info = await env.first_payment()
    for _ in range(2):
        await env.claim(info["claim_id"], "guess")
    assert (await env.claim(info["claim_id"], info["claim_secret"])).status_code == 404
    assert (await env.scalar("SELECT count(*) AS v FROM sites")) == 0


async def test_claim_expired_and_double_pickup(bill):
    claim_id, secret, _ = await bill.signup()
    await bill.execute("UPDATE checkout_claims SET expires_at = now() - interval '1 minute'")
    r = await bill.claim(claim_id, secret)
    assert r.status_code == 410 and r.json()["error"]["code"] == "claim_expired"

    info = await bill.first_payment()
    # A paid claim stays collectable even after its checkout window has passed.
    await bill.execute("UPDATE checkout_claims SET expires_at = now() - interval '1 minute' WHERE id = :i", i=info["claim_id"])
    ok = await bill.claim(info["claim_id"], info["claim_secret"])
    assert ok.status_code == 200
    second = await bill.claim(info["claim_id"], info["claim_secret"])
    assert second.status_code == 409 and second.json()["error"]["code"] == "already_claimed"
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 1


async def test_claim_respects_the_plan_site_limit(bill, make_site):
    info = await bill.first_payment()
    account_id = await bill.scalar("SELECT id AS v FROM accounts")
    await make_site(account_id)  # starter allows one site and it is taken
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 409 and r.json()["error"]["code"] == "site_limit_reached" and "token" not in r.text
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "paid"  # nothing consumed
    await bill.execute("UPDATE sites SET revoked_at = now()")
    assert (await bill.claim(info["claim_id"], info["claim_secret"])).status_code == 200


# --- checkout / portal ----------------------------------------------------------------------------------


async def test_pack_requires_a_live_subscription_and_makes_no_gateway_call(bill, make_account, make_site, sessionmaker):
    account_id = await make_account(plan="starter")
    await bill.execute("UPDATE accounts SET stripe_customer_id = 'cus_nosub' WHERE id = :a", a=account_id)
    _, token = await make_site(account_id)
    r = await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(token))
    assert r.status_code == 409 and r.json()["error"]["code"] == "subscription_required"
    assert bill.gw.network_calls == 0

    # An ended subscription is not live either.
    await bill.execute(
        "INSERT INTO subscriptions (account_id, stripe_subscription_id, plan, status, current_period_end) "
        "VALUES (:a, 'sub_old', 'starter', 'canceled', now() + interval '10 days')", a=account_id,
    )
    r = await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(token))
    assert r.status_code == 409 and bill.gw.network_calls == 0


async def test_pack_checkout_with_live_subscription(bill):
    info = await bill.paid_account()
    r = await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(info["token"]))
    assert r.status_code == 200 and r.json()["checkout_url"].startswith("https://checkout.test/")
    params, idem = bill.gw.checkout_calls[-1]
    account_id = str(info["account_id"])
    assert params["mode"] == "payment" and params["customer"] == "cus_1"
    assert params["line_items"] == [{"price": bf.PRICE_PACK, "quantity": 1}]
    assert params["metadata"]["lc_app"] == "lingocanvas" and params["metadata"]["lc_account_id"] == account_id
    assert params["payment_intent_data"]["metadata"] == {"lc_app": "lingocanvas", "lc_account_id": account_id}
    assert not ({"user_id", "product"} & keys_of(params))
    assert len(idem) >= 16
    r2 = await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(info["token"]))
    assert bill.gw.checkout_calls[-1][1] != idem  # a new key per call

    # And the purchase that comes back through the webhook credits this account.
    await bill.send_ok(bill.pack_session_event(info["account_id"]))
    assert [b["kind"] for b in await bill.buckets(info["account_id"])] == ["monthly", "pack"]


async def test_starter_checkout_only_without_live_subscription(bill, make_account, make_site):
    info = await bill.paid_account()
    r = await bill.client.post("/v1/billing/checkout", json={"product": "starter"}, headers=auth(info["token"]))
    assert r.status_code == 409 and r.json()["error"]["code"] == "already_subscribed"

    account_id = await make_account(plan=None)
    _, token = await make_site(account_id)
    none = await bill.client.post("/v1/billing/checkout", json={"product": "starter"}, headers=auth(token))
    assert none.status_code == 409 and none.json()["error"]["code"] == "no_billing_account"

    await bill.execute("UPDATE accounts SET stripe_customer_id = 'cus_returning' WHERE id = :a", a=account_id)
    ok = await bill.client.post("/v1/billing/checkout", json={"product": "starter"}, headers=auth(token))
    assert ok.status_code == 200
    params = bill.gw.checkout_calls[-1][0]
    assert params["mode"] == "subscription" and params["customer"] == "cus_returning"
    expected = {"lc_app": "lingocanvas", "lc_account_id": str(account_id)}
    assert params["metadata"] == expected and params["subscription_data"]["metadata"] == expected
    assert not ({"user_id", "product"} & keys_of(params))


async def test_checkout_and_portal_need_a_site_token(bill):
    assert (await bill.client.post("/v1/billing/checkout", json={"product": "pack_250k"})).status_code == 401
    assert (await bill.client.post("/v1/billing/portal")).status_code == 401
    assert (await bill.client.post("/v1/sites/pair-code")).status_code == 401


async def test_portal(bill, make_account, make_site):
    info = await bill.paid_account()
    r = await bill.client.post("/v1/billing/portal", headers=auth(info["token"]))
    assert r.status_code == 200 and r.json() == {"url": "https://billing.test/portal/cus_1"}
    assert bill.gw.portal_calls == [("cus_1", "https://centerai.eu/lingocanvas/account/")]

    account_id = await make_account(plan="starter")
    _, token = await make_site(account_id)
    none = await bill.client.post("/v1/billing/portal", headers=auth(token))
    assert none.status_code == 409 and none.json()["error"]["code"] == "no_billing_account"


async def test_account_without_subscription_reports_null(bill, make_account, make_site):
    account_id = await make_account(plan="starter")
    _, token = await make_site(account_id)
    assert (await bill.client.get("/v1/account", headers=auth(token))).json()["subscription"] is None


# --- pairing ------------------------------------------------------------------------------------------


async def test_pair_code_flow_single_use(bill):
    info = await bill.paid_account()
    await bill.execute("UPDATE accounts SET plan = 'pro'")  # three sites
    r = await bill.client.post("/v1/sites/pair-code", headers=auth(info["token"]))
    assert r.status_code == 200
    code = r.json()["code"]
    assert len(code) >= 16 and r.json()["expires_at"]
    stored = await bill.q("SELECT code_hash FROM pair_codes")
    assert len(stored) == 1 and code.encode() not in bytes(stored[0]["code_hash"])

    paired = await bill.client.post("/v1/sites/pair", json={"code": code, "site_name": "Второй сайт"})
    assert paired.status_code == 200
    new_token = paired.json()["token"]
    assert (await bill.client.get("/v1/account", headers=auth(new_token))).json()["sites"]["used"] == 2
    assert (await bill.translate(new_token)).status_code == 200

    again = await bill.client.post("/v1/sites/pair", json={"code": code, "site_name": "x"})
    unknown = await bill.client.post("/v1/sites/pair", json={"code": "nonsense-code-value", "site_name": "x"})
    assert again.status_code == unknown.status_code == 404
    assert again.json() == unknown.json() and again.json()["error"]["code"] == "pair_code_invalid"


async def test_pair_code_expiry(bill):
    info = await bill.paid_account()
    await bill.execute("UPDATE accounts SET plan = 'pro'")
    code = (await bill.client.post("/v1/sites/pair-code", headers=auth(info["token"]))).json()["code"]
    await bill.execute("UPDATE pair_codes SET expires_at = now() - interval '1 second'")
    r = await bill.client.post("/v1/sites/pair", json={"code": code, "site_name": "x"})
    assert r.status_code == 404 and r.json()["error"]["code"] == "pair_code_invalid"
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 1


async def test_pair_respects_site_limit_and_keeps_the_code(bill):
    info = await bill.paid_account()  # starter: one site, already taken
    code = (await bill.client.post("/v1/sites/pair-code", headers=auth(info["token"]))).json()["code"]
    r = await bill.client.post("/v1/sites/pair", json={"code": code, "site_name": "x"})
    assert r.status_code == 409 and r.json()["error"]["code"] == "site_limit_reached" and "token" not in r.text
    assert (await bill.scalar("SELECT used_at IS NULL AS v FROM pair_codes")) is True


async def test_pair_requires_plan_and_live_subscription(bill, make_account, make_site):
    account_id = await make_account(plan="starter")  # plan set by hand, no subscription row
    _, token = await make_site(account_id)
    code = (await bill.client.post("/v1/sites/pair-code", headers=auth(token))).json()["code"]
    r = await bill.client.post("/v1/sites/pair", json={"code": code, "site_name": "x"})
    assert r.status_code == 409 and r.json()["error"]["code"] == "subscription_required"

    no_plan = await make_account(plan=None)
    _, token2 = await make_site(no_plan)
    code2 = (await bill.client.post("/v1/sites/pair-code", headers=auth(token2))).json()["code"]
    r2 = await bill.client.post("/v1/sites/pair", json={"code": code2, "site_name": "x"})
    assert r2.status_code == 409


async def test_pair_code_cap(make_client, make_account, make_site):
    gw = FakeGateway()
    client, _ = await make_client(gateway=gw, stripe=STRIPE_SETTINGS, billing={"max_active_pair_codes": 2})
    account_id = await make_account(plan="pro")
    _, token = await make_site(account_id)
    for _ in range(2):
        assert (await client.post("/v1/sites/pair-code", headers=auth(token))).status_code == 200
    r = await client.post("/v1/sites/pair-code", headers=auth(token))
    assert r.status_code == 429 and r.json()["error"]["code"] == "too_many_pair_codes"


# --- webhook security ----------------------------------------------------------------------------------


async def test_bad_or_missing_signature_is_400_without_db_writes(bill):
    evt = event("customer.created", {"id": "cus_x"})
    wrong_secret = await bill.send(evt, secret="whsec_wrong")
    assert wrong_secret.status_code == 400 and wrong_secret.json()["error"]["code"] == "invalid_signature"
    no_header = await bill.client.post("/v1/stripe/webhook", content=json.dumps(evt).encode())
    assert no_header.status_code == 400
    tampered_body = json.dumps(evt).encode()
    sig = bf.sign(tampered_body)
    tampered = await bill.client.post(
        "/v1/stripe/webhook", content=tampered_body.replace(b"cus_x", b"cus_y"), headers={"Stripe-Signature": sig}
    )
    assert tampered.status_code == 400
    garbage = await bill.client.post("/v1/stripe/webhook", content=b"not json", headers={"Stripe-Signature": bf.sign(b"not json")})
    assert garbage.status_code == 400
    assert (await bill.counts("stripe_events"))["stripe_events"] == 0


async def test_stale_timestamp_is_rejected(bill):
    import time

    evt = event("customer.created", {"id": "cus_x"})
    r = await bill.send(evt, timestamp=int(time.time()) - 400)
    assert r.status_code == 400
    fresh = await bill.send(evt, timestamp=int(time.time()) - 200)
    assert fresh.status_code == 200
    # (The SDK's tolerance check is one-sided: it rejects old timestamps, not future ones.)


async def test_livemode_mismatch_is_ignored_without_any_write(bill):
    live = event("invoice.paid", bf.invoice(metadata=LC), livemode=True)
    r = await bill.send(live)
    assert r.status_code == 200 and r.json() == {"status": "ignored"}
    assert (await bill.counts("stripe_events", "accounts")) == {"stripe_events": 0, "accounts": 0}
    assert bill.gw.network_calls == 0


async def test_live_events_are_processed_when_configured_live(make_client):
    gw = FakeGateway()
    client, app = await make_client(gateway=gw, stripe={**STRIPE_SETTINGS, "stripe_livemode": True})
    ok = await bf.post_event(client, event("customer.created", {"id": "cus_x"}, livemode=True))
    assert ok.json() == {"status": "ignored"}  # an unrelated type, but recorded
    from tests.billing_env import BillingEnv

    env = BillingEnv(client, app, gw, app.state.sessionmaker, None)
    assert (await env.counts("stripe_events"))["stripe_events"] == 1
    test_mode = await bf.post_event(client, event("customer.created", {"id": "cus_y"}, livemode=False))
    assert test_mode.json() == {"status": "ignored"}
    assert (await env.counts("stripe_events"))["stripe_events"] == 1  # the test-mode event was not recorded


async def test_webhook_has_its_own_body_limit(make_client):
    gw = FakeGateway()
    client, _ = await make_client(
        gateway=gw, stripe=STRIPE_SETTINGS, limits={"max_body_bytes": 1000}, billing={"webhook_max_body_bytes": 20000}
    )
    padded = event("customer.created", {"id": "cus_x", "padding": "p" * 5000})
    # Over the global limit but under the webhook's own: accepted.
    assert len(json.dumps(padded)) > 1000
    assert (await bf.post_event(client, padded)).status_code == 200
    # The same global limit still protects the other endpoints.
    big = await client.post("/v1/billing/claim", json={"claim_id": "x" * 3000, "claim_secret": "y"})
    assert big.status_code == 413
    # Over the webhook's own limit: 413 before any signature work.
    huge = event("customer.created", {"id": "cus_x", "padding": "p" * 30000})
    r = await bf.post_event(client, huge)
    assert r.status_code == 413 and r.json()["error"]["code"] == "payload_too_large"


async def test_billing_disabled_answers_503_everywhere(make_client, make_account, make_site):
    client, _ = await make_client()  # no Stripe settings
    account_id = await make_account(plan="starter")
    _, token = await make_site(account_id)
    checks = [
        client.post("/v1/billing/signup", json={"plan": "starter", "site_name": "x", "claim_secret_sha256": "a" * 64}),
        client.post("/v1/billing/claim", json={"claim_id": "x", "claim_secret": "y"}),
        client.post("/v1/billing/checkout", json={"product": "pack_250k"}, headers=auth(token)),
        client.post("/v1/billing/portal", headers=auth(token)),
        bf.post_event(client, event("customer.created", {"id": "cus_x"})),
    ]
    for coro in checks:
        r = await coro
        assert r.status_code == 503 and r.json()["error"]["code"] == "billing_disabled", r.text
    # Everything else keeps working.
    assert (await client.get("/v1/account", headers=auth(token))).status_code == 200


@pytest.mark.parametrize("missing", ["stripe_secret_key", "stripe_webhook_secret"])
async def test_billing_needs_both_secrets(make_client, missing):
    client, _ = await make_client(gateway=FakeGateway(), stripe={**STRIPE_SETTINGS, missing: ""})
    r = await client.post("/v1/billing/claim", json={"claim_id": "x", "claim_secret": "y"})
    assert r.status_code == 503


async def test_processing_failure_logs_only_ids_and_class(bill, caplog):
    caplog.set_level(logging.DEBUG)

    class Boom(Exception):
        pass

    async def explode(self_obj, subscription_id):
        raise Boom("secret detail buyer@example.com sk_test_leak")

    bill.gw.retrieve_subscription = explode.__get__(bill.gw)
    claim_id, secret, session_id = await bill.signup()
    meta = {**LC, "lc_claim_id": claim_id}
    evt = event("checkout.session.completed", bf.checkout_session(id=session_id, metadata=meta, email="buyer@example.com"), id="evt_boom_1")
    r = await bill.send(evt)
    assert r.status_code == 500 and r.json() == {"error": {"code": "internal_error", "message": "Internal error."}}
    records = [rec.getMessage() for rec in caplog.records if rec.name == "lingocanvas.billing.api"]
    assert any("evt_boom_1" in m and "Boom" in m and "checkout.session.completed" in m for m in records)
    for needle in ("secret detail", "buyer@example.com", "sk_test_leak"):
        assert needle not in caplog.text and needle not in r.text
    assert (await bill.counts("stripe_events"))["stripe_events"] == 0


async def test_no_secrets_tokens_or_emails_in_logs(bill, caplog):
    caplog.set_level(logging.INFO)
    email = "private.person@example.com"
    info = await bill.first_payment(email=email)
    await bill.claim(info["claim_id"], "wrong-secret-value-123")
    picked = await bill.claim(info["claim_id"], info["claim_secret"])
    token = picked.json()["token"]
    await bill.send(bf.event("customer.created", {"id": "cus_x"}), secret="whsec_wrong_guess_value")
    await bill.client.post("/v1/billing/portal", headers=auth(token))
    code = (await bill.client.post("/v1/sites/pair-code", headers=auth(token))).json()["code"]

    forbidden = [
        email, info["claim_secret"], sha(info["claim_secret"]), "wrong-secret-value-123", token, code,
        STRIPE_SETTINGS["stripe_webhook_secret"], STRIPE_SETTINGS["stripe_secret_key"], "whsec_wrong_guess_value",
    ]
    for needle in forbidden:
        assert needle not in caplog.text, f"{needle!r} leaked into the logs"
