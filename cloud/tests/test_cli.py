"""Admin CLI commands, run against the test database (the async command functions, not a subprocess)."""

from __future__ import annotations

import re
from datetime import datetime, timedelta, timezone

import pytest
from sqlalchemy import text

from app import cli
from app.security import hash_token
from tests.conftest import PEPPER, auth
from tests.helpers import body, new_rid, reserve


@pytest.fixture
def run(sessionmaker, settings, capsys):
    """run("grant-month", "--account", "1") -> stdout of the command."""

    async def _run(*argv: str) -> str:
        capsys.readouterr()
        args = cli.build_parser().parse_args(list(argv))
        await args.func(sessionmaker, settings, args)
        return capsys.readouterr().out

    return _run


def _id(out: str, label: str) -> int:
    return int(re.search(rf"{label} id=(\d+)", out).group(1))


async def test_create_account(run, q):
    out = await run("create-account", "--name", "Acme", "--email", "a@example.com", "--plan", "pro")
    acc = (await q("SELECT * FROM accounts WHERE id = :i", i=_id(out, "account")))[0]
    assert (acc["name"], acc["email"], acc["plan"], acc["status"]) == ("Acme", "a@example.com", "pro", "active")


def test_unknown_plan_and_pack_are_rejected_by_the_parser():
    for argv in (["create-account", "--name", "x", "--plan", "gold"], ["grant-pack", "--account", "1", "--pack", "huge"]):
        with pytest.raises(SystemExit) as exc:
            cli.build_parser().parse_args(argv)
        assert exc.value.code == 2


async def test_add_site_prints_the_token_once_and_stores_only_its_hash(run, q):
    acc = _id(await run("create-account", "--name", "Acme", "--plan", "starter"), "account")

    out = await run("add-site", "--account", str(acc), "--name", "shop.example.com")

    token = re.search(r"^(lcc_\S+)$", out, re.M).group(1)
    assert out.count(token) == 1
    assert "shown once" in out
    site = (await q("SELECT * FROM sites WHERE id = :i", i=_id(out, "site")))[0]
    assert bytes(site["token_hash"]) == hash_token(token, PEPPER)
    assert site["token_prefix"] == token[:12] and site["revoked_at"] is None
    assert token not in repr(site)
    listing = await run("list-sites", "--account", str(acc))
    assert token not in listing and token[:12] in listing


async def test_add_site_respects_the_plan_site_limit(run, q):
    starter = _id(await run("create-account", "--name", "S", "--plan", "starter"), "account")  # 1 site
    await run("add-site", "--account", str(starter), "--name", "one.example.com")
    with pytest.raises(SystemExit) as exc:
        await run("add-site", "--account", str(starter), "--name", "two.example.com")
    assert "allows 1 site" in str(exc.value)

    pro = _id(await run("create-account", "--name", "P", "--plan", "pro"), "account")  # 3 sites
    for n in range(3):
        await run("add-site", "--account", str(pro), "--name", f"s{n}.example.com")
    with pytest.raises(SystemExit):
        await run("add-site", "--account", str(pro), "--name", "s4.example.com")
    assert len(await q("SELECT 1 FROM sites WHERE account_id = :a", a=pro)) == 3


async def test_revoking_a_site_frees_a_slot_and_blocks_its_token(run, client, q, grant_month):
    acc = _id(await run("create-account", "--name", "S", "--plan", "starter"), "account")
    out = await run("add-site", "--account", str(acc), "--name", "one.example.com")
    token = re.search(r"^(lcc_\S+)$", out, re.M).group(1)
    await grant_month(acc, 1000)
    assert (await client.get("/v1/account", headers=auth(token))).status_code == 200

    assert "revoked" in await run("revoke-site", "--site", str(_id(out, "site")))

    assert (await client.get("/v1/account", headers=auth(token))).status_code == 401
    await run("add-site", "--account", str(acc), "--name", "new.example.com")  # slot is free again


async def test_add_site_errors(run):
    with pytest.raises(SystemExit):
        await run("add-site", "--account", "999999", "--name", "x")
    no_plan = _id(await run("create-account", "--name", "NoPlan"), "account")
    with pytest.raises(SystemExit) as exc:
        await run("add-site", "--account", str(no_plan), "--name", "x")
    assert "no plan" in str(exc.value)


async def test_grant_month_is_idempotent(run, q):
    acc = _id(await run("create-account", "--name", "Acme", "--plan", "starter"), "account")

    first = await run("grant-month", "--account", str(acc), "--start", "2026-10-01")
    second = await run("grant-month", "--account", str(acc), "--start", "2026-10-01")

    assert "created" in first and "already existed" in second
    assert _id(first, "bucket") == _id(second, "bucket")
    rows = await q("SELECT kind, granted, valid_from, expires_at FROM quota_buckets WHERE account_id = :a", a=acc)
    assert len(rows) == 1
    assert (rows[0]["kind"], rows[0]["granted"]) == ("monthly", 100_000)  # starter plan
    assert rows[0]["valid_from"] == datetime(2026, 10, 1, tzinfo=timezone.utc)
    assert rows[0]["expires_at"] == datetime(2026, 11, 1, tzinfo=timezone.utc)
    assert len(await q("SELECT 1 FROM ledger WHERE kind = 'grant'")) == 1

    # another period is a new bucket; the default start (today 00:00 UTC) is idempotent too
    await run("grant-month", "--account", str(acc), "--start", "2026-11-01")
    await run("grant-month", "--account", str(acc))
    await run("grant-month", "--account", str(acc))
    assert len(await q("SELECT 1 FROM quota_buckets WHERE account_id = :a", a=acc)) == 3


