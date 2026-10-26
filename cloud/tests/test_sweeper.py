"""The sweeper releases reservations stuck in 'pending' after their TTL."""

from __future__ import annotations

from datetime import timedelta

import pytest

from app.sweeper import sweep_once
from tests.helpers import grant_raw, new_rid, reserve, settle, utcnow


@pytest.fixture
async def acct(make_account, make_site):
    account_id = await make_account()
    site_id, _ = await make_site(account_id)
    return account_id, site_id


async def _bucket(q, bucket_id: int) -> dict:
    return (await q("SELECT * FROM quota_buckets WHERE id = :i", i=bucket_id))[0]


async def test_expired_pending_reservation_is_released(sessionmaker, q, acct):
    account_id, site_id = acct
    bucket = await grant_raw(sessionmaker, account_id, "monthly", 100, valid_from=utcnow() - timedelta(hours=1))
    # created 10 minutes ago with a 5 minute TTL -> expired 5 minutes ago
    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 40, now=utcnow() - timedelta(minutes=10))
    assert (await _bucket(q, bucket))["reserved"] == 40

    released = await sweep_once(sessionmaker)

    assert released == 1
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)
    row = (await q("SELECT status, billed_chars FROM reservations WHERE id = :i", i=res.reservation_id))[0]
    assert row == {"status": "released", "billed_chars": 0}
    kinds = [r["kind"] for r in await q("SELECT kind FROM ledger WHERE kind <> 'grant' ORDER BY id")]
    assert kinds == ["reserve", "release"]


async def test_settle_after_sweep_charges_nothing(sessionmaker, q, acct):
    account_id, site_id = acct
    bucket = await grant_raw(sessionmaker, account_id, "monthly", 100, valid_from=utcnow() - timedelta(hours=1))
    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 40, now=utcnow() - timedelta(minutes=10))
    await sweep_once(sessionmaker)

    out = await settle(sessionmaker, account_id, res.reservation_id, 40)

    assert out.settled is False and out.billed == 0
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)
    assert await q("SELECT 1 FROM ledger WHERE kind = 'commit'") == []


async def test_unexpired_pending_reservation_is_untouched(sessionmaker, q, acct):
    account_id, site_id = acct
    bucket = await grant_raw(sessionmaker, account_id, "monthly", 100, valid_from=utcnow() - timedelta(hours=1))
    res = await reserve(sessionmaker, account_id, site_id, new_rid(), 40)

    assert await sweep_once(sessionmaker) == 0

    assert (await _bucket(q, bucket))["reserved"] == 40
    assert (await q("SELECT status FROM reservations WHERE id = :i", i=res.reservation_id))[0]["status"] == "pending"
    out = await settle(sessionmaker, account_id, res.reservation_id, 40)
    assert out.settled is True and out.billed == 40


async def test_sweeper_ignores_committed_and_handles_mixed_set(sessionmaker, q, acct):
    account_id, site_id = acct
    bucket = await grant_raw(sessionmaker, account_id, "monthly", 100, valid_from=utcnow() - timedelta(hours=1))
    old = utcnow() - timedelta(minutes=10)
    done = await reserve(sessionmaker, account_id, site_id, new_rid(), 10, now=old)
    await settle(sessionmaker, account_id, done.reservation_id, 10, now=old)
    await reserve(sessionmaker, account_id, site_id, new_rid(), 20, now=old)
    await reserve(sessionmaker, account_id, site_id, new_rid(), 30)

    assert await sweep_once(sessionmaker) == 1

    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (10, 30)
    assert await sweep_once(sessionmaker) == 0  # nothing left to do
