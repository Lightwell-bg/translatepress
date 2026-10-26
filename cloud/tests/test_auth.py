"""Authentication: bearer tokens, revocation, suspended accounts, hash-only storage."""

from __future__ import annotations

from sqlalchemy import text

from app.security import hash_token
from tests.conftest import PEPPER, auth
from tests.helpers import body


async def test_missing_garbage_and_revoked_tokens_give_identical_401(
    client, sessionmaker, make_account, make_site, grant_month
):
    acc = await make_account()
    site_id, token = await make_site(acc)
    await grant_month(acc, 1000)

    ok = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert ok.status_code == 200

    async with sessionmaker() as s, s.begin():
        await s.execute(text("UPDATE sites SET revoked_at = now() WHERE id = :i"), {"i": site_id})

    responses = [
        await client.post("/v1/translate", json=body()),
        await client.post("/v1/translate", json=body(), headers=auth("lcc_not-a-real-token")),
        await client.post("/v1/translate", json=body(), headers={"Authorization": "Basic dXNlcjpwYXNz"}),
        await client.post("/v1/translate", json=body(), headers={"Authorization": "Bearer "}),
        await client.post("/v1/translate", json=body(), headers=auth("x" * 500)),
        await client.post("/v1/translate", json=body(), headers=auth(token)),  # revoked
    ]
    for r in responses:
        assert r.status_code == 401
        assert r.headers["www-authenticate"] == "Bearer"
    assert len({r.text for r in responses}) == 1
    assert responses[0].json() == {"error": {"code": "invalid_token", "message": "Missing or invalid token."}}


async def test_suspended_account_gets_403(client, mock_provider, make_account, make_site, grant_month):
    acc = await make_account(status="suspended")
    _, token = await make_site(acc)
    await grant_month(acc, 1000)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert r.status_code == 403
    assert r.json()["error"]["code"] == "account_suspended"
    assert (await client.get("/v1/account", headers=auth(token))).status_code == 403
    assert mock_provider.calls == 0


async def test_database_stores_only_the_token_hash(client, sessionmaker, make_account, make_site):
    acc = await make_account()
    site_id, token = await make_site(acc)

    async with sessionmaker() as s:
        row = (await s.execute(text("SELECT * FROM sites WHERE id = :i"), {"i": site_id})).one()._mapping
    stored = " ".join(repr(v) for v in row.values())
    assert token not in stored
    assert token[4:] not in stored
    assert bytes(row["token_hash"]) == hash_token(token, PEPPER)
    assert len(bytes(row["token_hash"])) == 32
    assert row["token_prefix"] == token[:12]
    # a different pepper gives a different hash: a leaked DB alone cannot be checked against guesses
    assert hash_token(token, "another-pepper-" + "y" * 40) != hash_token(token, PEPPER)


async def test_two_sites_have_different_tokens_and_both_work(client, make_account, make_site, grant_month):
    acc = await make_account(plan="pro")
    _, token_a = await make_site(acc, "a.example.com")
    _, token_b = await make_site(acc, "b.example.com")
    await grant_month(acc, 1000)
    assert token_a != token_b

    ra = await client.post("/v1/translate", json=body(), headers=auth(token_a))
    rb = await client.post("/v1/translate", json=body(), headers=auth(token_b))
    assert ra.status_code == 200 and rb.status_code == 200
    # one shared account quota: 10 + 10 chars spent
    assert rb.json()["quota"]["monthly_remaining"] == 980


async def test_revoking_one_site_does_not_affect_the_other(
    client, sessionmaker, make_account, make_site, grant_month
):
    acc = await make_account(plan="pro")
    site_a, token_a = await make_site(acc, "a.example.com")
    _, token_b = await make_site(acc, "b.example.com")
    await grant_month(acc, 1000)

    async with sessionmaker() as s, s.begin():
        await s.execute(text("UPDATE sites SET revoked_at = now() WHERE id = :i"), {"i": site_a})

    assert (await client.post("/v1/translate", json=body(), headers=auth(token_a))).status_code == 401
    assert (await client.post("/v1/translate", json=body(), headers=auth(token_b))).status_code == 200


async def test_last_used_at_is_recorded(client, q, make_account, make_site, grant_month):
    acc = await make_account()
    site_id, token = await make_site(acc)
    await grant_month(acc, 1000)
    assert (await q("SELECT last_used_at FROM sites WHERE id = :i", i=site_id))[0]["last_used_at"] is None
    await client.get("/v1/account", headers=auth(token))
    assert (await q("SELECT last_used_at FROM sites WHERE id = :i", i=site_id))[0]["last_used_at"] is not None
