"""Claim pickup recovery: a lost 200 response must not lose the site token forever."""

from __future__ import annotations

import asyncio
import logging

from tests.conftest import auth

CONFIRM = "/v1/billing/claim/confirm"


async def _account(bill, token: str):
    return await bill.client.get("/v1/account", headers=auth(token))


async def _confirm(bill, claim_id: str, token: str):
    return await bill.client.post(CONFIRM, json={"claim_id": claim_id}, headers=auth(token))


async def _snapshot(bill) -> dict:
    return {
        "counts": await bill.counts("sites", "quota_buckets", "ledger", "subscriptions", "payments"),
        "buckets": await bill.q("SELECT * FROM quota_buckets ORDER BY id"),
        "ledger": await bill.q("SELECT * FROM ledger ORDER BY id"),
        "subs": await bill.q("SELECT * FROM subscriptions ORDER BY id"),
    }


async def test_first_pickup_records_site_and_window(bill):
    info = await bill.first_payment()
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 200
    data = r.json()
    assert data["recovered"] is False and set(data) == {"token", "site_id", "account_id", "recovered"}
    row = await bill.one(
        "SELECT status, site_id, consumed_at, recovery_until, (recovery_until - consumed_at) AS span FROM checkout_claims"
    )
    assert row["status"] == "consumed" and row["site_id"] == data["site_id"]
    assert row["consumed_at"] is not None and row["span"].total_seconds() == 24 * 3600


async def test_lost_response_second_claim_rotates_the_token(bill):
    info = await bill.first_payment()
    first = await bill.claim(info["claim_id"], info["claim_secret"])
    assert first.status_code == 200
    old = first.json()["token"]
    assert (await _account(bill, old)).status_code == 200
    before = await _snapshot(bill)

    second = await bill.claim(info["claim_id"], info["claim_secret"])
    assert second.status_code == 200
    data = second.json()
    assert data["recovered"] is True and data["site_id"] == first.json()["site_id"]
    assert data["account_id"] == first.json()["account_id"] and data["token"] != old
    assert (await _account(bill, old)).status_code == 401
    assert (await _account(bill, data["token"])).status_code == 200
    assert await _snapshot(bill) == before
    assert (await bill.scalar("SELECT token_prefix AS v FROM sites")) == data["token"][:12]
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "consumed"


async def test_wrong_secret_never_rotates_and_counts_towards_lockout(make_client):
    from tests.billing_env import BillingEnv
    from tests.billing_fakes import STRIPE_SETTINGS, FakeGateway

    gw = FakeGateway()
    client, app = await make_client(gateway=gw, stripe=STRIPE_SETTINGS, billing={"claim_max_failed_attempts": 2})
    env = BillingEnv(client, app, gw, app.state.sessionmaker, None)
    info = await env.first_payment()
    token = (await env.claim(info["claim_id"], info["claim_secret"])).json()["token"]

    for _ in range(2):
        r = await env.claim(info["claim_id"], "wrong-secret")
        assert r.status_code == 404 and "token" not in r.text
    assert (await env.scalar("SELECT failed_attempts AS v FROM checkout_claims")) == 2
    assert (await _account(env, token)).status_code == 200  # untouched

    # Lockout: even the right secret gets the same 404 and nothing rotates.
    r = await env.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 404 and r.json()["error"]["code"] == "claim_not_found"
    assert (await _account(env, token)).status_code == 200


async def test_confirm_closes_recovery(bill):
    info = await bill.first_payment()
    token = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    r = await _confirm(bill, info["claim_id"], token)
    assert r.status_code == 200 and r.json() == {"status": "confirmed"}
    row = await bill.one("SELECT status, recovery_until FROM checkout_claims")
    assert row["status"] == "confirmed" and row["recovery_until"] is None

    again = await bill.claim(info["claim_id"], info["claim_secret"])
    assert again.status_code == 409 and again.json()["error"]["code"] == "already_claimed"
    assert "token" not in again.text
    assert (await _account(bill, token)).status_code == 200  # no rotation


async def test_confirm_is_idempotent(bill):
    info = await bill.first_payment()
    token = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    assert (await _confirm(bill, info["claim_id"], token)).status_code == 200
    assert (await _confirm(bill, info["claim_id"], token)).status_code == 200
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "confirmed"


async def test_confirm_by_another_site_or_unknown_claim_is_404(bill, make_account, make_site):
    info = await bill.first_payment()
    token = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    other_account = await make_account(plan="starter")
    _, other_token = await make_site(other_account)

    r = await _confirm(bill, info["claim_id"], other_token)
    unknown = await _confirm(bill, "lcc_claim_does_not_exist", token)
    assert r.status_code == unknown.status_code == 404
    assert r.json() == unknown.json() and r.json()["error"]["code"] == "claim_not_found"
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "consumed"

    assert (await bill.client.post(CONFIRM, json={"claim_id": info["claim_id"]})).status_code == 401


