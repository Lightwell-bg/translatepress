"""Regression tests for the Codex review findings: attempt fencing, deadline, replay limits,
spend admission, glossary limits, 422 privacy, grant conflicts, cancellation, lifespan sweeper.

Concurrency is driven by ``GatedProvider`` events, not by sleeps.
"""

from __future__ import annotations

import asyncio
from datetime import timedelta

import pytest
from sqlalchemy import text

from app import quota
from app.pricing import Pricing
from app.providers.base import ProviderRejected, ProviderTimeout
from app.providers.prompt import build_messages
from app.sweeper import sweep_once
from app.translate_service import ServiceConfig, SiteIdentity, TranslateRequest, TranslationService
from tests.conftest import auth
from tests.helpers import GatedProvider, StubProvider, body, grant_raw, new_rid, reserve, settle, utcnow

# body() default: 2 items, 10 chars. The service estimates int(0.6 * 10) + 400 = 406 prompt tokens
# and max(256, int(1.5 * 10) + 16 * 2 + 256) = 303 output tokens for the first call.
FIRST_CALL_TOKENS = (406, 303)


async def _setup(make_client, make_account, make_site, grant_month, *, limits=None, provider=None, chars=100_000, **kw):
    client, app = await make_client(limits=limits, provider=provider, **kw)
    account_id = await make_account()
    site_id, token = await make_site(account_id)
    bucket = await grant_month(account_id, chars)
    return client, app, account_id, site_id, token, bucket


async def _bucket(q, bucket_id: int) -> dict:
    return (await q("SELECT * FROM quota_buckets WHERE id = :i", i=bucket_id))[0]


def _service(sessionmaker, settings, provider, *, deadline: float = 150.0, ttl: int = 300) -> TranslationService:
    limits = quota.Limits(
        reservation_ttl=timedelta(seconds=ttl),
        per_site_requests_per_minute=1000,
        max_in_flight_per_account=1000,
        max_replays=2,
        max_attempts=3,
    )
    return TranslationService(
        sessionmaker,
        provider,
        Pricing(settings.cfg.pricing),
        ServiceConfig(
            limits=limits, daily_spend_limit_micro_usd=5_000_000, max_output_tokens=32000, call_deadline_seconds=deadline
        ),
    )


def _treq(items=None) -> TranslateRequest:
    return TranslateRequest(
        request_id=new_rid(), source_locale="en", target_locale="de", target_label="de",
        items=items or {"k1": "Hello", "k2": "World"}, glossary={},
    )


# --- a. attempt fencing: A -> sweep -> B -> A completes ---------------------------------


@pytest.fixture
async def acct(make_account, make_site):
    account_id = await make_account()
    site_id, _ = await make_site(account_id)
    return account_id, site_id


async def test_stale_attempt_settle_is_a_noop_and_leaves_the_newer_attempt_alone(sessionmaker, q, acct):
    account_id, site_id = acct
    bucket = await grant_raw(sessionmaker, account_id, "monthly", 100, valid_from=utcnow() - timedelta(hours=1))
    rid = new_rid()
    a = await reserve(sessionmaker, account_id, site_id, rid, 40, now=utcnow() - timedelta(minutes=10))
    assert a.attempt == 1
    assert await sweep_once(sessionmaker) == 1  # clock is past the TTL: A is released

    b = await reserve(sessionmaker, account_id, site_id, rid, 40)  # same request id: a retry

    assert (b.kind, b.attempt, b.reservation_id) == ("retry", 2, a.reservation_id)
    stale = await settle(sessionmaker, account_id, a.reservation_id, 40, attempt=a.attempt)
    assert stale.settled is False and stale.billed == 0
    row = (await q("SELECT status, attempts, billed_chars FROM reservations"))[0]
    assert row == {"status": "pending", "attempts": 2, "billed_chars": 0}
    assert (await _bucket(q, bucket))["reserved"] == 40  # B's reservation is intact

    fresh = await settle(sessionmaker, account_id, b.reservation_id, 40, attempt=b.attempt)
    assert fresh.settled is True and fresh.billed == 40
    b_row = await _bucket(q, bucket)
    assert (b_row["used"], b_row["reserved"]) == (40, 0)
    assert (await q("SELECT COALESCE(SUM(chars), 0) AS n FROM ledger WHERE kind = 'commit'"))[0]["n"] == 40


