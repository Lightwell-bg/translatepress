"""POST /v1/translate and GET /v1/account: response shape, settle outcomes, error mapping."""

from __future__ import annotations

import pytest

from app.providers.base import (
    ProviderBadOutput,
    ProviderError,
    ProviderRateLimited,
    ProviderTimeout,
    ProviderUnavailable,
    Usage,
)
from app.providers.mock import MockProvider
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


async def test_response_shape(client, acct):
    _, _, token, _ = acct
    rid = new_rid()
    r = await client.post("/v1/translate", json=body(request_id=rid, target_label="Deutsch"), headers=auth(token))
    assert r.status_code == 200
    assert r.json() == {
        "request_id": rid,
        "translations": {"k1": "[de] Hello", "k2": "[de] World"},
        "missing": [],
        "billed_chars": 10,
        "replay": False,
        "quota": {"monthly_remaining": 990, "packs_remaining": 0, "packs_usable": True, "total_remaining": 990},
    }


async def test_billing_unit_is_unicode_code_points_of_the_text_as_sent(client, mock_provider, q, acct):
    _, _, token, bucket = acct
    items = {"a": "Привет", "b": "  padded  ", "c": "emoji \U0001F600"}  # 6 + 10 + 7 code points
    r = await client.post("/v1/translate", json=body(items), headers=auth(token))
    assert r.status_code == 200
    assert r.json()["billed_chars"] == 23
    assert (await _bucket(q, bucket))["used"] == 23


async def test_glossary_and_label_reach_the_provider_and_unknown_fields_are_ignored(client, mock_provider, acct):
    _, _, token, _ = acct
    payload = body(target_label="Deutsch", glossary={"Cart": "Warenkorb"}, extra_field="ignored")
    r = await client.post("/v1/translate", json=payload, headers=auth(token))
    assert r.status_code == 200
    req = mock_provider.requests[0]
    assert req.target_label == "Deutsch" and req.glossary == {"Cart": "Warenkorb"}
    assert (req.source_locale, req.target_locale) == ("en", "de")


async def test_account_endpoint(client, make_account, make_site, grant_month, grant_pack):
    account_id = await make_account(plan="pro")
    _, token = await make_site(account_id)
    await make_site(account_id, "second.example.com")
    await grant_month(account_id, 1000)
    await grant_pack(account_id, 500)
    await client.post("/v1/translate", json=body(), headers=auth(token))

    r = await client.get("/v1/account", headers=auth(token))

    assert r.status_code == 200
    data = r.json()
    assert data["account"] == {"id": account_id, "name": "Test account", "plan": "pro", "status": "active"}
    assert data["sites"] == {"used": 2, "max": 3}
    assert data["quota"] == {"monthly_remaining": 990, "packs_remaining": 500, "packs_usable": True, "total_remaining": 1490}
    assert data["billed_chars_this_month"] == 10


async def test_unknown_route_and_method_use_the_error_format(client):
    r = await client.get("/nope")
    assert r.status_code == 404 and r.json()["error"]["code"] == "not_found"
    r = await client.get("/v1/translate")
    assert r.status_code == 405 and r.json()["error"]["code"] == "method_not_allowed"


# --- failures release the reservation -------------------------------------


@pytest.mark.parametrize(
    "error, status, code, outcome",
    [
        (ProviderError("x"), 502, "provider_error", "provider_error"),
        (ProviderTimeout("x"), 504, "provider_timeout", "timeout"),
        (ProviderBadOutput("x", Usage(prompt_tokens=30, completion_tokens=12, cache_miss_tokens=30)), 502, "provider_bad_output", "bad_output"),
        (ProviderUnavailable("x"), 503, "provider_unavailable", "provider_error"),
        (ProviderRateLimited("x"), 503, "provider_busy", "rate_limited"),
    ],
)
async def test_provider_failure_releases_everything(
    make_client, q, make_account, make_site, grant_month, error, status, code, outcome
):
    stub = StubProvider(error=error)
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == status
    assert r.json()["error"]["code"] == code
    assert set(r.json()["error"]) == {"code", "message"}
    assert stub.calls == 1  # failures are never retried inside the service
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)
    kinds = [row["kind"] for row in await q("SELECT kind FROM ledger WHERE kind <> 'grant' ORDER BY id")]
    assert kinds == ["reserve", "release"]
    res = (await q("SELECT status, billed_chars FROM reservations"))[0]
    assert res == {"status": "released", "billed_chars": 0}
    usage = await q("SELECT outcome, returned_chars, chars FROM usage_log")
    assert usage == [{"outcome": outcome, "returned_chars": 0, "chars": 10}]
    if status == 503 and code == "provider_busy":
        assert r.headers["retry-after"] == "10"


