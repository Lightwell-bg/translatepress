# LingoCanvas Cloud (backend)

Backend of the paid "LingoCanvas Cloud" translation provider for the LingoCanvas WordPress plugin. A site sends source texts, the backend translates them with an AI model (DeepSeek) and charges characters from a prepaid quota of the customer's account.

It lives in the `cloud/` folder of the plugin repository for convenience only and is **never part of the plugin ZIP**.

Status: **stage 3 MVP, not deployed.** Stripe billing and the plugin-side integration are the next stages. Russian version: [README_RU.md](README_RU.md). Quick start (Russian): [docs/QUICK_START.md](docs/QUICK_START.md).

## Stack

- Python 3.12, FastAPI, uvicorn
- SQLAlchemy 2 (async) + asyncpg, Alembic migrations
- PostgreSQL 16 (embedded `pgserver` for local development and tests)
- httpx for the DeepSeek API, pytest + pytest-asyncio for tests

## Structure

```
app/
  main.py               application factory, body size limit, sweeper start-up
  config.py             .env settings + config.ini constants
  models.py             database schema (CHECK constraints guard the quota)
  quota.py              ALL quota changes: reserve / settle / grant / revoke (account row lock)
  translate_service.py  reserve -> provider call -> settle
  security.py           site tokens (HMAC hash only) and authentication
  pricing.py            provider cost in micro-USD (peak / off-peak)
  sweeper.py            releases stuck reservations
  errors.py             the single error format {"error": {"code", "message"}}
  cli.py                admin commands (accounts, sites, grants, usage)
  api/                  routes (translate/account, billing, webhook) and request/response schemas
  billing/              events.py (webhook processor: ownership test, idempotency, grants/refunds/disputes),
                        gateway.py (normalised Stripe objects + contract), stripe_gateway.py (Stripe SDK)
  providers/            deepseek.py, mock.py (no paid calls), prompt.py
migrations/             Alembic migrations
scripts/local_pg.py     start/stop a local PostgreSQL
tests/                  pytest suite (embedded PostgreSQL, no network)
docs/                   API.md, QUICK_START.md, STRIPE_SETUP.md, SESSION_HANDOFF.md
config.ini              non-secret constants (limits, plans, packs, prices)
.env / .env.example     secrets and critical settings
Dockerfile, docker-compose.yml   for a FUTURE VPS deployment (not used yet)
```

## How quotas work

- An account owns **quota buckets**: a *monthly* bucket per paid period (not carried over) and optional *packs* of extra characters.
- Characters are spent **monthly first, then packs** (packs by earliest expiry). **Packs are spendable only while a paid period is live**: if the monthly bucket has expired, unused packs wait for the renewal.
- Billing unit: Unicode code points of the **unique** source texts of a request (identical texts are sent and charged once).
- A request first **reserves** its characters, then calls the provider, then **settles**: only characters of texts that actually came back translated are charged, the rest returns to the bucket. A request without enough quota gets 402 and never reaches the provider.
- A **replay** (the same `request_id` and body again) is free (`billed_chars: 0`, `replay: true`) and capped by `max_replays`. The text is re-translated by the model (nothing is stored), so a replay counts against the same per-site rate limit and per-account in-flight limit as a new request. A failed request can be retried with the same `request_id` and is charged once at most (`max_attempts`). Billing happens only for committed attempts.
- Every reservation has a TTL (`reservation_ttl_seconds`). A background **sweeper** releases reservations left pending by a crash, so the customer is never charged for them. Each attempt carries a number (fencing token) and settles only its own reservation; one overall deadline (`call_deadline_seconds`, at least 30 s below the TTL) bounds all model calls of a request. If an attempt's reservation was released before it finished, the result is withheld with `504 provider_timeout` (retry with the same `request_id`) instead of being delivered unpaid.
- The glossary is sent to the model as data in the user message (never in the system prompt) and is bounded: `glossary_max_chars` and at most 2 x item characters + 200 (otherwise 413).
- The daily provider budget is checked before every call against the spent amount plus twice the worst-case cost of the request; failures with unknown usage that may have been billed (timeout, 5xx, cancellation) are booked at the worst case.
- Every quota change takes a **row lock on the account** first (`app/quota.py`), so parallel requests cannot spend the same characters; database CHECK constraints are the last line of defence. Every movement is written to an append-only `ledger`.
- Client texts and translations are never stored or logged; the database holds only sizes, counts, token counts and cost.

## Billing (Stripe)

