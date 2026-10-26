# Передача сессии: LingoCanvas Cloud, этап 3

Папка: `translatepress/cloud` (backend платного провайдера перевода для плагина LingoCanvas; в ZIP плагина не входит).

## Объём этапа 3

Работающий MVP backend: аутентификация сайтов по токену, квоты символов (месячная корзина и пакеты), перевод через DeepSeek (и фальшивый провайдер для разработки), идемпотентные запросы, лимиты, аварийный выключатель расхода, CLI администратора, тесты.

## Что есть

- API: `POST /v1/translate`, `GET /v1/account`, `GET /health` (описание: `docs/API.md`).
- Квоты в `app/quota.py` под блокировкой строки аккаунта; CHECK-ограничения в БД; журнал `ledger`; sweeper для зависших резервов.
- Провайдеры: `deepseek` (httpx, thinking выключен, JSON-режим) и `mock`.
- CLI: аккаунты, сайты и токены, начисления месяца и пакетов, отзыв корзины, usage/spend, sweep.
- Тесты (pytest, встроенный PostgreSQL, без сети): авторизация, порядок списания, частичные результаты, идемпотентность, параллельность, sweeper, лимиты, DeepSeek через MockTransport, тарификация, приватность логов, CLI.
- Документация: README (EN/RU), `docs/QUICK_START.md`, `docs/API.md`.
- `Dockerfile`, `docker-compose.yml`, `.dockerignore` для будущего VPS (не запускались, Docker не установлен).

## Что НЕ сделано

- Stripe: подписки, пакеты, вебхуки (начисления пока вручную через CLI; `quota.grant` уже идемпотентен по `source_ref`).
- Интеграция в плагин (режим «LingoCanvas Cloud» в админке, хранение токена, обработка кодов ошибок из `docs/API.md`).
- Развёртывание на VPS (отдельный проект Docker Compose, своя БД, обратный прокси, HTTPS).
- Ни одного настоящего вызова DeepSeek (платные вызовы только с явного разрешения владельца).

## Как проверить

Git Bash:

```bash
cd /d/1PythonProjects/1_WORDPRESS/translatepress/cloud
source .venv/Scripts/activate
pytest -q
```

PowerShell:

```powershell
cd D:\1PythonProjects\1_WORDPRESS\translatepress\cloud
.venv\Scripts\Activate.ps1
pytest -q
```

Ожидается: все тесты зелёные, без `F`. Проверка соответствия миграций моделям (нужен запущенный локальный PostgreSQL и `DATABASE_URL` в `.env`, см. `docs/QUICK_START.md`, шаги 4-6):

```bash
python scripts/local_pg.py start
alembic upgrade head
alembic check
```

Ожидается: `alembic check` завершается без ошибок ("No new upgrade operations detected").

## Ревью Codex: найденное и исправленное

Миграция `0002_review_fixes` (после `alembic upgrade head` проверено на временной БД pgserver: бэкфилл, `alembic check` без отличий, downgrade/upgrade). Важно: на любой существующей БД нужно выполнить `alembic upgrade head`.

- **Устаревшая попытка (A -> sweeper -> B -> A завершается).** `settle()` закрывает резерв только если он `pending` И номер попытки совпадает (`attempt`, fencing-токен). Если попытку A уже освободили, а B заняла резерв, результат A не выдаётся и ничего не списывается: клиент получает `504 provider_timeout` и повторяет с тем же `request_id`.
- **Общий дедлайн.** `call_deadline_seconds` (150 с, не менее чем на 30 с меньше `reservation_ttl_seconds`; конструктор сервиса проверяет) ограничивает все вызовы модели одного запроса. Отмена запроса и дедлайн сразу освобождают резерв (а не по TTL) и пишут в `usage_log` расход по худшему случаю.
- **Повторы (replay) подчиняются лимитам.** Проходят `_check_rates`, занимают слот «в обработке» (`replay_started_at`, освобождается `finish_replay`), обновляют `last_attempt_at` (частота считается по нему; индекс `ix_reservations_site_attempt`).
- **Расход провайдера.** Допуск: потрачено сегодня + 2 x худший случай запроса <= лимита. Сбои с неизвестным расходом, которые могли быть оплачены (`maybe_billed`), пишутся по худшему случаю (`pricing.worst_case_micro_usd`). Повторная отправка у DeepSeek только при сбое соединения до отправки (ConnectError/ConnectTimeout/PoolTimeout), 5xx не повторяется; usage сохраняется даже при мусорном ответе; прочие 4xx — `ProviderRejected` (не оплачено).
- **Промпт.** Глоссарий передаётся как данные в сообщении пользователя, не в системном промпте (защита от инъекций); размер ограничен (`glossary_max_chars`, 2 x текстов + 200, иначе 413).
- **Приватность 422.** Ключи словарей `items`/`glossary` в `field` заменяются на `<key>`; в ответе только `field`, `message`, `type`.
- **Учёт.** `usage_log.billed_chars` переименован в `returned_chars` (работа провайдера, не счёт клиента); CLI `usage` показывает отдельно списания клиента (резервы/ledger) и работу провайдера.
- **Гранты.** `quota.grant` с тем же `source_ref` для другого аккаунта/типа/источника/суммы/дат бросает `GrantConflict` (CLI: понятная ошибка, ненулевой код выхода).
- Тесты: `tests/test_review_fixes.py` (детерминированные, на `asyncio.Event`), дополнены `test_provider_deepseek.py`, `test_cli.py`.

## Заметки

- Повтор успешного запроса бесплатен для клиента, но перевод снова запрашивается у модели (тексты не хранятся); повтор учитывается в лимитах частоты и одновременных запросов.
- Секреты только в `.env`; `.env` и `.env.example` структурно одинаковы.