async def test_api_slow_first_attempt_is_not_delivered_after_a_sweep_and_retry(
    make_client, make_account, make_site, grant_month, q
):
    gated = GatedProvider(gated={1, 2})
    client, app, account_id, _, token, bucket = await _setup(
        make_client, make_account, make_site, grant_month, provider=gated
    )
    payload = body()

    task_a = asyncio.ensure_future(client.post("/v1/translate", json=payload, headers=auth(token)))
    await gated.entered(1)
    assert await sweep_once(app.state.sessionmaker, now=utcnow() + timedelta(seconds=301)) == 1  # A swept
    task_b = asyncio.ensure_future(client.post("/v1/translate", json=payload, headers=auth(token)))
    await gated.entered(2)

    gated.release(1)  # A comes back from the provider with a result, after the sweep
    resp_a = await task_a

    assert resp_a.status_code == 504
    assert resp_a.json()["error"]["code"] == "provider_timeout"
    assert "translations" not in resp_a.json()
    row = (await q("SELECT status, attempts FROM reservations"))[0]
    assert row == {"status": "pending", "attempts": 2}  # B untouched
    assert (await _bucket(q, bucket))["reserved"] == 10

    gated.release(2)
    resp_b = await task_b

    assert resp_b.status_code == 200
    assert resp_b.json()["billed_chars"] == 10 and resp_b.json()["replay"] is False
    assert set(resp_b.json()["translations"]) == {"k1", "k2"}
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (10, 0)  # one attempt charged, not two
    assert (await q("SELECT COALESCE(SUM(chars), 0) AS n FROM ledger WHERE kind = 'commit'"))[0]["n"] == 10
    assert (await q("SELECT status, attempts, billed_chars FROM reservations"))[0] == {
        "status": "committed", "attempts": 2, "billed_chars": 10,
    }


# --- b. overall deadline ---------------------------------------------------------------


async def test_overall_deadline_gives_504_releases_and_books_the_worst_case(
    make_client, make_account, make_site, grant_month, q, settings
):
    gated = GatedProvider(gated={1})  # never released: the provider hangs
    client, _, _, _, token, bucket = await _setup(
        make_client, make_account, make_site, grant_month, provider=gated,
        limits={"reservation_ttl_seconds": 31, "call_deadline_seconds": 0.2},
    )

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 504 and r.json()["error"]["code"] == "provider_timeout"
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)
    assert (await q("SELECT status, billed_chars FROM reservations"))[0] == {"status": "released", "billed_chars": 0}
    rows = await q("SELECT outcome, returned_chars, cost_micro_usd FROM usage_log")
    assert len(rows) == 1 and rows[0]["outcome"] == "timeout" and rows[0]["returned_chars"] == 0
    assert rows[0]["cost_micro_usd"] == Pricing(settings.cfg.pricing).worst_case_micro_usd(*FIRST_CALL_TOKENS) > 0


async def test_deadline_must_leave_30_seconds_before_the_reservation_ttl(sessionmaker, settings):
    with pytest.raises(ValueError):
        _service(sessionmaker, settings, StubProvider(), deadline=271, ttl=300)
    _service(sessionmaker, settings, StubProvider(), deadline=270, ttl=300)  # exactly 30 s is fine


# --- c. replays obey the limits ---------------------------------------------------------


async def test_replay_is_refused_while_the_in_flight_slot_is_taken(
    make_client, make_account, make_site, grant_month, q
):
    gated = GatedProvider(gated={2})
    client, _, _, _, token, _ = await _setup(
        make_client, make_account, make_site, grant_month, provider=gated, limits={"max_in_flight_per_account": 1}
    )
    done = body()
    assert (await client.post("/v1/translate", json=done, headers=auth(token))).status_code == 200  # call 1
    pending = asyncio.ensure_future(client.post("/v1/translate", json=body(), headers=auth(token)))  # call 2, gated
    await gated.entered(2)

    r = await client.post("/v1/translate", json=done, headers=auth(token))  # replay of the committed one

    assert r.status_code == 429 and r.json()["error"]["code"] == "too_many_in_flight"
    assert r.headers["retry-after"] == "5"
    assert gated.calls == 2  # the refused replay never reached the provider
    gated.release(2)
    assert (await pending).status_code == 200
    again = await client.post("/v1/translate", json=done, headers=auth(token))
    assert again.status_code == 200 and again.json()["replay"] is True