async def test_bad_output_usage_is_logged_with_cost(make_client, q, make_account, make_site, grant_month):
    client, _ = await make_client(provider=MockProvider(mode="bad_output"))
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 502
    row = (await q("SELECT outcome, prompt_tokens, completion_tokens, cost_micro_usd FROM usage_log"))[0]
    assert row["outcome"] == "bad_output" and row["prompt_tokens"] > 0 and row["cost_micro_usd"] > 0


async def test_cancelled_request_releases_the_reservation(make_client, q, make_account, make_site, grant_month):
    """A bug/cancellation inside the provider call must not leave quota reserved."""
    client, _ = await make_client(provider=StubProvider(error=RuntimeError("boom")))
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 500
    assert r.json() == {"error": {"code": "internal_error", "message": "Internal error."}}
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (0, 0)


# --- partial results -------------------------------------------------------


async def test_partial_result_bills_only_returned_chars(make_client, q, make_account, make_site, grant_month):
    stub = StubProvider(omit={"2"})  # "World!" (id 2) is never returned, also not on the follow-up
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body({"a": "Hello", "b": "World!"}), headers=auth(token))

    assert r.status_code == 200
    data = r.json()
    assert data["translations"] == {"a": "[de] Hello"}
    assert data["missing"] == ["b"]
    assert data["billed_chars"] == 5
    assert data["quota"]["monthly_remaining"] == 995
    assert stub.calls == 2  # one follow-up call for the missing id
    assert list(stub.requests[1].items) == ["2"]
    b = await _bucket(q, bucket)
    assert (b["used"], b["reserved"]) == (5, 0)
    assert sum(r_["chars"] for r_ in await q("SELECT chars FROM ledger WHERE kind = 'release'")) == 6
    assert sum(r_["chars"] for r_ in await q("SELECT chars FROM ledger WHERE kind = 'commit'")) == 5
    assert [row["outcome"] for row in await q("SELECT outcome FROM usage_log ORDER BY id")] == ["partial", "partial"]


async def test_missing_keys_of_duplicate_texts_are_all_listed(make_client, make_account, make_site, grant_month):
    client, _ = await make_client(provider=StubProvider(omit={"2"}))
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 1000)

    r = await client.post(
        "/v1/translate", json=body({"a": "Hello", "b": "World!", "c": "World!"}), headers=auth(token)
    )

    data = r.json()
    assert data["missing"] == ["b", "c"] and list(data["translations"]) == ["a"]
    assert data["billed_chars"] == 5


async def test_follow_up_call_completes_a_partial_answer(make_client, q, make_account, make_site, grant_month):
    mock = MockProvider(mode="partial")  # first call drops every 2nd id, the follow-up returns it
    client, _ = await make_client(provider=mock)
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body({"a": "one", "b": "two", "c": "three"}), headers=auth(token))

    data = r.json()
    assert data["missing"] == [] and len(data["translations"]) == 3
    assert data["billed_chars"] == 11
    assert mock.calls == 2


async def test_provider_returning_nothing_is_free_and_skips_the_follow_up(
    make_client, q, make_account, make_site, grant_month
):
    stub = StubProvider(omit={"1", "2"})
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body(), headers=auth(token))

    assert r.status_code == 200
    assert r.json()["translations"] == {} and r.json()["billed_chars"] == 0
    assert sorted(r.json()["missing"]) == ["k1", "k2"]
    assert stub.calls == 1
    assert (await _bucket(q, bucket))["used"] == 0
    assert (await q("SELECT status FROM reservations"))[0]["status"] == "released"


async def test_failing_follow_up_keeps_the_partial_result(make_client, q, make_account, make_site, grant_month):
    state = {"n": 0}

    class FlakySecondCall(StubProvider):
        async def translate(self, request):
            state["n"] += 1
            if state["n"] == 2:
                raise ProviderTimeout("follow-up timed out")
            return await super().translate(request)

    stub = FlakySecondCall(omit={"2"})
    client, _ = await make_client(provider=stub)
    account_id = await make_account()
    _, token = await make_site(account_id)
    bucket = await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=body({"a": "Hello", "b": "World!"}), headers=auth(token))

    assert r.status_code == 200
    assert r.json()["billed_chars"] == 5 and r.json()["missing"] == ["b"]
    assert (await _bucket(q, bucket))["used"] == 5
    outcomes = [row["outcome"] for row in await q("SELECT outcome FROM usage_log ORDER BY id")]
    assert outcomes == ["partial", "timeout"]
