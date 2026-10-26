# LingoCanvas Cloud API (v1)

For the plugin developer. JSON over HTTPS, UTF-8. Stage 3 MVP: the backend is not deployed yet, the base URL is not final.

## Authentication

Every endpoint except `/health` needs the site token issued by the administrator (one token per connected site):

```
Authorization: Bearer lcc_xxxxxxxxxxxxxxxxxxxxxxxx
```

A missing, malformed, unknown or revoked token always gives the same `401 invalid_token` response. A suspended account gives `403 account_suspended`. The token is shown once when it is created and only a hash is stored on the server.

## Error format

Every error, whatever its status, has this shape. Messages are fixed strings and never repeat your input:

```json
{ "error": { "code": "insufficient_quota", "message": "Not enough characters left.", "needed": 120, "available": 80 } }
```

`code` is stable and meant for programs; `message` is for humans and may change. Some errors carry extra fields (listed below).

## POST /v1/translate

Translates a set of texts from one language to another.

Request:

```json
{
  "request_id": "9f2c1b7a-0001",
  "source_locale": "en",
  "target_locale": "de_DE",
  "target_label": "Deutsch",
  "items": {
    "3a7bd3e2360a3d29eea436fcfb7e44c735d117c42d1c1835420b6b9942dd4f1b": "Add to cart",
    "b1b3773a05c0ed0176787a4f1574ff0075f7521e32e3d3a8ecf2a2d6e5d2f0a1": "<b>Free</b> shipping over %s"
  },
  "glossary": { "Cart": "Warenkorb" }
}
```

| Field | Required | Rules |
|---|---|---|
| `request_id` | yes | 8-64 characters `A-Za-z0-9_-`. Unique per site. **Generate it once per logical request and reuse it on retries.** |
| `source_locale`, `target_locale` | yes | up to 16 characters, form `en`, `de_DE`, `pt-BR`, `zh_Hans_CN` |
| `target_label` | no | human name of the target language (max 64 characters), helps the model; defaults to `target_locale` |
| `items` | yes | object `key -> source text`, not empty. Key: 1-128 printable characters (use your own hash). Text: not blank. Unknown top-level fields are ignored. |
| `glossary` | no | object `term -> required translation`, each side non-blank and up to 200 characters. Total size (keys + values) is capped, see "Glossary limits" below. The glossary is sent to the model as data with every call, so send only the terms that occur in `items` |

Response `200`:

```json
{
  "request_id": "9f2c1b7a-0001",
  "translations": {
    "3a7bd3e2360a3d29eea436fcfb7e44c735d117c42d1c1835420b6b9942dd4f1b": "In den Warenkorb"
  },
  "missing": ["b1b3773a05c0ed0176787a4f1574ff0075f7521e32e3d3a8ecf2a2d6e5d2f0a1"],
  "billed_chars": 11,
  "replay": false,
  "quota": { "monthly_remaining": 99989, "packs_remaining": 250000, "packs_usable": true, "total_remaining": 349989 }
}
```

- `translations`: your keys with their translations. HTML tags, shortcodes and placeholders are kept by the model.
- `missing`: keys the provider did not return. A `200` can be partial. **Missing items are not charged**; send them again later in a new request with a new `request_id`.
- `billed_chars`: characters charged by this call (`0` for a replay).
- `replay`: `true` if this was a free repeat of an earlier successful request.
- `quota`: what is left after the call. `total_remaining` counts packs only when `packs_usable` is true.

### Billing unit

The unit is the number of **Unicode code points** of the source text exactly as sent (an emoji counts as 1, whitespace counts). **Identical texts inside one request are sent to the model and charged once**; all their keys get the translation. Only the texts that were actually returned translated are charged, never the ones in `missing`.

Quota is spent from the monthly allowance first, then from packs. Packs are usable only while a paid period is active (`packs_usable`).

**Billing happens only for committed attempts.** A request is charged once, when an attempt completes and its result is delivered to you. Failed attempts, attempts whose result was withheld (504, see below), replays and the service's own follow-up call for missing items are never charged. Reserved characters of a failed or abandoned attempt go back to your quota immediately.

### Glossary limits

The glossary is part of every model call, so it is bounded twice (both give `413 payload_too_large`):

- total characters of all keys and values at most `glossary_max_chars` (default 2000);
- total glossary characters at most `2 x (sum of the characters of all item texts) + 200`.

More than `max_glossary_entries` entries, or an empty/over-long term, is a `422`.

### Idempotency and retries

- The pair (site, `request_id`) identifies a request. After a **successful** request, sending the same `request_id` with the **same body** is a free replay (`replay: true`, `billed_chars: 0`, the quota is not touched). Replays are capped (`max_replays`, default 2), then `409 replay_limit`. A replay returns the translation again but it is **re-translated by the model** (nothing is stored), so the wording may differ slightly from the first answer. Because it is a real model call, a replay **counts against the same limits as a new request**: the per-site rate limit (`429 rate_limited`) and the per-account in-flight limit (`429 too_many_in_flight`, the replay occupies a slot while it runs).
- After a **failed** request (any 5xx/provider error) nothing is charged and you may **retry with the same `request_id`**. It is charged at most once, whichever attempt succeeds. The number of attempts is capped (`max_attempts`, default 3), then `409 retry_limit`.
- The same `request_id` with a **different** body gives `409 request_id_conflict`.
- Two simultaneous calls with the same `request_id`: one is processed, the other gets `409 request_in_progress`.
- A timeout on your side means "maybe done": retry with the **same** `request_id`; never generate a new id for the same work, or you could pay twice.