async def test_a_running_replay_holds_a_slot_and_frees_it_when_done(
    make_client, make_account, make_site, grant_month, q
):
    gated = GatedProvider(gated={2})
    client, _, _, _, token, _ = await _setup(
        make_client, make_account, make_site, grant_month, provider=gated, limits={"max_in_flight_per_account": 1}
    )
    done = body()
    assert (await client.post("/v1/translate", json=done, headers=auth(token))).status_code == 200
    replay = asyncio.ensure_future(client.post("/v1/translate", json=done, headers=auth(token)))  # call 2, gated
    await gated.entered(2)
    assert (await q("SELECT replay_started_at FROM reservations"))[0]["replay_started_at"] is not None

    blocked = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert blocked.status_code == 429 and blocked.json()["error"]["code"] == "too_many_in_flight"
    gated.release(2)
    done_replay = await replay
    assert done_replay.status_code == 200 and done_replay.json()["replay"] is True
    assert done_replay.json()["billed_chars"] == 0
    assert (await q("SELECT replay_started_at, replays FROM reservations"))[0] == {
        "replay_started_at": None, "replays": 1,
    }
    assert (await client.post("/v1/translate", json=body(), headers=auth(token))).status_code == 200  # slot is free


async def test_replays_count_toward_the_site_rate_limit(make_client, make_account, make_site, grant_month, sessionmaker):
    client, _, _, _, token, _ = await _setup(
        make_client, make_account, make_site, grant_month, limits={"per_site_requests_per_minute": 2}
    )
    first, second = body(), body()
    assert (await client.post("/v1/translate", json=first, headers=auth(token))).status_code == 200
    async with sessionmaker() as s, s.begin():  # R1 is old news: it no longer counts
        await s.execute(text("UPDATE reservations SET last_attempt_at = now() - interval '5 minutes'"))
    assert (await client.post("/v1/translate", json=second, headers=auth(token))).status_code == 200
    replay = await client.post("/v1/translate", json=first, headers=auth(token))
    assert replay.status_code == 200 and replay.json()["replay"] is True  # counts again from now on

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 429 and r.json()["error"]["code"] == "rate_limited"


# --- e. spend admission -----------------------------------------------------------------


async def _insert_spend(sessionmaker, account_id, site_id, micro_usd: int) -> None:
    async with sessionmaker() as s, s.begin():
        await s.execute(
            text(
                "INSERT INTO usage_log (account_id, site_id, source_locale, target_locale, items, chars, model, "
                "cost_micro_usd, outcome) VALUES (:a, :s, 'en', 'de', 1, 10, 'stub', :c, 'ok')"
            ),
            {"a": account_id, "s": site_id, "c": micro_usd},
        )


async def test_spend_admission_counts_twice_the_worst_case_of_the_request(
    make_client, make_account, make_site, grant_month, sessionmaker, q, settings
):
    worst_two_calls = 2 * Pricing(settings.cfg.pricing).worst_case_micro_usd(*FIRST_CALL_TOKENS)
    assert worst_two_calls == 972  # pins the estimate the numbers below are built on
    limit = 2000  # micro-USD
    spent_ok = limit - worst_two_calls

    stub = StubProvider()
    client, _, account_id, site_id, token, _ = await _setup(
        make_client, make_account, make_site, grant_month, provider=stub, daily_spend_limit_usd="0.002"
    )
    await _insert_spend(sessionmaker, account_id, site_id, spent_ok + 1)  # one micro-USD too many

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 503 and r.json()["error"]["code"] == "service_budget_exhausted"
    assert stub.calls == 0 and await q("SELECT 1 FROM reservations") == []

    async with sessionmaker() as s, s.begin():
        await s.execute(text("UPDATE usage_log SET cost_micro_usd = :c"), {"c": spent_ok})
    assert (await client.post("/v1/translate", json=body(), headers=auth(token))).status_code == 200  # exactly at the limit


@pytest.mark.parametrize(
    "error, books_worst_case",
    [(ProviderTimeout("x"), True), (ProviderRejected("x"), False)],
    ids=["timeout-unknown-usage", "rejected-not-billed"],
)
async def test_failure_with_unknown_usage_books_the_worst_case(
    make_client, make_account, make_site, grant_month, q, error, books_worst_case
):
    stub = StubProvider(error=error)
    client, _, _, _, token, _ = await _setup(
        make_client, make_account, make_site, grant_month, provider=stub, daily_spend_limit_usd="0.0014"
    )

    first = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert first.status_code in (502, 504)
    cost = (await q("SELECT cost_micro_usd FROM usage_log"))[0]["cost_micro_usd"]
    assert (cost > 0) is books_worst_case

    second = await client.post("/v1/translate", json=body(), headers=auth(token))

    if books_worst_case:  # 486 booked + 972 admission > 1400
        assert second.status_code == 503 and second.json()["error"]["code"] == "service_budget_exhausted"
        assert stub.calls == 1
    else:
        assert second.status_code == 502 and stub.calls == 2


