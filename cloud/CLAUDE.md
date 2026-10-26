## Project rules

- Stack: Python 3.12, FastAPI, async SQLAlchemy 2 + asyncpg, Alembic, PostgreSQL 16, httpx, pytest (+ pytest-asyncio). The owner works on Windows + PowerShell; give instructions PowerShell first, Git Bash second and clearly labelled. venv: `.venv\Scripts\Activate.ps1` (PowerShell) / `source .venv/Scripts/activate` (Git Bash).
- This project lives in `translatepress/cloud` of the LingoCanvas WordPress plugin repository and is NEVER part of the plugin ZIP. Never create folders outside this directory, and never create new top-level folders elsewhere without asking the owner. The old copy `D:\1PythonProjects\1_WORDPRESS\lingocanvas-cloud` must not be touched or used.
- Future deployment: the existing CenterAI VPS, as a separate Docker Compose project with its own PostgreSQL. Never use Vira's database or Vira's Stripe account. Docker is not installed on the dev machine: do not run it here.
- Secrets live only in `.env`; `.env` and `.env.example` stay structurally identical (same variables, same order, same comments; only values differ). A new variable goes into both in the same step. Never print `.env` values and never overwrite real values. Non-secret constants live in `config.ini`.
- Quota (buckets, reservations, ledger) changes ONLY through `app/quota.py`, under the account row lock (lock order: account first, then buckets). No direct UPDATE of quota tables from application code; the database CHECK constraints are the last line of defence.
- Never log or store client texts, translations, site tokens or API keys. Logs carry only ids, sizes, counts and codes. `tests/test_privacy.py` guards this: keep it green.
- Errors are always `{"error": {"code", "message"}}` (plus documented extra fields such as `needed`/`available`), with fixed messages that never echo client input or provider/internal error text.
- Paid DeepSeek calls only with the owner's explicit permission; development and tests use `PROVIDER=mock` or `httpx.MockTransport`. No network calls in tests.
- Every behaviour change updates `README.md` + `README_RU.md` (exact equivalents), `docs/QUICK_START.md` (Russian) and `docs/API.md`; the test suite (`pytest -q`) must be green before a task is called done.
- No git commits unless the owner asks.
