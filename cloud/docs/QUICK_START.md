# Быстрый старт LingoCanvas Cloud (локально)

Основной вариант: PowerShell на Windows. Вариант для Git Bash указан отдельно после каждого блока там, где команды отличаются. Платные вызовы не делаются: по умолчанию `PROVIDER=mock` (фальшивые переводы).

## 1. Откройте PowerShell в папке проекта

1. Нажмите клавишу Windows, наберите `PowerShell`, откройте «Windows PowerShell».
2. Вставьте и нажмите Enter:

```powershell
cd D:\1PythonProjects\1_WORDPRESS\translatepress\cloud
```

После этого в начале строки будет путь `...\translatepress\cloud>`.

## 2. Виртуальное окружение (venv)

Это отдельная папка `.venv` с нужными библиотеками Python, чтобы не засорять систему.

```powershell
python -m venv .venv
.venv\Scripts\Activate.ps1
```

Что появится: в начале строки приписка `(.venv)`. Если PowerShell пишет, что запуск скриптов запрещён, выполните один раз `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`, ответьте `Y` и повторите вторую команду.

Git Bash: `python -m venv .venv` и `source .venv/Scripts/activate`.

## 3. Установка зависимостей

```powershell
pip install -r requirements-dev.txt
```

Что появится: бегущий список пакетов и в конце `Successfully installed ...`.

## 4. Локальная база данных PostgreSQL

```powershell
python scripts/local_pg.py start
```

Что появится: одна строка вида `postgresql+asyncpg://postgres:@localhost:.../lingocanvas`. Скопируйте её целиком (это адрес базы). База продолжает работать в фоне после завершения команды. Остановить позже: `python scripts/local_pg.py stop`.

## 5. Файл настроек `.env`

1. Если файла `.env` нет: `Copy-Item .env.example .env`
2. Откройте `.env` в Блокноте: `notepad .env`
3. Заполните (после знака `=` без пробелов и кавычек):
   - `DATABASE_URL=` — строка из шага 4.
   - `PROVIDER=mock` — оставьте как есть (фальшивые переводы, бесплатно).
   - `DEEPSEEK_API_KEY=` — оставьте пустым (нужен только при `PROVIDER=deepseek`).
   - `TOKEN_PEPPER=` — случайная строка не короче 32 символов. Получить: выполните `python -c "import secrets;print(secrets.token_urlsafe(48))"` и вставьте результат.
   - `DAILY_SPEND_LIMIT_USD=5` — оставьте.
   - `STRIPE_*` (в конце файла) — оставьте пустыми: оплата выключена (адреса `/v1/billing/...` отвечают `503 billing_disabled`), перевод работает как обычно. Как включить тестовую оплату: `docs/STRIPE_SETUP.md`.
4. Сохраните (Ctrl+S) и закройте Блокнот.

Не показывайте и не отправляйте файл `.env` никому: в нём секреты.

## 6. Создание таблиц (миграции)

```powershell
alembic upgrade head
```

Что появится: строки `Running upgrade  -> 0001, ...` (и далее до `0004`; в `0003` создаются таблицы оплаты, в `0004` добавляются споры, отложенные возвраты и защита от двойной оплаты). Если пишет про недоступную базу, проверьте шаг 4 и значение `DATABASE_URL`.

## 7. Тестовый аккаунт, сайт и квота

```powershell
python -m app.cli create-account --name "Test" --plan starter
python -m app.cli add-site --account 1 --name test.example.com
python -m app.cli grant-month --account 1
```

Что появится: `account id=1 ...`, затем `site id=1` и строка токена вида `lcc_...` (**показывается один раз**, скопируйте и сохраните его), затем `bucket id=1 created`. Если номер аккаунта в ответе не `1`, подставьте свой номер вместо `1`.

## 8. Запуск сервера

```powershell
uvicorn app.main:app --host 127.0.0.1 --port 8000
```

Окно должно оставаться открытым, пока сервер нужен. Что появится: `Uvicorn running on http://127.0.0.1:8000`. Остановить: Ctrl+C. Дальше откройте **второе** окно PowerShell, перейдите в папку (шаг 1) и активируйте venv (шаг 2, вторая команда).

## 9. Проверка перевода

Во втором окне PowerShell (вместо `lcc_ВАШ_ТОКЕН` вставьте токен из шага 7):

```powershell
Invoke-RestMethod http://127.0.0.1:8000/health

$token = "lcc_ВАШ_ТОКЕН"
$body = @{ request_id = "test-0001-abcd"; source_locale = "en"; target_locale = "de"; items = @{ k1 = "Hello"; k2 = "World" } } | ConvertTo-Json -Depth 5
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8000/v1/translate -Headers @{ Authorization = "Bearer $token" } -ContentType "application/json; charset=utf-8" -Body $body
```

Что появится: сначала `status ok`, затем ответ с `translations` (`[de] Hello`, `[de] World`), `billed_chars 10` и остатком квоты. Повторный запуск с тем же `request_id` вернёт `replay True` и `billed_chars 0`. Чтобы сделать новый запрос, поменяйте `request_id` (8–64 символа: буквы, цифры, `_`, `-`).

Остаток квоты и число сайтов: `Invoke-RestMethod http://127.0.0.1:8000/v1/account -Headers @{ Authorization = "Bearer $token" }`.

## 10. Запуск тестов

```powershell
pytest -q
```

Что появится: строки точек и в конце процент `100%` без `F` (ошибок). Тесты поднимают свою временную базу и ничего не оплачивают.

## Если что-то не получилось

- `python` не найден: установите Python 3.12 и откройте PowerShell заново.
- `401 invalid_token`: токен скопирован не полностью или создан при другом `TOKEN_PEPPER`.
- `402 insufficient_quota`: не выполнен `grant-month` (шаг 7) или квота закончилась.
- Остальные коды ошибок: [API.md](API.md).
