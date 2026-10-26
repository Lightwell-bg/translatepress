"""Idempotency of request ids: replays, conflicts, retries after failure, parallel duplicates."""

from __future__ import annotations

import asyncio

import pytest

from app.providers.base import ProviderError
from tests.conftest import auth
from tests.helpers import StubProvider, body, new_rid


@pytest.fixture
async def acct(make_account, make_site, grant_month):
    account_id = await make_account()
    site_id, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)
    return account_id, site_id, token, bucket


async def _bucket(q, bucket_id):
    return (await q("SELECT * FROM quota_buckets WHERE id = :i", i=bucket_id))[0]


async def test_same_request_twice_is_a_free_replay(client, mock_provider, q, acct):
    _, _, token, bucket = acct
    payload = body()

    first = await client.post("/v1/translate", json=payload, headers=auth(token))
    second = await client.post("/v1/translate", json=payload, headers=auth(token))

    assert first.status_code == 200 and second.status_code == 200
    assert first.json()["replay"] is False and first.json()["billed_chars"] == 10
    assert second.json()["replay"] is True and second.json()["billed_chars"] == 0
    assert second.json()["translations"] == first.json()["translations"]
    assert second.json()["quota"] == first.json()["quota"]
    assert (await _bucket(q, bucket))["used"] == 10
    assert (await q("SELECT replays FROM reservations"))[0]["replays"] == 1
    assert len(await q("SELECT 1 FROM ledger WHERE kind = 'commit'")) == 1


async def test_replay_limit(client, acct):
    _, _, token, bucket = acct
    payload = body()
    codes = [(await client.post("/v1/translate", json=payload, headers=auth(token))).status_code for _ in range(3)]
    assert codes == [200, 200, 200]  # original + max_replays (2) replays
    r = await client.post("/v1/translate", json=payload, headers=auth(token))
    assert r.status_code == 409 and r.json()["error"]["code"] == "replay_limit"


async def test_same_request_id_with_other_body_conflicts(client, mock_provider, q, acct):
    _, _, token, bucket = acct
    rid = new_rid()
    await client.post("/v1/translate", json=body(request_id=rid), headers=auth(token))

    for changed in (
        body({"k1": "Hello", "k2": "Changed"}, request_id=rid),
        body(request_id=rid, target_locale="fr"),
        body(request_id=rid, glossary={"a": "b"}),
    ):
        r = await client.post("/v1/translate", json=changed, headers=auth(token))
        assert r.status_code == 409 and r.json()["error"]["code"] == "request_id_conflict"
    assert mock_provider.calls == 1
    assert (await _bucket(q, bucket))["used"] == 10


async def test_same_request_id_on_another_site_is_independent(client, make_site, acct):
    account_id, _, token, _ = acct
    _, token2 = await make_site(account_id, "other.example.com")
    payload = body()
    a = await client.post("/v1/translate", json=payload, headers=auth(token))
    b = await client.post("/v1/translate", json=payload, headers=auth(token2))
    assert a.json()["replay"] is False and b.json()["replay"] is False


async def test_key_order_does_not_change_the_request_identity(client, acct):
    _, _, token, _ = acct
    rid = new_rid()
    a = await client.post("/v1/translate", json=body({"k1": "Hello", "k2": "World"}, request_id=rid), headers=auth(token))
    b = await client.post("/v1/translate", json=body({"k2": "World", "k1": "Hello"}, request_id=rid), headers=auth(token))
    assert a.status_code == 200 and b.status_code == 200 and b.json()["replay"] is True


async def test_failed_request_retried_with_same_id_charges_once(make_client, q, make_account, make_site, grant_month):
    stub = StubProvider(error=ProviderError("down"), fail_times=1)
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)
    payload = body()

    failed = await client.post("/v1/translate", json=payload, headers=auth(token))
    assert failed.status_code == 502
    assert (await _bucket(q, bucket))["used"] == 0

    retried = await client.post("/v1/translate", json=payload, headers=auth(token))

    assert retried.status_code == 200
    assert retried.json()["billed_chars"] == 10 and retried.json()["replay"] is False
    assert (await _bucket(q, bucket))["used"] == 10
    res = await q("SELECT status, attempts, billed_chars FROM reservations")
    assert res == [{"status": "committed", "attempts": 2, "billed_chars": 10}]
    assert len(await q("SELECT 1 FROM ledger WHERE kind = 'commit'")) == 1


async def test_attempts_cap(make_client, q, make_account, make_site, grant_month):
    stub = StubProvider(error=ProviderError("down"))
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 1000)
    payload = body()

    codes = [(await client.post("/v1/translate", json=payload, headers=auth(token))).status_code for _ in range(3)]
    assert codes == [502, 502, 502]  # max_attempts = 3

    r = await client.post("/v1/translate", json=payload, headers=auth(token))
    assert r.status_code == 409 and r.json()["error"]["code"] == "retry_limit"
    assert stub.calls == 3


async def test_parallel_duplicate_is_charged_once_and_the_other_gets_409(make_client, q, make_account, make_site, grant_month):
    stub = StubProvider(delay=0.4)
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)
    payload = body()

    a, b = await asyncio.gather(
        client.post("/v1/translate", json=payload, headers=auth(token)),
        client.post("/v1/translate", json=payload, headers=auth(token)),
    )

    statuses = sorted([a.status_code, b.status_code])
    assert statuses == [200, 409]
    loser = a if a.status_code == 409 else b
    assert loser.json()["error"]["code"] == "request_in_progress"
    assert stub.calls == 1
    assert (await _bucket(q, bucket))["used"] == 10
    assert len(await q("SELECT 1 FROM reservations")) == 1
