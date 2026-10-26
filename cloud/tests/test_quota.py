"""Quota rules: spend order, partial settle, which buckets are spendable, 402 and dedupe."""

from __future__ import annotations

from datetime import timedelta

import pytest
from sqlalchemy import text

from app import quota
from tests.conftest import auth
from tests.helpers import body, grant_raw, new_rid, reserve, settle, utcnow


async def _bucket(q, bucket_id: int) -> dict:
    return (await q("SELECT * FROM quota_buckets WHERE id = :i", i=bucket_id))[0]


async def _ledger(q, kind: str) -> int:
    rows = await q("SELECT COALESCE(SUM(chars), 0) AS n FROM ledger WHERE kind = :k", k=kind)
    return int(rows[0]["n"])


@pytest.fixture
async def acct(make_account, make_site):
    account_id = await make_account()
    site_id, token = await make_site(account_id)
    return account_id, site_id, token


async def test_monthly_is_spent_before_pack(sessionmaker, q, acct):
    account_id, site_id, _ = acct
    pack = await grant_raw(sessionmaker, account_id, "pack", 100)  # created first, lower id
    month = await grant_raw(sessionmaker, account_id, "monthly", 100)

    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 50)
    assert res.kind == "new" and res.chars == 50
    assert (await _bucket(q, month))["reserved"] == 50
    assert (await _bucket(q, pack))["reserved"] == 0

    await settle(sessionmaker, account_id, res.reservation_id, 50)
    assert (await _bucket(q, month))["used"] == 50
    assert (await _bucket(q, pack))["used"] == 0


async def test_reservation_spans_monthly_remainder_and_pack(sessionmaker, q, acct):
    account_id, site_id, _ = acct
    month = await grant_raw(sessionmaker, account_id, "monthly", 100)
    pack = await grant_raw(sessionmaker, account_id, "pack", 100)

    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 150)
    assert (await _bucket(q, month))["reserved"] == 100
    assert (await _bucket(q, pack))["reserved"] == 50
    items = await q("SELECT bucket_id, chars FROM reservation_items WHERE reservation_id = :r ORDER BY bucket_id", r=res.reservation_id)
    assert {(i["bucket_id"], i["chars"]) for i in items} == {(month, 100), (pack, 50)}

    out = await settle(sessionmaker, account_id, res.reservation_id, 150)
    assert (out.billed, out.released, out.settled) == (150, 0, True)
    assert (await _bucket(q, month))["used"] == 100
    assert (await _bucket(q, pack))["used"] == 50
    assert (await _bucket(q, pack))["reserved"] == 0


async def test_partial_settle_charges_monthly_first(sessionmaker, q, acct):
    account_id, site_id, _ = acct
    month = await grant_raw(sessionmaker, account_id, "monthly", 100)
    pack = await grant_raw(sessionmaker, account_id, "pack", 100)
    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 150)

    out = await settle(sessionmaker, account_id, res.reservation_id, 60)

    assert (out.billed, out.released) == (60, 90)
    m, p = await _bucket(q, month), await _bucket(q, pack)
    assert (m["used"], m["reserved"]) == (60, 0)
    assert (p["used"], p["reserved"]) == (0, 0)
    assert await _ledger(q, "commit") == 60
    assert await _ledger(q, "release") == 90
    status = (await q("SELECT status, billed_chars FROM reservations"))[0]
    assert status == {"status": "committed", "billed_chars": 60}


async def test_partial_settle_spanning_both_buckets(sessionmaker, q, acct):
    account_id, site_id, _ = acct
    month = await grant_raw(sessionmaker, account_id, "monthly", 100)
    pack = await grant_raw(sessionmaker, account_id, "pack", 100)
    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 150)

    await settle(sessionmaker, account_id, res.reservation_id, 120)

    assert (await _bucket(q, month))["used"] == 100
    p = await _bucket(q, pack)
    assert (p["used"], p["reserved"]) == (20, 0)


async def test_packs_are_earliest_expiry_first(sessionmaker, q, acct):
    account_id, site_id, _ = acct
    await grant_raw(sessionmaker, account_id, "monthly", 10)
    late = await grant_raw(sessionmaker, account_id, "pack", 100, expires_at=utcnow() + timedelta(days=300))
    early = await grant_raw(sessionmaker, account_id, "pack", 100, expires_at=utcnow() + timedelta(days=10))

    await reserve(sessionmaker, account_id, site_id, new_rid(), 60)

    assert (await _bucket(q, early))["reserved"] == 50
    assert (await _bucket(q, late))["reserved"] == 0


async def test_exactly_available_is_allowed_one_more_is_not(sessionmaker, acct):
    account_id, site_id, _ = acct
    await grant_raw(sessionmaker, account_id, "monthly", 100)
    await reserve(sessionmaker, account_id, site_id, new_rid(), 100)
    with pytest.raises(quota.InsufficientQuota) as exc:
        await reserve(sessionmaker, account_id, site_id, new_rid(), 1)
    assert (exc.value.needed, exc.value.available) == (1, 0)


