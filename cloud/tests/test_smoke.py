"""Minimal smoke tests; the full suite builds on tests/conftest.py."""

from __future__ import annotations

from tests.conftest import auth


def _body(**over):
    body = {
        "request_id": "req-12345678",
        "source_locale": "en",
        "target_locale": "de",
        "items": {"k1": "Hello", "k2": "World"},
    }
    body.update(over)
    return body


async def test_health(client):
    r = await client.get("/health")
    assert r.status_code == 200
    assert r.json() == {"status": "ok", "db": "ok"}


async def test_translate_happy_path(client, mock_provider, make_account, make_site, grant_month):
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 1000)

    r = await client.post("/v1/translate", json=_body(), headers=auth(token))

    assert r.status_code == 200, r.text
    data = r.json()
    assert data["translations"] == {"k1": "[de] Hello", "k2": "[de] World"}
    assert data["missing"] == []
    assert data["billed_chars"] == 10
    assert data["replay"] is False
    assert data["quota"]["monthly_remaining"] == 990
    assert mock_provider.calls == 1


async def test_insufficient_quota_never_calls_provider(client, mock_provider, make_account, make_site, grant_month):
    account_id = await make_account()
    _, token = await make_site(account_id)
    await grant_month(account_id, 5)

    r = await client.post("/v1/translate", json=_body(), headers=auth(token))

    assert r.status_code == 402
    err = r.json()["error"]
    assert err["code"] == "insufficient_quota"
    assert err["needed"] == 10 and err["available"] == 5
    assert mock_provider.calls == 0


async def test_missing_token_is_401(client):
    r = await client.post("/v1/translate", json=_body())
    assert r.status_code == 401
    assert r.json()["error"]["code"] == "invalid_token"