async def test_confirm_with_a_rotated_old_token_is_401(bill):
    info = await bill.first_payment()
    old = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    new = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    assert (await _confirm(bill, info["claim_id"], old)).status_code == 401
    assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "consumed"
    assert (await _confirm(bill, info["claim_id"], new)).status_code == 200


async def test_window_over_is_already_claimed(bill):
    info = await bill.first_payment()
    token = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    await bill.execute("UPDATE checkout_claims SET recovery_until = now() - interval '1 second'")
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 409 and r.json()["error"]["code"] == "already_claimed" and "token" not in r.text
    assert (await _account(bill, token)).status_code == 200


async def test_legacy_consumed_rows_are_not_recoverable(bill):
    info = await bill.first_payment()
    await bill.claim(info["claim_id"], info["claim_secret"])
    await bill.execute("UPDATE checkout_claims SET site_id = NULL, consumed_at = NULL, recovery_until = NULL")
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 409 and r.json()["error"]["code"] == "already_claimed"
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 1


async def test_revoked_site_is_not_revived(bill):
    info = await bill.first_payment()
    await bill.claim(info["claim_id"], info["claim_secret"])
    await bill.execute("UPDATE sites SET revoked_at = now()")
    before = await bill.one("SELECT token_hash FROM sites")
    r = await bill.claim(info["claim_id"], info["claim_secret"])
    assert r.status_code == 409 and r.json()["error"]["code"] == "site_revoked" and "token" not in r.text
    assert await bill.one("SELECT token_hash FROM sites") == before
    assert (await bill.scalar("SELECT revoked_at IS NOT NULL AS v FROM sites")) is True


async def test_dispute_hold_and_suspension_block_rotation(bill):
    info = await bill.first_payment()
    old = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    before = await bill.one("SELECT token_hash FROM sites")
    for sql in (
        "UPDATE accounts SET dispute_hold = true",
        "UPDATE accounts SET dispute_hold = false, status = 'suspended'",
    ):
        await bill.execute(sql)
        r = await bill.claim(info["claim_id"], info["claim_secret"])
        assert r.status_code == 403 and r.json()["error"]["code"] == "account_suspended" and "token" not in r.text
        assert await bill.one("SELECT token_hash FROM sites") == before
    await bill.execute("UPDATE accounts SET status = 'active'")
    assert (await _account(bill, old)).status_code == 200


async def test_concurrent_claims_end_with_exactly_one_site_and_the_last_token(bill):
    info = await bill.first_payment()
    results = await asyncio.gather(*[bill.claim(info["claim_id"], info["claim_secret"]) for _ in range(6)])
    assert all(r.status_code == 200 for r in results)
    assert sum(1 for r in results if r.json()["recovered"] is False) == 1
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 1
    assert len({r.json()["site_id"] for r in results}) == 1
    tokens = [r.json()["token"] for r in results]
    assert len(set(tokens)) == 6
    live = [t for t in tokens if (await _account(bill, t)).status_code == 200]
    assert len(live) == 1  # exactly the last issued token works
    assert (await bill.scalar("SELECT token_prefix AS v FROM sites")) == live[0][:12]
    assert len(await bill.q("SELECT 1 FROM quota_buckets")) == 1


async def test_concurrent_claim_and_confirm_stay_consistent(bill):
    info = await bill.first_payment()
    token = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    claim_r, confirm_r = await asyncio.gather(
        bill.claim(info["claim_id"], info["claim_secret"]), _confirm(bill, info["claim_id"], token)
    )
    assert (await bill.scalar("SELECT count(*) AS v FROM sites")) == 1
    if confirm_r.status_code == 200:
        # Confirm won the lock, or ran first against the still-current token.
        if claim_r.status_code == 200:
            # Claim rotated first; confirm then must have failed (stale token). Both 200 is impossible.
            assert False, "confirm of a rotated token must not succeed"
        assert claim_r.status_code == 409
        assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "confirmed"
        assert (await _account(bill, token)).status_code == 200
    else:
        assert confirm_r.status_code == 401 and claim_r.status_code == 200
        assert (await bill.scalar("SELECT status AS v FROM checkout_claims")) == "consumed"
        assert (await _account(bill, token)).status_code == 401
        assert (await _account(bill, claim_r.json()["token"])).status_code == 200


async def test_billing_disabled_confirm_is_503(make_client):
    client, _ = await make_client()
    r = await client.post(CONFIRM, json={"claim_id": "x"}, headers=auth("lcc_whatever"))
    assert r.status_code == 503 and r.json()["error"]["code"] == "billing_disabled"


async def test_logs_hold_no_tokens_or_secrets(bill, caplog):
    caplog.set_level(logging.INFO)
    info = await bill.first_payment()
    first = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    second = (await bill.claim(info["claim_id"], info["claim_secret"])).json()["token"]
    await _confirm(bill, info["claim_id"], second)
    await bill.claim(info["claim_id"], info["claim_secret"])
    for needle in (first, second, info["claim_secret"]):
        assert needle not in caplog.text
