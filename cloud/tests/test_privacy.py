"""Privacy: client texts, translations, site tokens and the provider key never reach logs or error bodies."""

from __future__ import annotations

import json
import logging

import httpx

from app.providers.base import ProviderBadOutput, ProviderError, ProviderTimeout
from app.providers.deepseek import DeepSeekProvider
from tests.conftest import auth
from tests.helpers import StubProvider, body, new_rid

SOURCE = "ZXQ-SOURCE-Секрет-Hello"
TRANSLATION = "QWV-UEBERSETZUNG-Geheim"
ECHO = "UPSTREAM-ECHO-ZXQ-SOURCE-Секрет-Hello"
API_KEY = "sk-distinct-privacy-key-7f3a9c"
SENSITIVE = [SOURCE, TRANSLATION, ECHO, API_KEY, "ZXQ-SOURCE", "QWV-UEBERSETZUNG"]


def deepseek(handler) -> DeepSeekProvider:
    return DeepSeekProvider(
        api_key=API_KEY, base_url="https://api.deepseek.com", model="deepseek-flash",
        timeout_seconds=5, transport=httpx.MockTransport(handler), retry_delay=0,
    )


def ok(request: httpx.Request) -> httpx.Response:
    user = json.loads(request.content)["messages"][1]["content"]
    items = json.loads(user.split("\n\nITEMS:\n", 1)[-1])  # the glossary block, if any, comes first
    content = json.dumps({k: f"{TRANSLATION} {k}" for k in items}, ensure_ascii=False)
    usage = {"prompt_tokens": 20, "completion_tokens": 10, "prompt_cache_miss_tokens": 20}
    return httpx.Response(200, json={"choices": [{"message": {"content": content}, "finish_reason": "stop"}], "usage": usage})


def assert_clean(caplog, *responses, extra=()):
    forbidden = [*SENSITIVE, *extra]
    log_text = caplog.text + "\n".join(r.getMessage() for r in caplog.records)
    for needle in forbidden:
        assert needle not in log_text, f"{needle!r} leaked into the logs"
    for r in responses:
        # JSON escapes non-ASCII, so check both the raw and the decoded form
        decoded = json.dumps(r.json(), ensure_ascii=False) if r.headers.get("content-type", "").startswith("application/json") else ""
        for needle in forbidden:
            assert needle not in r.text and needle not in decoded, f"{needle!r} leaked into a response"
        if r.status_code >= 400:
            assert set(r.json()) == {"error"}
            assert {"code", "message"} <= set(r.json()["error"])


async def test_full_cycles_with_every_kind_of_failure_leak_nothing(
    make_client, caplog, make_account, make_site, grant_month
):
    caplog.set_level(logging.DEBUG)
    mode = {"v": "ok"}
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(mode["v"])
        m = mode["v"]
        if m == "ok":
            return ok(request)
        if m == "400":
            return httpx.Response(400, json={"error": {"message": ECHO}})
        if m == "500":
            return httpx.Response(500, text=ECHO)
        if m == "429":
            return httpx.Response(429, json={"error": {"message": ECHO}})
        if m == "garbage":
            return httpx.Response(200, json={"choices": [{"message": {"content": f"not json {ECHO}"}}]})
        if m == "timeout":
            raise httpx.ReadTimeout(ECHO)
        raise AssertionError(m)

    client, _ = await make_client(provider=deepseek(handler), deepseek_api_key=API_KEY)
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 100_000)
    poor_account = await make_account(name="Poor")
    _, poor_token = await make_site(poor_account)
    await grant_month(poor_account, 1)

    responses = []
    for m in ("ok", "400", "500", "429", "garbage", "timeout"):
        mode["v"] = m
        responses.append(await client.post("/v1/translate", json=body({"k": SOURCE}), headers=auth(token)))
    assert [r.status_code for r in responses] == [200, 502, 503, 503, 502, 504]
    assert TRANSLATION in responses[0].text  # the client of course gets its own translation

    mode["v"] = "ok"
    same = body({"k": SOURCE})
    replay_1 = await client.post("/v1/translate", json=same, headers=auth(token))
    replay_2 = await client.post("/v1/translate", json=same, headers=auth(token))
    conflict = await client.post("/v1/translate", json=body({"k": SOURCE + "!"}, request_id=same["request_id"]), headers=auth(token))
    assert (replay_1.status_code, replay_2.status_code, conflict.status_code) == (200, 200, 409)

    errors = [
        *responses[1:],
        conflict,
        await client.post("/v1/translate", json=body({"k": SOURCE}), headers=auth(poor_token)),  # 402
        await client.post("/v1/translate", json=body({"k": SOURCE}), headers=auth("lcc_ZXQ-SOURCE-garbage")),  # 401
        await client.post("/v1/translate", json=body({"k": SOURCE}), headers={"Authorization": f"Bearer {API_KEY}"}),
        await client.get("/v1/account", headers=auth(token)),
        await client.get("/health"),
    ]
    assert [e.status_code for e in errors[-6:-2]] == [409, 402, 401, 401]
    assert_clean(caplog, *errors, extra=[token, poor_token])
    assert any(m != "ok" for m in calls)