Stage 4, **local and test mode only** (nothing is deployed, no live keys exist). Click-by-click setup of the Stripe test account: [docs/STRIPE_SETUP.md](docs/STRIPE_SETUP.md) (Russian).

- **Shared Stripe account.** The Stripe account is shared with another product. Every object we create carries metadata `lc_app=lingocanvas` (checkout session, subscription, payment intent); we never write `user_id` or `product` keys. A webhook event is processed only if its object has that metadata or already exists in our tables (`subscriptions`, `payments`); everything else is acknowledged (`200 ignored`) without any Stripe API call and without touching our data.
- **Sign-up without a token in a URL.** The plugin generates a random *claim secret* and sends only its SHA-256 to `POST /v1/billing/signup`; the customer pays in Stripe Checkout; the plugin then polls `POST /v1/billing/claim` with the secret and receives the site token once. A claim locks after `claim_max_failed_attempts` wrong secrets.
- **Webhook** `POST /v1/stripe/webhook`: signature verified with `STRIPE_WEBHOOK_SECRET` (5 minutes tolerance), test/live events separated by `STRIPE_LIVEMODE`. The `stripe_events` row is written in the same transaction as the effects, so a duplicate is a no-op and a failed attempt makes Stripe retry (HTTP 500). Subscription state is always copied from the live Stripe object, never from the event payload, so out-of-order events cannot restore an old state.
- **Money to quota.** `invoice.paid` (reasons `subscription_create`/`subscription_cycle`) grants one monthly bucket valid for the invoice period (the invoice line is chosen by its known plan price, never a proration line); a pack (one-time payment, 12 months) is granted when the checkout session is paid (`checkout.session.completed` with `payment_status=paid`, or `async_payment_succeeded`). Only real money grants quota: a 0 EUR invoice or a non-EUR payment grants nothing. Grants are keyed by invoice / payment intent id. A sign-up claim becomes collectable only after its first paid invoice has granted quota (not on subscription creation). Refunds are cumulative and revoke the unused part proportionally; the part sitting in a running request is revoked when that request releases it. A dispute puts a hold on the account (`403 account_suspended` for translating, claim pickup and pairing) and revokes the unused characters; winning it lifts the hold (revoked characters are not restored automatically); `accounts.status` (the administrator's switch) is never changed by disputes. Refunds and disputes that arrive before their payment (and those of the other product's charges) are parked as ids and amounts only, applied when the payment shows up, and deleted after `unmatched_retention_days` (90).
- **One open Starter checkout.** `POST /v1/billing/checkout` with `starter` returns the same URL while an earlier session of the account is still open (23 h), so a double click cannot start two subscriptions.
- **More sites.** `POST /v1/sites/pair-code` (site token) returns a one-time code; the new site sends it to `POST /v1/sites/pair`. The plan's `max_sites` applies to claims and pairing.
- Billing answers `503 billing_disabled` until both `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET` are set. Constants (URLs, TTLs, limits) are in `config.ini`, section `[billing]`. Stripe SDK `stripe==16.0.0`, API version pinned in `app/billing/stripe_gateway.py` (`API_VERSION`).
- Admin check: `python -m app.cli billing-status --account 1`.

## API

Summary: `POST /v1/translate`, `GET /v1/account`, `GET /health`; billing: `POST /v1/billing/signup`, `/claim`, `/checkout`, `/portal`, `POST /v1/sites/pair-code`, `/pair`, `POST /v1/stripe/webhook`. Authentication is `Authorization: Bearer lcc_...` (one token per connected site). Full description with examples and error codes: [docs/API.md](docs/API.md).

## Setup (virtual environment and dependencies)

Git Bash:

```bash
cd /d/1PythonProjects/1_WORDPRESS/translatepress/cloud
python -m venv .venv          # only if .venv does not exist yet
source .venv/Scripts/activate
pip install -r requirements-dev.txt
```

PowerShell:

```powershell
cd D:\1PythonProjects\1_WORDPRESS\translatepress\cloud
python -m venv .venv          # only if .venv does not exist yet
.venv\Scripts\Activate.ps1
pip install -r requirements-dev.txt
```

## Local PostgreSQL

```bash
python scripts/local_pg.py start    # starts (or reuses) a local cluster in .pgdata/, prints DATABASE_URL
python scripts/local_pg.py stop     # stops it
```

Copy `.env.example` to `.env` and paste the printed URL into `DATABASE_URL` (see the variable table below).

## Migrations

```bash
alembic upgrade head      # create / update the schema
alembic check             # exits 0 when the models match the migrations
```

## Run