async def test_pack_is_not_spendable_without_a_live_monthly_bucket(client, mock_provider, sessionmaker, acct):
    account_id, _, token = acct
    now = utcnow()
    await grant_raw(
        sessionmaker, account_id, "monthly", 100_000,
        valid_from=now - timedelta(days=40), expires_at=now - timedelta(days=10),
    )
    await grant_raw(sessionmaker, account_id, "pack", 250_000)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert r.status_code == 402
    assert r.json()["error"]["available"] == 0
    assert mock_provider.calls == 0

    info = (await client.get("/v1/account", headers=auth(token))).json()["quota"]
    assert info["packs_remaining"] == 250_000
    assert info["packs_usable"] is False
    assert info["total_remaining"] == 0

    # a renewal makes the waiting pack usable again; monthly is still spent first
    await grant_raw(sessionmaker, account_id, "monthly", 5)
    r = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert r.status_code == 200
    q = r.json()["quota"]
    assert q["monthly_remaining"] == 0 and q["packs_remaining"] == 250_000 - 5 and q["packs_usable"] is True


async def test_pack_only_account_cannot_translate(client, mock_provider, sessionmaker, acct):
    account_id, _, token = acct
    await grant_raw(sessionmaker, account_id, "pack", 250_000)
    r = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert r.status_code == 402
    assert mock_provider.calls == 0


async def test_expired_revoked_and_future_buckets_are_ignored(client, sessionmaker, q, mock_provider, acct):
    account_id, _, token = acct
    now = utcnow()
    await grant_raw(sessionmaker, account_id, "monthly", 9999, valid_from=now - timedelta(days=40), expires_at=now - timedelta(seconds=1))
    revoked = await grant_raw(sessionmaker, account_id, "monthly", 9999)
    async with sessionmaker() as s, s.begin():
        await s.execute(text("UPDATE quota_buckets SET revoked_at = now() WHERE id = :i"), {"i": revoked})
    await grant_raw(sessionmaker, account_id, "monthly", 9999, valid_from=now + timedelta(days=1), expires_at=now + timedelta(days=31))
    await grant_raw(sessionmaker, account_id, "monthly", 4)  # the only live one

    r = await client.post("/v1/translate", json=body(), headers=auth(token))  # needs 10

    assert r.status_code == 402
    err = r.json()["error"]
    assert (err["needed"], err["available"]) == (10, 4)
    assert mock_provider.calls == 0


async def test_402_leaves_no_trace_and_never_calls_provider(client, mock_provider, q, sessionmaker, acct):
    account_id, _, token = acct
    month = await grant_raw(sessionmaker, account_id, "monthly", 5)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 402
    assert r.json() == {
        "error": {"code": "insufficient_quota", "message": "Not enough characters left.", "needed": 10, "available": 5}
    }
    assert mock_provider.calls == 0
    assert await q("SELECT 1 FROM reservations") == []
    assert await q("SELECT 1 FROM ledger WHERE kind = 'reserve'") == []
    b = await _bucket(q, month)
    assert (b["used"], b["reserved"]) == (0, 0)


async def test_identical_texts_are_charged_once_and_all_keys_translated(
    client, mock_provider, q, sessionmaker, acct
):
    account_id, _, token = acct
    month = await grant_raw(sessionmaker, account_id, "monthly", 1000)
    items = {"key-a": "Hello", "key-b": "Hello", "key-c": "World"}

    r = await client.post("/v1/translate", json=body(items), headers=auth(token))

    assert r.status_code == 200
    data = r.json()
    assert data["translations"] == {"key-a": "[de] Hello", "key-b": "[de] Hello", "key-c": "[de] World"}
    assert data["billed_chars"] == 10  # Hello + World, not Hello twice
    assert data["missing"] == []
    assert sorted(mock_provider.requests[0].items.values()) == ["Hello", "World"]
    assert (await _bucket(q, month))["used"] == 10


async def test_revoke_unused_never_touches_used_or_reserved(sessionmaker, q, acct):
    account_id, site_id, _ = acct
    month = await grant_raw(sessionmaker, account_id, "monthly", 100)
    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 30)

    async with sessionmaker() as s, s.begin():
        taken = await quota.revoke_unused(s, account_id=account_id, bucket_id=month, chars=None, now=utcnow(), note="refund")
    assert taken == 70
    b = await _bucket(q, month)
    assert (b["granted"], b["reserved"], b["used"]) == (30, 30, 0)
    assert b["revoked_at"] is not None
    assert await _ledger(q, "revoke") == 70

    # the revoked bucket is closed, but the in-flight reservation still settles cleanly
    out = await settle(sessionmaker, account_id, res.reservation_id, 30)
    assert out.billed == 30
    b = await _bucket(q, month)
    assert (b["granted"], b["used"], b["reserved"]) == (30, 30, 0)


async def test_grant_validation(sessionmaker, acct):
    account_id, _, _ = acct
    async with sessionmaker() as s, s.begin():
        with pytest.raises(ValueError):
            await quota.grant(s, account_id=account_id, kind="bonus", chars=10, now=utcnow())
    async with sessionmaker() as s, s.begin():
        with pytest.raises(ValueError):
            await quota.grant(s, account_id=account_id, kind="monthly", chars=0, now=utcnow())


async def test_provider_gets_short_ids_never_client_keys(client, mock_provider, sessionmaker, acct):
    account_id, _, token = acct
    await grant_raw(sessionmaker, account_id, "monthly", 1000)
    r = await client.post("/v1/translate", json=body({"a" * 64: "Hello"}), headers=auth(token))
    assert r.status_code == 200
    assert list(mock_provider.requests[0].items) == ["1"]
    assert r.json()["translations"] == {"a" * 64: "[de] Hello"}