async def test_validation_errors_do_not_echo_client_text(make_client, caplog, make_account, make_site, grant_month):
    caplog.set_level(logging.DEBUG)
    client, _ = await make_client(limits={"max_items": 2, "max_chars_per_item": 8})
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 100_000)

    bad_bodies = [
        body({"k": SOURCE}, target_locale=SOURCE),
        body({"k": SOURCE}, source_locale="en", request_id=SOURCE),
        body({SOURCE * 20: SOURCE}),  # key too long
        body({"k": "   "}, target_label=SOURCE * 10),
        body({"k": SOURCE}, glossary={SOURCE: " "}),
        body({"k": SOURCE}),  # 413: text longer than max_chars_per_item
        {"request_id": new_rid(), "source_locale": "en", "target_locale": "de", "items": {"k": SOURCE, "k2": 5}},
        {"items": SOURCE},
    ]
    responses = [await client.post("/v1/translate", json=b, headers=auth(token)) for b in bad_bodies]
    responses.append(
        await client.post("/v1/translate", content=("{" + SOURCE).encode("utf-8"), headers={**auth(token), "Content-Type": "application/json"})
    )

    assert [r.status_code for r in responses[:-1]] == [422, 422, 422, 422, 422, 413, 422, 422]
    assert responses[-1].status_code == 422
    assert_clean(caplog, *responses)
    for r in responses:
        assert r.json()["error"]["code"] in {"validation_error", "payload_too_large"}


async def test_unexpected_exception_and_provider_messages_stay_internal(
    make_client, caplog, make_account, make_site, grant_month
):
    caplog.set_level(logging.DEBUG)
    errors = [
        ProviderError("provider said: " + ECHO),
        ProviderTimeout(ECHO),
        ProviderBadOutput(ECHO),
        RuntimeError("internal detail"),
    ]
    responses = []
    for err in errors:
        client, _ = await make_client(provider=StubProvider(error=err), deepseek_api_key=API_KEY)
        account_id = await make_account()
        _, token = await make_site(account_id)
        await grant_month(account_id, 1000)
        responses.append(await client.post("/v1/translate", json=body({"k": SOURCE}), headers=auth(token)))

    assert [r.status_code for r in responses] == [502, 504, 502, 500]
    for r in responses:
        assert "internal detail" not in r.text and "provider said" not in r.text
    assert_clean(caplog, *responses)
    for r in responses:
        assert set(r.json()["error"]) == {"code", "message"}


async def test_database_holds_no_client_text(make_client, sessionmaker, q, make_account, make_site, grant_month):
    """Only sizes, counts and hashes are stored: no source text, no translation, no token."""
    client, _ = await make_client(provider=deepseek(ok))
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 100_000)
    r = await client.post("/v1/translate", json=body({"k": SOURCE}, glossary={"Cart": "Warenkorb-GLOSSARY"}), headers=auth(token))
    assert r.status_code == 200

    tables = ["accounts", "sites", "quota_buckets", "reservations", "reservation_items", "ledger", "usage_log"]
    dump = ""
    for t in tables:
        dump += repr(await q(f"SELECT * FROM {t}"))  # noqa: S608 - fixed table names
    for needle in (SOURCE, "ZXQ-SOURCE", TRANSLATION, "GLOSSARY", token, API_KEY):
        assert needle not in dump