```bash
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Check: <http://127.0.0.1:8000/health> returns `{"status":"ok","db":"ok"}`. With `PROVIDER=mock` (default) translations are fake (`[de] text`) and nothing is paid.

## Admin CLI

```bash
python -m app.cli create-account --name "Acme" --email a@example.com --plan starter
python -m app.cli add-site --account 1 --name shop.example.com   # prints the site token ONCE
python -m app.cli list-sites --account 1
python -m app.cli revoke-site --site 1
python -m app.cli grant-month --account 1 [--start 2026-10-01]   # monthly quota of the plan, idempotent
python -m app.cli grant-pack --account 1 --pack pack_250k --ref pay_123   # --ref makes it idempotent
python -m app.cli revoke-bucket --bucket 1       # takes back the unused characters (refund)
python -m app.cli usage --account 1 --days 30    # customer billing (charged requests/characters) and provider work (calls, tokens, cost)
python -m app.cli spend --days 7                 # provider cost per day
python -m app.cli sweep                          # release expired reservations now
python -m app.cli billing-status --account 1      # subscription, payments (refunded/revoked), buckets; no secrets
```

`add-site` respects the plan's maximum number of sites. Plans, packs and limits are in `config.ini`.

## Tests

```bash
pytest -q
```

The suite starts a throw-away embedded PostgreSQL, applies the migrations and never makes network calls or paid provider calls.

## External services

| Service | Why | Where to get a key | Where to enter it |
|---|---|---|---|
| DeepSeek | the AI model that translates (`deepseek-flash`, thinking disabled) | <https://platform.deepseek.com> → "API keys" ([docs](https://api-docs.deepseek.com)) | `DEEPSEEK_API_KEY` in `.env`, and `PROVIDER=deepseek` |
| Stripe (TEST mode only for now) | subscriptions and packs | <https://dashboard.stripe.com/test/apikeys> ([docs](https://docs.stripe.com)); step by step: [docs/STRIPE_SETUP.md](docs/STRIPE_SETUP.md) | `STRIPE_*` variables in `.env` (table below) |

Never run a paid DeepSeek call without the owner's explicit permission; keep `PROVIDER=mock` for development.

## Environment variables (`.env`)

| Variable | Required | Meaning |
|---|---|---|
| `DATABASE_URL` | yes | PostgreSQL URL for asyncpg, `postgresql+asyncpg://user:password@host:port/dbname` (local: printed by `scripts/local_pg.py start`) |
| `PROVIDER` | no (`mock`) | `mock` = fake translations, no paid calls; `deepseek` = real provider |
| `DEEPSEEK_API_KEY` | only with `PROVIDER=deepseek` | DeepSeek API key |
| `TOKEN_PEPPER` | yes | secret for hashing site tokens, at least 32 random characters; changing it invalidates every issued token. Generate: `python -c "import secrets;print(secrets.token_urlsafe(48))"` |
| `DAILY_SPEND_LIMIT_USD` | no (`5`) | kill switch: once today's (UTC) provider cost reaches this, the service answers 503 `service_budget_exhausted` |
| `POSTGRES_PASSWORD` | only for docker-compose | password of the PostgreSQL container; compose builds `DATABASE_URL` for the services from it. Generate: `python -c "import secrets;print(secrets.token_hex(24))"` |
| `STRIPE_SECRET_KEY` | for billing | Stripe secret API key `sk_test_...` (test mode). Empty = billing off (503 `billing_disabled`) |
| `STRIPE_WEBHOOK_SECRET` | for billing | signing secret `whsec_...` of the webhook endpoint. Empty = billing off |
| `STRIPE_PRICE_STARTER` | for billing | id `price_...` of the Starter monthly price |
| `STRIPE_PRICE_PACK_250K` | for billing | id `price_...` of the 250k-characters one-time price |
| `STRIPE_PORTAL_CONFIGURATION` | for the portal | id `bpc_...` of the Customer Portal configuration made for LingoCanvas; empty = `POST /v1/billing/portal` answers `503 portal_not_configured` (there is no fallback to the Stripe account default, which the other product uses) |
| `STRIPE_LIVEMODE` | no (`false`) | `false` processes only test-mode events, `true` only live-mode events |

`CONFIG_INI` (optional, not in `.env.example`) overrides the path of `config.ini`. `.env` and `.env.example` must stay structurally identical (same variables in the same order, only values differ).

## Future deployment

`Dockerfile` and `docker-compose.yml` describe the future VPS deployment (a separate Compose project with its own PostgreSQL; the API listens on `127.0.0.1:8077` only, behind a reverse proxy). They have not been run yet.
