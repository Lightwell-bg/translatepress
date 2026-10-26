"""Parallel requests against one quota: the account row lock must keep the books exact."""

from __future__ import annotations

import asyncio

import pytest
from sqlalchemy import text
from sqlalchemy.exc import IntegrityError

from app import quota
from tests.conftest import auth
from tests.helpers import body, utcnow

K = 10  # characters per request
BIG_LIMITS = {"max_in_flight_per_account": 1000, "per_site_requests_per_minute": 100000}


def _one(i: int) -> dict:
    return body({"k": f"Text {i:05d}"})  # exactly K characters, distinct per request


async def _fire(client, tokens: list[str], count: int):
    return await asyncio.gather(
        *[client.post("/v1/translate", json=_one(i), headers=auth(tokens[i % len(tokens)])) for i in range(count)]
    )


async def test_parallel_requests_never_overspend_one_site(make_client, q, make_account, make_site, grant_month):
    client, _ = await make_client(limits=BIG_LIMITS)
    account_id = await make_account()
    _, token = await make_site(account_id)
    n_ok = 25
    bucket = await grant_month(account_id, n_ok * K)

    responses = await _fire(client, [token], 40)

    codes = [r.status_code for r in responses]
    assert codes.count(200) == n_ok
    assert codes.count(402) == 40 - n_ok
    assert set(codes) == {200, 402}
    b = (await q("SELECT used, reserved, granted FROM quota_buckets WHERE id = :i", i=bucket))[0]
    assert (b["used"], b["reserved"], b["granted"]) == (n_ok * K, 0, n_ok * K)
    assert len(await q("SELECT 1 FROM reservations WHERE status = 'committed'")) == n_ok
    assert await q("SELECT 1 FROM reservations WHERE status = 'pending'") == []
    commit = (await q("SELECT COALESCE(SUM(chars), 0) AS n FROM ledger WHERE kind = 'commit'"))[0]["n"]
    assert commit == n_ok * K


async def test_parallel_requests_across_two_sites_share_the_account_quota(
    make_client, q, make_account, make_site, grant_month
):
    client, _ = await make_client(limits=BIG_LIMITS)
    account_id = await make_account(plan="pro")
    _, token_a = await make_site(account_id, "a.example.com")
    _, token_b = await make_site(account_id, "b.example.com")
    n_ok = 20
    bucket = await grant_month(account_id, n_ok * K)

    responses = await _fire(client, [token_a, token_b], 40)

    codes = [r.status_code for r in responses]
    assert codes.count(200) == n_ok and codes.count(402) == 40 - n_ok
    b = (await q("SELECT used, reserved FROM quota_buckets WHERE id = :i", i=bucket))[0]
    assert (b["used"], b["reserved"]) == (n_ok * K, 0)
    per_site = await q("SELECT site_id, COUNT(*) AS n FROM reservations WHERE status = 'committed' GROUP BY site_id")
    assert sum(r["n"] for r in per_site) == n_ok


async def test_parallel_grants_with_same_source_ref_create_one_bucket(sessionmaker, q, make_account):
    account_id = await make_account()

    async def _grant():
        now = utcnow()
        async with sessionmaker() as s, s.begin():
            return await quota.grant(
                s, account_id=account_id, kind="pack", chars=500, now=now, source_ref="stripe:pi_same"
            )

    results = await asyncio.gather(*[_grant() for _ in range(12)])

    assert sum(1 for _, created in results if created) == 1
    assert len({bucket_id for bucket_id, _ in results}) == 1
    assert len(await q("SELECT 1 FROM quota_buckets")) == 1
    assert len(await q("SELECT 1 FROM ledger WHERE kind = 'grant'")) == 1


@pytest.mark.parametrize(
    "assignment",
    ["used = granted + 1", "reserved = granted + 1", "used = granted, reserved = 1", "used = -1"],
)
async def test_database_refuses_an_overspent_or_negative_bucket(sessionmaker, make_account, grant_month, assignment):
    account_id = await make_account()
    bucket = await grant_month(account_id, 100)

    with pytest.raises(IntegrityError):
        async with sessionmaker() as s, s.begin():
            await s.execute(text(f"UPDATE quota_buckets SET {assignment} WHERE id = :i"), {"i": bucket})
