"""Rate limits, in-flight cap, spend kill switch, payload size limits and input validation."""

from __future__ import annotations

import asyncio

import pytest
from sqlalchemy import text

from tests.conftest import auth
from tests.helpers import StubProvider, body, new_rid


async def _setup(make_client, make_account, make_site, grant_month, *, limits=None, provider=None, **kw):
    client, app = await make_client(limits=limits, provider=provider, **kw)
    account_id = await make_account()
    site_id, token = await make_site(account_id)
    await grant_month(account_id, 100_000)
    return client, account_id, site_id, token


# --- rate limits ------------------------------------------------------------


async def test_per_site_requests_per_minute(make_client, make_account, make_site, grant_month):
    stub = StubProvider()
    client, _, _, token = await _setup(
        make_client, make_account, make_site, grant_month,
        limits={"per_site_requests_per_minute": 2}, provider=stub,
    )

    codes = [(await client.post("/v1/translate", json=body(), headers=auth(token))).status_code for _ in range(2)]
    assert codes == [200, 200]
    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 429
    assert r.json()["error"]["code"] == "rate_limited"
    assert r.headers["retry-after"] == "60"
    assert stub.calls == 2


async def test_rate_limit_is_per_site_not_per_account(make_client, make_account, make_site, grant_month):
    client, account_id, _, token_a = await _setup(
        make_client, make_account, make_site, grant_month, limits={"per_site_requests_per_minute": 1}
    )
    _, token_b = await make_site(account_id, "other.example.com")

    assert (await client.post("/v1/translate", json=body(), headers=auth(token_a))).status_code == 200
    assert (await client.post("/v1/translate", json=body(), headers=auth(token_a))).status_code == 429
    assert (await client.post("/v1/translate", json=body(), headers=auth(token_b))).status_code == 200


async def test_in_flight_cap_returns_429(make_client, q, make_account, make_site, grant_month):
    stub = StubProvider(delay=0.5)
    client, _, _, token = await _setup(
        make_client, make_account, make_site, grant_month, limits={"max_in_flight_per_account": 1}, provider=stub
    )

    first = asyncio.ensure_future(client.post("/v1/translate", json=body(), headers=auth(token)))
    await asyncio.sleep(0.2)  # let the first request reserve and enter the provider
    second = await client.post("/v1/translate", json=body(), headers=auth(token))
    first = await first

    assert first.status_code == 200
    assert second.status_code == 429
    assert second.json()["error"]["code"] == "too_many_in_flight"
    assert second.headers["retry-after"] == "5"
    assert stub.calls == 1
    assert len(await q("SELECT 1 FROM reservations")) == 1


# --- spend kill switch --------------------------------------------------------


async def test_daily_spend_limit_blocks_the_provider(make_client, q, sessionmaker, make_account, make_site, grant_month):
    stub = StubProvider()
    client, account_id, site_id, token = await _setup(
        make_client, make_account, make_site, grant_month, provider=stub, daily_spend_limit_usd=1
    )
    async with sessionmaker() as s, s.begin():
        await s.execute(
            text(
                "INSERT INTO usage_log (account_id, site_id, source_locale, target_locale, items, chars, model, "
                "cost_micro_usd, outcome) VALUES (:a, :s, 'en', 'de', 1, 10, 'stub', 1000000, 'ok')"
            ),
            {"a": account_id, "s": site_id},
        )

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 503
    assert r.json()["error"]["code"] == "service_budget_exhausted"
    assert r.headers["retry-after"] == "3600"
    assert stub.calls == 0
    assert await q("SELECT 1 FROM reservations") == []


async def test_spend_below_the_limit_still_works(make_client, sessionmaker, make_account, make_site, grant_month):
    client, account_id, site_id, token = await _setup(
        make_client, make_account, make_site, grant_month, daily_spend_limit_usd=1
    )
    async with sessionmaker() as s, s.begin():
        await s.execute(
            text(
                "INSERT INTO usage_log (account_id, site_id, source_locale, target_locale, items, chars, model, "
                "cost_micro_usd, outcome) VALUES (:a, :s, 'en', 'de', 1, 10, 'stub', 990000, 'ok')"
            ),
            {"a": account_id, "s": site_id},
        )
    assert (await client.post("/v1/translate", json=body(), headers=auth(token))).status_code == 200


# --- 413 ----------------------------------------------------------------------