# --- f. glossary limits and placement ---------------------------------------------------


def _glossary(entries: int, size: int = 20) -> dict[str, str]:
    return {f"{n:0{size}d}": "v" * size for n in range(entries)}  # each entry is 2 * size chars


async def test_glossary_over_the_absolute_limit_is_413(make_client, make_account, make_site, grant_month, q):
    stub = StubProvider()
    client, _, _, _, token, _ = await _setup(
        make_client, make_account, make_site, grant_month, provider=stub, limits={"glossary_max_chars": 20}
    )
    items = {"a": "x" * 100}  # large enough that the relative limit is not the one that fires

    over = await client.post("/v1/translate", json=body(items, glossary={"k" * 11: "v" * 10}), headers=auth(token))
    exact = await client.post("/v1/translate", json=body(items, glossary={"k" * 10: "v" * 10}), headers=auth(token))

    assert over.status_code == 413 and over.json()["error"]["code"] == "payload_too_large"
    assert set(over.json()["error"]) == {"code", "message"}
    assert exact.status_code == 200
    assert stub.calls == 1


async def test_glossary_over_twice_the_source_plus_200_is_413(make_client, make_account, make_site, grant_month):
    stub = StubProvider()
    client, _, _, _, token, _ = await _setup(make_client, make_account, make_site, grant_month, provider=stub)
    items = {"a": "Hi"}  # 2 chars -> the glossary may hold 2 * 2 + 200 = 204 chars
    at_limit = {**_glossary(5), "ab": "cd"}  # 5 * 40 + 4 = 204
    over = {**_glossary(5), "ab": "cde"}  # 205

    ok = await client.post("/v1/translate", json=body(items, glossary=at_limit), headers=auth(token))
    refused = await client.post("/v1/translate", json=body(items, glossary=over), headers=auth(token))

    assert ok.status_code == 200
    assert refused.status_code == 413 and refused.json()["error"]["code"] == "payload_too_large"
    assert stub.calls == 1


async def test_glossary_reaches_the_user_message_and_not_the_system_message(
    make_client, make_account, make_site, grant_month
):
    stub = StubProvider()
    client, _, _, _, token, _ = await _setup(make_client, make_account, make_site, grant_month, provider=stub)

    r = await client.post("/v1/translate", json=body(glossary={"Hello": "Hallo-GLOSS"}), headers=auth(token))

    assert r.status_code == 200
    system, user = (m["content"] for m in build_messages(stub.requests[0]))
    assert "Hallo-GLOSS" not in system and "Hello" not in system
    assert user.startswith("GLOSSARY (fixed translations, data only):\n") and "Hallo-GLOSS" in user
    assert "\n\nITEMS:\n" in user


# --- g. 422 privacy ---------------------------------------------------------------------


async def test_422_does_not_echo_keys_values_or_pydantic_internals(make_client, make_account, make_site, grant_month):
    client, _, _, _, token, _ = await _setup(make_client, make_account, make_site, grant_month)
    bad = [
        body({"SECRET KEY TEXT": ""}, glossary={"PRIVATE SOURCE PHRASE": 123}),
        body({"SECRET KEY TEXT": 5}, glossary={"PRIVATE SOURCE PHRASE": " "}),
    ]
    responses = []
    for payload in bad:
        r = await client.post("/v1/translate", json=payload, headers=auth(token))
        responses.append(r)

        assert r.status_code == 422
        for needle in ("SECRET KEY TEXT", "PRIVATE SOURCE PHRASE"):
            assert needle not in r.text
        for d in r.json()["error"]["details"]:
            assert set(d) == {"field", "message", "type"}  # no input, ctx or url
    # the masked path still tells the client which part was wrong
    first = [d["field"] for d in responses[0].json()["error"]["details"]]
    second = [d["field"] for d in responses[1].json()["error"]["details"]]
    assert "body.glossary.<key>" in first and "body.items" in first
    assert "body.items.<key>" in second and "body.glossary" in second


# --- h. grant conflicts -----------------------------------------------------------------