async def test_grant_month_without_plan_fails(run):
    acc = _id(await run("create-account", "--name", "NoPlan"), "account")
    with pytest.raises(SystemExit):
        await run("grant-month", "--account", str(acc))


def test_add_months_clamps_the_day():
    assert cli.add_months(datetime(2026, 1, 31, tzinfo=timezone.utc), 1) == datetime(2026, 2, 28, tzinfo=timezone.utc)
    assert cli.add_months(datetime(2026, 12, 15, tzinfo=timezone.utc), 1) == datetime(2027, 1, 15, tzinfo=timezone.utc)
    assert cli.add_months(datetime(2024, 1, 31, tzinfo=timezone.utc), 1) == datetime(2024, 2, 29, tzinfo=timezone.utc)


async def test_grant_pack(run, q):
    acc = _id(await run("create-account", "--name", "Acme", "--plan", "starter"), "account")

    first = await run("grant-pack", "--account", str(acc), "--pack", "pack_250k", "--ref", "pay_1")
    again = await run("grant-pack", "--account", str(acc), "--pack", "pack_250k", "--ref", "pay_1")
    other = await run("grant-pack", "--account", str(acc), "--pack", "pack_1m", "--ref", "pay_2")

    assert "created" in first and "already existed" in again and "created" in other
    rows = await q("SELECT kind, granted, source_ref, expires_at FROM quota_buckets WHERE account_id = :a ORDER BY id", a=acc)
    assert [(r["kind"], r["granted"], r["source_ref"]) for r in rows] == [
        ("pack", 250_000, "pay_1"),
        ("pack", 1_000_000, "pay_2"),
    ]
    assert rows[0]["expires_at"] > datetime.now(timezone.utc) + timedelta(days=360)


async def test_revoke_bucket_takes_back_only_unused_characters(run, q, sessionmaker, make_site):
    acc = _id(await run("create-account", "--name", "Acme", "--plan", "starter"), "account")
    bucket = _id(await run("grant-month", "--account", str(acc), "--start", "2026-01-01"), "bucket")
    # the bucket above is long expired; make it live so a reservation can use it
    now = datetime.now(timezone.utc)
    async with sessionmaker() as s, s.begin():
        await s.execute(
            text("UPDATE quota_buckets SET valid_from = :f, expires_at = :e WHERE id = :i"),
            {"f": now - timedelta(days=1), "e": now + timedelta(days=29), "i": bucket},
        )
    site_id, _ = await make_site(acc)
    await reserve(sessionmaker, acc, site_id, new_rid(), 300)

    out = await run("revoke-bucket", "--bucket", str(bucket))

    assert "revoked 99700 unused characters" in out
    row = (await q("SELECT granted, used, reserved, revoked_at FROM quota_buckets WHERE id = :i", i=bucket))[0]
    assert (row["granted"], row["used"], row["reserved"]) == (300, 0, 300)
    assert row["revoked_at"] is not None
    assert (await q("SELECT chars FROM ledger WHERE kind = 'revoke'")) == [{"chars": 99_700}]
    with pytest.raises(SystemExit):
        await run("revoke-bucket", "--bucket", "999999")


async def test_usage_and_spend(run, client, make_account, make_site, grant_month):
    acc = await make_account()
    _, token = await make_site(acc)
    await grant_month(acc, 1000)
    for _ in range(2):
        assert (await client.post("/v1/translate", json=body(), headers=auth(token))).status_code == 200

    usage = await run("usage", "--account", str(acc), "--days", "7")
    assert "customer billing: requests=2 billed_chars=20 ledger_commit_chars=20" in usage
    assert re.search(r"provider work:\s+calls=2 sent_chars=20 returned_chars=20 ", usage)
    assert re.search(r"cost_usd=\d+\.\d{6}", usage)

    spend = await run("spend", "--days", "3")
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    assert f"{today} calls=2" in spend
    assert re.search(r"total cost_usd=\d+\.\d{6}", spend)
    # nothing here may contain client text
    assert "Hello" not in usage + spend

    other = await make_account(name="Other")
    empty = await run("usage", "--account", str(other))
    assert "requests=0 billed_chars=0" in empty and "calls=0" in empty


async def test_usage_bills_a_request_once_even_when_replayed(run, client, make_account, make_site, grant_month):
    acc = await make_account()
    _, token = await make_site(acc)
    await grant_month(acc, 1000)
    payload = body()
    for n in range(3):  # original + 2 replays (the default replay cap)
        r = await client.post("/v1/translate", json=payload, headers=auth(token))
        assert r.status_code == 200 and r.json()["replay"] is (n > 0)

    usage = await run("usage", "--account", str(acc))

    assert "customer billing: requests=1 billed_chars=10 ledger_commit_chars=10" in usage
    # the provider really worked three times (replays are re-translated, free for the customer)
    assert re.search(r"provider work:\s+calls=3 sent_chars=30 returned_chars=30 ", usage)


async def test_grant_conflict_is_a_clear_nonzero_exit(run, q):
    acc = _id(await run("create-account", "--name", "Acme", "--plan", "starter"), "account")
    await run("grant-pack", "--account", str(acc), "--pack", "pack_250k", "--ref", "pay_x")
    with pytest.raises(SystemExit) as exc:
        await run("grant-pack", "--account", str(acc), "--pack", "pack_1m", "--ref", "pay_x")
    assert exc.value.code not in (0, None) and "different grant" in str(exc.value.code)
    assert len(await q("SELECT 1 FROM quota_buckets")) == 1


async def test_sweep_command(run):
    assert "released 0 reservation(s)" in await run("sweep")