## Errors and what to do

| HTTP | `code` | Meaning | What to do |
|---|---|---|---|
| 401 | `invalid_token` | missing / wrong / revoked token | stop, ask the site owner to check the token. Do not retry |
| 402 | `insufficient_quota` | not enough characters (`needed`, `available` in the body); the provider was not called | stop, show "buy more characters". Do not retry until the quota changes. Nothing was charged |
| 403 | `account_suspended` | account suspended | stop. Do not retry |
| 404 / 405 | `not_found` / `method_not_allowed` | wrong path or method | fix the client |
| 409 | `request_in_progress` | the same `request_id` is still being processed | wait 2-5 seconds and retry with the same `request_id`; it will probably become a replay |
| 409 | `request_id_conflict` | the id was used with a different body | do not retry; use a new `request_id` for a new body |
| 409 | `replay_limit` | the request was already delivered the maximum number of times | do not retry; use the data you already received |
| 409 | `retry_limit` | too many failed attempts for this id | do not retry this id; start over later with a new `request_id` |
| 413 | `payload_too_large` | body, item count, text length, total characters or glossary size over the limits below | do not retry; split into smaller requests or shorten the glossary |
| 422 | `validation_error` | invalid input; `details` is a list of `{field, message, type}`. Neither your input nor your keys are echoed: a dict key in `field` is shown as `<key>` (for example `body.items.<key>`) | do not retry; fix the request |
| 429 | `rate_limited` | too many requests from this site | wait `Retry-After` seconds, retry with the same `request_id` |
| 429 | `too_many_in_flight` | too many requests of this account are being processed | wait `Retry-After` seconds, retry with the same `request_id` |
| 500 | `internal_error` | server bug | retry later (same `request_id`), with backoff |
| 502 | `provider_error` / `provider_bad_output` | the AI provider failed or returned an unusable answer | retry (same `request_id`) after a short pause; at most `max_attempts` |
| 503 | `provider_busy` | the provider rate-limits us | wait `Retry-After` (10 s), retry with the same `request_id` |
| 503 | `provider_unavailable` | the provider is down | retry with backoff (same `request_id`) |
| 503 | `service_budget_exhausted` | the service-wide daily budget is used up | wait `Retry-After` (3600 s) or longer; do not hammer |
| 504 | `provider_timeout` | the provider did not answer within the overall deadline (`call_deadline_seconds`), **or** the attempt took so long that its reservation expired and was released before the result could be settled. In both cases the result is **not delivered and nothing is charged** | retry with the **same** `request_id` after a pause (it counts as one of `max_attempts`) |

Rule of thumb: **never retry 402, 401, 403, 413, 422 or `request_id_conflict`**. For 429/5xx/`request_in_progress` retry with the **same** `request_id`, honour `Retry-After` when present, use exponential backoff with a cap, and give up after 3 tries.

## GET /v1/account

Current state of the account of the token's site.

```json
{
  "account": { "id": 1, "name": "Acme", "plan": "pro", "status": "active" },
  "sites": { "used": 2, "max": 3 },
  "quota": { "monthly_remaining": 480000, "packs_remaining": 250000, "packs_usable": true, "total_remaining": 730000 },
  "billed_chars_this_month": 20000
}
```

`plan` and `sites.max` can be `null` for an account without a subscription. `billed_chars_this_month` counts the characters charged in the current UTC calendar month.

## GET /health

No authentication. `200 {"status":"ok","db":"ok"}`; `503 {"status":"error","db":"down"}` when the database is unreachable.

## Limits (from `config.ini`, section `[limits]`)

| Key | Default | Applies to |
|---|---|---|
| `max_items` | 300 | items in one request (413) |
| `max_chars_per_item` | 10000 | characters of one text (413) |
| `max_chars_per_request` | 20000 | sum of characters of all items (413) |
| `max_body_bytes` | 262144 | raw request body, checked on the `Content-Length` and on the streamed size (413) |
| `max_glossary_entries` | 50 | glossary entries (422) |
| `glossary_max_chars` | 2000 | total characters of glossary keys + values (413); also at most 2 x item characters + 200 |
| `per_site_requests_per_minute` | 30 | admissions per site in the last minute: new requests, retries and replays (429 `rate_limited`, `Retry-After: 60`) |
| `max_in_flight_per_account` | 2 | simultaneous requests per account, replays included (429 `too_many_in_flight`, `Retry-After: 5`) |
| `reservation_ttl_seconds` | 300 | a stuck request releases its reserved characters after this time |
| `call_deadline_seconds` | 150 | overall time budget of one request for all model calls; on expiry `504 provider_timeout`. Must be at least 30 s below `reservation_ttl_seconds` |
| `max_replays` | 2 | free replays of a successful request |
| `max_attempts` | 3 | attempts for one `request_id` after failures |

The values can change between releases; the plugin should treat the limits as hints and rely on the error codes.
