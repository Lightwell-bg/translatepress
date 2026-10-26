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
  "billed_chars_this_month": 20000,
  "subscription": { "status": "active", "current_period_end": "2026-11-25T15:31:52Z", "cancel_at_period_end": false }
}
```

`plan` and `sites.max` can be `null` for an account without a subscription. `billed_chars_this_month` counts the characters charged in the current UTC calendar month. `subscription` is `null` when the account never had a Stripe subscription; otherwise it mirrors the latest one (`status` is Stripe's: `active`, `trialing`, `past_due`, `canceled`, ...). Show "renews on / ends on `current_period_end`" from it, and "cancelled, active until ..." when `cancel_at_period_end` is true.

## Billing (Stripe)

All billing endpoints answer `503 billing_disabled` while the service has no Stripe configuration. Errors keep the usual `{"error": {"code", "message"}}` shape and never echo your input.

### Sign-up flow for the plugin

1. The plugin generates a random **claim secret** (at least 32 random characters) and keeps it locally.
2. `POST /v1/billing/signup` with its SHA-256. Open `checkout_url` in the customer's browser.
3. After payment Stripe sends the customer to the `success_url` (`.../thanks/?claim=<claim_id>`; the URL contains the claim id only, never a secret or a token).
4. The plugin polls `POST /v1/billing/claim` every 3-5 seconds (for at most `expires_at`) until it gets `200` with the site token. The token is returned **once**: store it immediately.

### POST /v1/billing/signup (no authentication)

```json
{ "plan": "starter", "site_name": "example.com", "claim_secret_sha256": "<64 hex chars>", "email": "optional@example.com" }
```

`200`: `{ "claim_id": "lcc_claim_...", "checkout_url": "https://checkout.stripe.com/...", "expires_at": "2026-10-27T10:00:00Z" }`. `site_name` 1-255 characters, `email` optional (valid, up to 320). Limited per client address (`signups_per_ip_per_hour`): `429 rate_limited` with `Retry-After: 3600`. `502 billing_provider_error` if Stripe is unreachable (retry later; the claim is closed, start a new sign-up).

Before the Checkout Session the backend creates the Stripe Customer itself (idempotency key `lc-customer:<claim_id>`) with metadata `lc_app=lingocanvas` and `lc_claim_id`, stores its id in `checkout_claims.stripe_customer_id` and passes `customer` (not `customer_email`) to Checkout. All LingoCanvas customers are created by the backend with metadata `lc_app=lingocanvas`; this is how the other product on the shared Stripe account (Vira) recognises LingoCanvas events. Never create LingoCanvas customers manually in the Dashboard without this metadata.

### POST /v1/billing/claim (no authentication)

```json
{ "claim_id": "lcc_claim_...", "claim_secret": "<the secret itself>" }
```

| HTTP | `code` / body | Meaning | What to do |
|---|---|---|---|
| 200 | `{ "token", "site_id", "account_id" }` | paid; the site token is shown once | store the token |
| 202 | `{ "status": "pending" }` | not paid yet (or the webhook has not arrived). The claim becomes ready **only after the first paid invoice** has granted quota: creating the subscription or completing the checkout is not enough, and a 0 EUR (trial / 100 % coupon) or non-EUR invoice grants nothing and leaves the claim pending | poll again in 3-5 s |
| 403 | `account_suspended` | the account is switched off by the administrator or has an open or lost card dispute (dispute hold) | contact support; poll nothing |
| 404 | `claim_not_found` | unknown id **or** wrong secret (identical body). After `claim_max_failed_attempts` wrong secrets the claim is locked and answers 404 even to the right secret | stop; start a new sign-up |
| 409 | `already_claimed` | the token was already collected | do not retry; use the stored token |
| 409 | `site_limit_reached` | the plan's site limit is full | revoke an unused site, then poll again |
| 410 | `claim_expired` | not paid within `claim_ttl_hours`, or the checkout expired | start a new sign-up |

### POST /v1/billing/checkout (site token)

`{ "product": "pack_250k" }` or `{ "product": "starter" }`. `200 { "checkout_url": "..." }`. Errors: `409 subscription_required` (a pack needs a live subscription: `active` or `trialing` and not past its period end), `409 already_subscribed` (`starter` while a live subscription exists), `409 no_billing_account` (the account has no Stripe customer yet), `502 billing_provider_error`. The pack is credited by the webhook (a few seconds after payment); poll `GET /v1/account`. A `starter` request is serialised per account: while an earlier Starter session is still open (stored for 23 hours; Stripe sessions live 24 h) the same `checkout_url` is returned and no new session is created, so double clicks and concurrent requests cannot start two subscriptions. The stored session is dropped once a live subscription exists.

### POST /v1/billing/portal (site token)

`200 { "url": "..." }`: send the customer there to change card, cancel or see invoices. `409 no_billing_account`. `503 portal_not_configured` when `STRIPE_PORTAL_CONFIGURATION` is empty (the gateway is not called; there is no fallback to the Stripe account's default portal, which the other product uses).

### POST /v1/sites/pair-code (site token) and POST /v1/sites/pair (no authentication)

Connects another site to the same account. On the already connected site: `POST /v1/sites/pair-code` -> `200 { "code", "expires_at" }` (valid `pair_code_ttl_minutes`, one use; `429 too_many_pair_codes` when `max_active_pair_codes` unused codes exist). On the new site: `POST /v1/sites/pair` with `{ "code": "...", "site_name": "new.example.com" }` -> `200 { "token", "site_id" }` (token shown once). Errors: `404 pair_code_invalid` (unknown, used or expired: identical body), `409 subscription_required` (no plan or no live subscription), `409 site_limit_reached`, `403 account_suspended` (administrator switch or dispute hold; the code stays unused).

### Dispute hold

An open or lost card dispute sets a hold on the account (separate from the administrator's `status`). While it is set, every site-token endpoint, claim pickup and `POST /v1/sites/pair` answer `403 account_suspended`. The hold is lifted when all disputes of the account end in `won` / `warning_closed`; a lost dispute keeps it. Dispute states are final once recorded: a late `created` / `updated` event never reopens a won dispute.

### POST /v1/stripe/webhook (called by Stripe only)

Authenticated by the `Stripe-Signature` header (secret `STRIPE_WEBHOOK_SECRET`, tolerance 5 minutes). Body limit `webhook_max_body_bytes` (413 above it). Responses:

| HTTP | Body | Meaning |
|---|---|---|
| 200 | `{"status": "processed"}` | the event changed our data. Refunds and disputes (`charge.refunded`, `charge.dispute.*`) carry no metadata, so one whose charge we do not know yet is recorded as `processed` and **parked** (ids and amounts only) until its payment appears; parked rows that never match (the other product's charges) are deleted after `unmatched_retention_days` (90) days |
| 200 | `{"status": "ignored"}` | not ours (another product on the shared Stripe account), not a handled type, or its `livemode` does not match `STRIPE_LIVEMODE`. No Stripe call, no data change |
| 200 | `{"status": "duplicate"}` | this event id was already handled |
| 400 | `invalid_signature` | bad or missing signature, old timestamp, malformed body. Do not retry |
| 413 | `payload_too_large` | body over the limit |
| 500 | `internal_error` | a processing error (unknown price on our subscription, database trouble, Stripe lookup failed): **nothing was saved**, Stripe retries by itself with backoff for up to three days. Watch the log line `stripe webhook failed type=... event=... error=<ExceptionClass>` |
| 503 | `billing_disabled` | Stripe is not configured; Stripe retries |

Retry guidance: answer 2xx only after the data is committed (that is how it works); Stripe's own retries are the only retry mechanism needed. Resending an event from the Stripe dashboard is always safe: a handled event answers `duplicate`.

## GET /health

No authentication. `200 {"status":"ok","db":"ok"}`; `503 {"status":"error","db":"down"}` when the database is unreachable.

## Billing constants (`config.ini`, section `[billing]`)

`success_url`, `cancel_url`, `portal_return_url`, `claim_ttl_hours` (24), `claim_max_failed_attempts` (10), `pair_code_ttl_minutes` (10), `max_active_pair_codes` (5), `pack_valid_months` (12), `signups_per_ip_per_hour` (10), `consent_terms` (false), `automatic_tax` (false), `webhook_max_body_bytes` (1048576), `unmatched_retention_days` (90).

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
