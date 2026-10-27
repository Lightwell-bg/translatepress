# Передача сессии: LingoCanvas Cloud, этапы 3 и 4

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

## Этап 4: оплата Stripe (только локально, тестовый режим)

Реальных ключей Stripe нет, ни одного сетевого вызова к Stripe не делалось; тесты работают с фальшивым шлюзом (`tests/billing_fakes.py`). Развёртывания нет.

- Код: `app/billing/events.py` (обработчик вебхуков), `gateway.py` (нормализованные объекты), `stripe_gateway.py` (Stripe SDK `stripe==16.0.0`, `API_VERSION = 2026-09-30.endive`), `app/api/billing.py` (маршруты), миграция `0003_stripe_billing`.
- Эндпоинты: `POST /v1/billing/signup`, `/claim`, `/checkout`, `/portal`, `POST /v1/sites/pair-code`, `/pair`, `POST /v1/stripe/webhook`; `GET /v1/account` теперь отдаёт `subscription`. Описание и коды ошибок: `docs/API.md`.
- Общий аккаунт Stripe с Vira: все наши объекты помечены `lc_app=lingocanvas`; чужие события получают `200 ignored` без вызовов Stripe и без записи в наши таблицы, кроме строки `stripe_events` (тесты `test_foreign_events_*`). Ключи `user_id`/`product` мы не пишем.
- Покупатели: все Customer LingoCanvas создаёт бэкенд (`gateway.create_customer`, при `POST /v1/billing/signup`, ключ идемпотентности `lc-customer:<claim_id>`) с метаданными `lc_app=lingocanvas`; id хранится в `checkout_claims.stripe_customer_id` (миграция `0005`), в Checkout передаётся `customer`, а не `customer_email`. Именно так Vira узнаёт события LingoCanvas (метаданные не наследуются, а на покупателя ссылаются все объекты). Никогда не создавать покупателей LingoCanvas вручную в Dashboard без этой пометки.
- Цена пакета в вебхуке: тело `checkout.session` не содержит позиций, поэтому цена пакета кладётся в метаданные сессии `lc_price_id` при создании (и читается оттуда парсером).
- Настройки: `.env` (`STRIPE_*`, пустые значения = оплата выключена, 503 `billing_disabled`), `config.ini` раздел `[billing]`. Пошаговая настройка тестового Stripe: `docs/STRIPE_SETUP.md`.
- CLI: `python -m app.cli billing-status --account ID`.
- Тесты: `tests/test_billing_webhook.py`, `tests/test_billing_api.py`, `tests/test_stripe_gateway.py` (+ фикстуры JSON двух форм API в `tests/fixtures/stripe/`).
- Миграция 0003 проверена на временной БД pgserver: `upgrade head`, `alembic check` (без отличий), `downgrade 0002`, снова `upgrade head`.
- Проверка подписи Stripe SDK односторонняя: слишком старые метки времени отклоняются, метки из будущего нет (это поведение SDK).
- За реверс-прокси нужен `--proxy-headers` (уже в Dockerfile): по IP клиента ограничивается число регистраций.

### Этап 4: замечания ревью Codex и исправления

Миграция `0004_stripe_review_fixes` (проверена на временной БД pgserver: бэкфилл строк, созданных до неё, включая платёж на 0; `alembic check` без отличий; downgrade до 0003 и снова upgrade). Ограничение `ck_payments_paid` добавлено как `NOT VALID`, чтобы старая строка с нулевой суммой не блокировала обновление. На любой существующей БД нужно выполнить `alembic upgrade head`.