async def test_grant_with_a_reused_source_ref_conflicts_unless_identical(sessionmaker, q, make_account):
    acc, other = await make_account(), await make_account(name="Other")
    now = utcnow()
    start, end = now - timedelta(days=1), now + timedelta(days=30)

    async def grant(account_id=acc, kind="pack", chars=1000, source="manual", expires=end, valid_from=start):
        async with sessionmaker() as s, s.begin():
            return await quota.grant(
                s, account_id=account_id, kind=kind, chars=chars, now=now, source=source,
                source_ref="pay_42", valid_from=valid_from, expires_at=expires,
            )

    bucket_id, created = await grant()
    assert created is True

    assert await grant() == (bucket_id, False)  # identical repeat: idempotent
    for variant in (
        {"account_id": other},
        {"kind": "monthly"},
        {"chars": 999},
        {"source": "stripe_payment"},
        {"expires": end + timedelta(days=1)},
        {"valid_from": start - timedelta(days=1)},
    ):
        with pytest.raises(quota.GrantConflict):
            await grant(**variant)

    assert len(await q("SELECT 1 FROM quota_buckets")) == 1
    assert len(await q("SELECT 1 FROM ledger WHERE kind = 'grant'")) == 1


# --- j. real cancellation ---------------------------------------------------------------


async def test_cancelling_a_request_releases_immediately_and_books_the_worst_case(
    sessionmaker, settings, q, acct, grant_month
):
    account_id, site_id = acct
    bucket = await grant_month(account_id, 1000)
    gated = GatedProvider(gated={1})
    service = _service(sessionmaker, settings, gated)

    task = asyncio.ensure_future(service.translate(SiteIdentity(site_id, account_id), _treq()))
    await gated.entered(1)
    assert (await q("SELECT status FROM reservations"))[0]["status"] == "pending"
    task.cancel()
    with pytest.raises(asyncio.CancelledError):
        await task

    # no sweeper is running: the service itself gave the characters back
    assert (await q("SELECT status, billed_chars FROM reservations"))[0] == {"status": "released", "billed_chars": 0}
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)
    rows = await q("SELECT outcome, returned_chars, cost_micro_usd FROM usage_log")
    assert len(rows) == 1 and rows[0]["outcome"] == "timeout"
    assert rows[0]["cost_micro_usd"] == Pricing(settings.cfg.pricing).worst_case_micro_usd(*FIRST_CALL_TOKENS) > 0


async def test_cancelling_during_the_follow_up_call_releases_immediately(
    sessionmaker, settings, q, acct, grant_month
):
    account_id, site_id = acct
    bucket = await grant_month(account_id, 1000)
    gated = GatedProvider(gated={2}, omit={"2"})  # the first call leaves "World" out -> follow-up call 2
    service = _service(sessionmaker, settings, gated)

    task = asyncio.ensure_future(service.translate(SiteIdentity(site_id, account_id), _treq()))
    await gated.entered(2)
    task.cancel()
    with pytest.raises(asyncio.CancelledError):
        await task

    assert (await q("SELECT status, billed_chars FROM reservations"))[0] == {"status": "released", "billed_chars": 0}
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)
    rows = await q("SELECT outcome, returned_chars, cost_micro_usd FROM usage_log ORDER BY id")
    assert [r["outcome"] for r in rows] == ["partial", "timeout"]
    assert rows[0]["returned_chars"] == 5 and rows[1]["returned_chars"] == 0
    assert rows[1]["cost_micro_usd"] > 0  # the follow-up was in flight: worst case


# --- k. lifespan sweeper ----------------------------------------------------------------


async def test_lifespan_background_sweeper_releases_expired_reservations(
    make_client, make_account, make_site, grant_month, sessionmaker, q
):
    client, app, account_id, site_id, _, bucket = await _setup(
        make_client, make_account, make_site, grant_month, limits={"sweeper_interval_seconds": 1}
    )
    await reserve(sessionmaker, account_id, site_id, new_rid(), 40)
    async with sessionmaker() as s, s.begin():  # the request "crashed" and its TTL ran out
        await s.execute(text("UPDATE reservations SET expires_at = now() - interval '1 minute'"))
    assert (await _bucket(q, bucket))["reserved"] == 40

    async with app.router.lifespan_context(app):
        async with asyncio.timeout(10):
            while (await q("SELECT status FROM reservations"))[0]["status"] != "released":
                await asyncio.sleep(0.05)  # poll the database; the sweeper itself runs in the background

        b = await _bucket(q, bucket)
        assert (b["used"], b["reserved"]) == (0, 0)