@pytest.mark.parametrize(
    "limits, items",
    [
        ({"max_items": 3}, {"a": "x", "b": "y", "c": "z", "d": "w"}),
        ({"max_chars_per_item": 5}, {"a": "123456"}),
        ({"max_chars_per_request": 9}, {"a": "Hello", "b": "World"}),
    ],
    ids=["items", "chars-per-item", "chars-per-request"],
)
async def test_payload_limits_give_413_and_never_reach_the_provider(
    make_client, q, make_account, make_site, grant_month, limits, items
):
    stub = StubProvider()
    client, _, _, token = await _setup(make_client, make_account, make_site, grant_month, limits=limits, provider=stub)

    r = await client.post("/v1/translate", json=body(items), headers=auth(token))

    assert r.status_code == 413
    assert r.json()["error"]["code"] == "payload_too_large"
    assert set(r.json()["error"]) == {"code", "message"}
    assert stub.calls == 0
    assert await q("SELECT 1 FROM reservations") == []


async def test_limits_are_inclusive(make_client, make_account, make_site, grant_month):
    client, _, _, token = await _setup(
        make_client, make_account, make_site, grant_month,
        limits={"max_items": 2, "max_chars_per_item": 5, "max_chars_per_request": 10},
    )
    r = await client.post("/v1/translate", json=body({"a": "Hello", "b": "World"}), headers=auth(token))
    assert r.status_code == 200


async def test_body_over_limit_with_content_length_is_413(make_client, make_account, make_site, grant_month):
    stub = StubProvider()
    client, _, _, token = await _setup(
        make_client, make_account, make_site, grant_month, limits={"max_body_bytes": 2000}, provider=stub
    )
    payload = b'{"pad": "' + b"a" * 3000 + b'"}'

    r = await client.post(
        "/v1/translate", content=payload, headers={**auth(token), "Content-Type": "application/json"}
    )

    assert r.status_code == 413
    assert r.json()["error"]["code"] == "payload_too_large"
    assert stub.calls == 0


async def test_chunked_body_over_limit_is_413(make_client, make_account, make_site, grant_month):
    stub = StubProvider()
    client, _, _, token = await _setup(
        make_client, make_account, make_site, grant_month, limits={"max_body_bytes": 2000}, provider=stub
    )

    async def stream():
        yield b'{"pad": "'
        for _ in range(5):
            yield b"a" * 1000
        yield b'"}'

    request = client.build_request(
        "POST", "/v1/translate", content=stream(), headers={**auth(token), "Content-Type": "application/json"}
    )
    assert "content-length" not in request.headers and request.headers["transfer-encoding"] == "chunked"
    r = await client.send(request)

    assert r.status_code == 413
    assert r.json()["error"]["code"] == "payload_too_large"
    assert stub.calls == 0


async def test_body_just_under_the_limit_passes(make_client, make_account, make_site, grant_month):
    client, _, _, token = await _setup(make_client, make_account, make_site, grant_month, limits={"max_body_bytes": 2000})
    r = await client.post("/v1/translate", json=body(), headers=auth(token))
    assert r.status_code == 200


# --- 422 ----------------------------------------------------------------------


@pytest.mark.parametrize(
    "over, field",
    [
        ({"source_locale": "english please"}, "source_locale"),
        ({"target_locale": "d"}, "target_locale"),
        ({"target_locale": "de;DROP"}, "target_locale"),
        ({"target_locale": "x" * 40}, "target_locale"),
        ({"request_id": "short"}, "request_id"),
        ({"request_id": "has spaces in it!"}, "request_id"),
        ({"request_id": "a" * 65}, "request_id"),
        ({"items": {}}, "items"),
        ({"items": {"k": "   "}}, "items"),
        ({"items": {"": "Hello"}}, "items"),
        ({"items": ["Hello"]}, "items"),
    ],
)
async def test_invalid_input_is_422(make_client, q, make_account, make_site, grant_month, over, field):
    stub = StubProvider()
    client, _, _, token = await _setup(make_client, make_account, make_site, grant_month, provider=stub)

    r = await client.post("/v1/translate", json=body(**over), headers=auth(token))

    assert r.status_code == 422
    error = r.json()["error"]
    assert error["code"] == "validation_error"
    assert any(d["field"].startswith(f"body.{field}") for d in error["details"])
    assert stub.calls == 0
    assert await q("SELECT 1 FROM reservations") == []


async def test_valid_locale_forms_are_accepted(client, make_account, make_site, grant_month):
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 1000)
    for locale in ("de", "de_DE", "pt-BR", "zh_Hans_CN", "fil"):
        r = await client.post("/v1/translate", json=body(target_locale=locale, request_id=new_rid()), headers=auth(token))
        assert r.status_code == 200, locale


async def test_too_many_glossary_entries_is_422(make_client, make_account, make_site, grant_month):
    client, _, _, token = await _setup(make_client, make_account, make_site, grant_month, limits={"max_glossary_entries": 2})
    r = await client.post("/v1/translate", json=body(glossary={"a": "1", "b": "2", "c": "3"}), headers=auth(token))
    assert r.status_code == 422 and r.json()["error"]["code"] == "validation_error"