- **Возврат при резерве.** `quota_buckets.revoke_pending`: часть возврата, пришедшаяся на зарезервированные символы, забирается, когда резерв освобождён (`settle`); если символы списаны, они потрачены законно, и «ожидающий» возврат обнуляется без нарушения CHECK. Доступно = выдано - потрачено - резерв - ожидающий возврат.
- **Гонка снимков подписки.** Состояние подписки читается из Stripe ВНУТРИ транзакции после `pg_advisory_xact_lock` на подписку: кто фиксирует позже, тот и читал позже. Тест с управляемой фальшивой подпиской показывает, что второй запрос не читает подписку, пока первый не зафиксирован.
- **Возвраты и споры раньше платежа.** Таблица `unmatched_charge_events` (только id и суммы); применяются при записи платежа; чужие (Vira) никогда не совпадают и удаляются через `[billing] unmatched_retention_days = 90` (sweeper, отдельная транзакция на каждый круг).
- **Споры.** Таблица `disputes` (финальное состояние не откатывается), флаг `accounts.dispute_hold` вместо смены `accounts.status` (выключатель администратора больше не трогается). Удержание даёт `403 account_suspended` на переводе, получении токена по заявке (`/claim`) и привязке сайта (`/pair`).
- **Бесплатные счета.** Счёт на 0 или не в EUR ничего не начисляет; заявка регистрации становится «оплачена» только в `invoice.paid` после начисления (не при создании подписки или завершении checkout).
- **Двойная подписка.** Колонки `accounts.pending_checkout_*`: оформление Starter сериализуется по аккаунту (advisory-блокировка `lc-checkout:<id>`, не блокировка строки аккаунта, чтобы переводы не ждали ответа Stripe), повторный или параллельный запрос возвращает ту же ссылку (23 ч), просроченная заменяется, при живой подписке запись очищается.
- **Строка счёта.** Выбирается строка с известной ценой тарифа, не перерасчёт (старая форма: `line.proration`, `line.type`; новая: `line.parent.subscription_item_details.proration`, `line.parent.type`); если такой нет, `price_id = None`, обработчик бросает `BillingError` (500, Stripe повторит). Цены тарифов передаются в `StripeSdkGateway(plan_price_ids=...)`.
- **Портал.** Без `STRIPE_PORTAL_CONFIGURATION` `POST /v1/billing/portal` отвечает `503 portal_not_configured` и не вызывает шлюз; шлюз всегда передаёт `configuration`.
- **CLI-тест.** `test_cli_billing_status_shows_state_without_secrets` проверяет поля по отдельности и по строкам (раньше подстрока `plan=starter status=active` совпадала только со строкой подписки).
- Тесты: `tests/test_billing_review.py` (новые), дополнены `test_billing_webhook.py`, `test_stripe_gateway.py` (+ фикстуры `invoice_multiline_*.json`), `tests/billing_fakes.py` (`retrieve_script`, `checkout_delay`, `plan_price_ids`).
- Дубли блокировки: оформление Starter держит advisory-блокировку на время запроса к Stripe; `uuid` ключ идемпотентности не детерминирован, поэтому сбой между созданием сессии и записью ссылки оставит открытую «лишнюю» сессию в Stripe (оплатить две можно, только открыв обе ссылки).

## Что НЕ сделано

- Боевой режим Stripe (только после отдельного согласования владельца; чек-лист в конце `docs/STRIPE_SETUP.md`), реальные ключи, реальный вызов Stripe API, проверка на настоящих событиях через Stripe CLI.
- Страница `https://centerai.eu/lingocanvas/thanks/` и `.../account/` на сайте (адреса заданы в `config.ini`).
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

## Восстановление токена заявки (claim recovery)

Проблема: ответ `POST /v1/billing/claim` с токеном мог потеряться, а повтор давал `409 already_claimed` навсегда. Решение (миграция `0006_claim_recovery`: `checkout_claims.site_id`, `consumed_at`, `recovery_until`, статус `confirmed`):
- `consumed` + `now < recovery_until` + сайт не отозван + аккаунт не приостановлен: тот же секрет ротирует токен того же сайта (`recovered: true`, старый токен сразу недействителен, новых сайтов/квот нет). Окно `claim_recovery_hours` (config.ini [billing], по умолчанию 24).
- Новый `POST /v1/billing/claim/confirm` (токен сайта, тело `{claim_id}`): `consumed` -> `confirmed`, `recovery_until = NULL`; идемпотентен; чужая или неизвестная заявка даёт `404 claim_not_found`; токен, ротированный параллельным запросом, даёт `401`.
- Старые `consumed` строки (`recovery_until IS NULL`) не восстанавливаются: `409 already_claimed`. Отозванный сайт: `409 site_revoked`; hold/приостановка: `403`.
- Тесты: `tests/test_claim_recovery.py`. Проверено `alembic upgrade/check/downgrade` на временной БД pgserver. Нужно выполнить `alembic upgrade head` на существующей БД.

## Заметки

- Повтор успешного запроса бесплатен для клиента, но перевод снова запрашивается у модели (тексты не хранятся); повтор учитывается в лимитах частоты и одновременных запросов.
- Инвариант Stripe: каждый Customer LingoCanvas несёт metadata `lc_app=lingocanvas` (по ней продукт Vira узнаёт наши события). При регистрации покупатель создаётся с меткой; перед checkout пакета, повторным checkout Starter и порталом вызывается `gateway.ensure_customer_tag` (сбой даёт 502, сессия не создаётся). Боевых покупателей LingoCanvas раньше не было, backfill не нужен; созданному вручную в Dashboard покупателю нужно задать `lc_app=lingocanvas`. Тесты: `tests/test_billing_customer_tag.py`.
- Секреты только в `.env`; `.env` и `.env.example` структурно одинаковы.
