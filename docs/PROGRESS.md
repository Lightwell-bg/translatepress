# Подготовка к WordPress.org — прогресс

Ветка: `release/wporg-prep` (откат: `git checkout feat/link-translation`).

## Решения владельца

- Название «WP Multilang Press» и text domain `wp-mlp` пока оставляем. Перед подачей
  плагин нужно переименовать: каталог не принимает «WP» в начале названия и окончание
  «-Press», а название слишком похоже на существующий плагин «WP Multilang».
- Строки-источники переводим на английский, русский интерфейс поставляем в
  `languages/wp-mlp-ru_RU.po/.mo/.l10n.php`.

## План

1. [x] Аудит: соответствие правилам WP.org / Plugin Check, безопасность REST и HTML, хранение ключа.
2. [x] Plugin Check: локальный запуск (`bin/plugin-check.sh`), базовый отчёт.
3. [x] Исправления соответствия и безопасности (ABSPATH-защита, putenv, файловые операции, санитизация, uninstall, заголовок, сборщик).
4. [x] i18n: английские строки-источники (PHP и JS) + ru_RU `.po` → `.mo`/`.l10n.php` (`bin/`).
5. [x] `readme.txt` (англ.), раздел External services (OpenAI).
6. [x] Тесты (1034 OK), повторный Plugin Check (0 ERROR / 10 WARNING), smoke-тест OK, ревью Codex (6 находок исправлены), сборка `dist/_20wp-mlp.zip` (0.3.13).

## Осталось перед подачей

- Переименовать плагин (название, slug, text domain, префикс хуков `mlp_*` → по новому slug).
- Заменить `Contributors: lightwell` в `readme.txt` на настоящий логин wordpress.org.
- Проверить на MySQL: отображение сохранённого перевода на `/en/` (Playground на SQLite это не проверяет).

## Ревью WordPress.org 17.10.2026 → 0.4.x (копирующая миграция префикса)

Спецификация миграции (`docs/PREFIX_MIGRATION.md`) удалена вместе с самим механизмом, см. раздел «Очистка публичного релиза» ниже.

Сделано (рабочая копия, не закоммичено):
- [x] Санитизация `$_SERVER`/`$_POST` (белый список полей), исключения sitemap через `sanitize_title`, аудит nonce/прав, `ob_start` закрывается на `shutdown`.
- [x] Переименование `WpMlp`/`mlp_`/`wp-mlp` → `LingoCanvas`/`lingocanvas_` с алиасами совместимости (шорткод, хуки, REST `mlp/v1`, sitemap 301, старые slug админки, env/константы).
- [x] Копирующая миграция с переключателем `DataPrefix`, заморозкой записи, сверкой; откат (кнопка + WP-CLI), восстановление прерванного отката.
- [x] Codex-ревью (3-я попытка, diff передан во входе — exec-песочница Codex на этой машине не работает): 8 находок исправлены.
- [x] PHPUnit 1342 OK, smoke (Playground/SQLite) 260 OK / 7 skip, Plugin Check ERROR=0.
- [x] Тестовая сборка `dist/lingocanvas-0.4.1.zip` (НЕ для отправки).

Осталось:
- [x] Прогоны на MariaDB (portable в `D:\LingoCanvasTestEnv`, 127.0.0.1:3307): обновление 0.3.12 и 0.3.14 → 0.4.x (U1-U6), обрыв PHP, права, откат, recover (F1/F2/R1/R2, ниже). Не покрыто: нехватка места на диске, два параллельных процесса миграции, latin1.
- [ ] Отдельный PHPCS (composer/phpcs не установлены).
- [x] Финальная сборка: заголовки readme.txt «= 0.4.0 =» приведены к 0.4.2 (версия, которую выставил `bin/build-zip.php`).
- [ ] Коммит, ответ ревьюеру WP.org (коротко, без перечисления изменений).

Результаты 19.10.2026 (MariaDB, сайт `up0314`, BULK=1000000, копия ~25 с; запуск `bash bin/mariadb-test/run.sh snap|f1|f2|r1|r2 up0314`, описание в `bin/mariadb-test/README.md`):
- Баг: старые адреса `admin.php?page=wp-mlp*` давали 403 раньше `admin_init` (`user_can_access_admin_page` в `wp-admin/includes/menu.php`). `LegacyAdminRedirect` теперь ещё и на `admin_page_access_denied` (приоритет 1), `admin_init` оставлен; тест на регистрацию обоих хуков. Проверено по HTTP под администратором: `wp-mlp`, `wp-mlp-editor`, `wp-mlp-strings` → 301 на `lingocanvas`, `lingocanvas-editor`, `lingocanvas-strings` с сохранением остальных параметров.
- F1 (kill PHP на ~407 тыс. из 1 млн строк occurrences): PASS. Переключатель `mlp_`, статус `copying`, замок `held`, старые данные байт-в-байт как до миграции, переводы на `/en/` и `/pt-br/` отдаются; повторный `migrate` отказывает («Another data migration is running»). Метки замка и заморозки (`lingocanvas_migration_lock`, `lingocanvas_write_freeze`) сдвинуты на `now-7200` с (старше TTL 3600 с) → `migrate` довёл до `done`, новый набор равен старому по хэшам, старый не изменился.
- F2 (пользователь БД только SELECT/INSERT/UPDATE/DELETE): PASS. Статус `failed`, `last_error` = «table wp_lingocanvas_sources could not be created (missing CREATE privilege?)» (сырой текст MariaDB в ошибку не попадает), переключатель `mlp_`, старые данные не изменены, фронт отдаёт переводы; пользователь удалён, в wp-config снова `lc_test`.
- R1 (откат из CLI после правок: перевод, настройка, видимость записи, счётчик, флаг): PASS. `rolled_back`, переключатель `mlp_`, 5 таблиц `lingocanvas_bak_<метка>_*` + опция с копией, контрольные суммы копий равны старым таблицам до отката, старые имена содержат все правки, новый набор не тронут, список активных плагинов не изменился, запрос администратора не запускает миграцию заново.
- R2 (kill PHP во время отката, после синхронизации двух таблиц; `translations` уже перезаписана): PASS. Статус `rolling_back`, переключатель `lingocanvas_`, новый `rollback` и `recover` отказывают при живом замке; после сдвига меток замка `recover --yes` вернул старые данные (равны копии и данным до отката), статус `rollback_failed`, переключатель `lingocanvas_`; затем `rollback --yes` прошёл, старый набор равен новому на момент до отката.
- Ошибок плагина по итогам F1/F2/R1/R2 не найдено. Правки только в harness: `run.sh` (snap, f1, f2, r1, r2), `killwp.ps1`, режимы `new-untouched` и `rolled-back` в `fingerprint.php`.
- PHPUnit 1343 OK (5186 assertions); Plugin Check ERROR=0, WARNING=2 (load_plugin_textdomain, DynamicHooknameFound).
- ZIP: `D:\1PythonProjects\1_WORDPRESS\translatepress\dist\lingocanvas-0.4.2.zip` (0.4.2: `Version`, `LINGOCANVAS_VERSION`, `Stable tag` совпадают; одна папка `lingocanvas/`, 141 файл, `php -l` по 126 PHP-файлам чисто, без tests/docs/bin/.env).

## Очистка публичного релиза от механизма миграции (0.4.3)

Условия владельца: плагин никогда не был публичным, его единственный сайт уже работает на `lingocanvas_*` (миграция выполнена). «Разрешаю очистить публичный релиз LingoCanvas от механизма миграции с WP Multilang Press». Сохраняются: `Schema::VERSION` (2), `Schema::install()`, `Schema::maybeUpgrade()` и dbDelta; имена таблиц, опций, метаполей, виджета (`lingocanvas_language_switcher`), папки `lingocanvas-flags`, REST `lingocanvas/v1` не менялись.

Сделано (рабочая копия, не закоммичено):
- [x] `lingocanvas.php`: убраны совместимость с прежним плагином, отложенная активация, уведомление «на паузе», хук `pre_uninstall_plugin`; активация — обычный `Plugin::activate()`; env-файлы — только `lingocanvas.env.php`, `lingocanvas.env`, `.env` в папке плагина.
- [x] Удалены `PrefixMigration`, `PrefixRollback`, `MigrationState`, `MigrationLock`, `MigrationDb`, `DataSet`, `WriteFreeze`, `RunGuard`, `FreshOption`, `FlagsDirectory`, три исключения миграции, `Cli/MigrationCommand`, `Admin/DataStorageSection`, `StaleStringsCleanup`, `OccurrenceObjectTypeMigration`, `DataPrefix` (имена хранения теперь константы), `LegacyRestAliases`, `LegacyAdminRedirect`; убраны все проверки `WriteFreeze` в местах записи.
- [x] Убраны псевдонимы: шорткод `[mlp_language_switcher]`, устаревшие хуки `mlp_*` (включая `mlp_translation_provider` и `mlp_purge_page_cache`), 301 со старой карты сайта, запасные env-файлы и константы `WP_MLP_OPENAI_*`, запасная папка флагов `wp-mlp-flags` и метки `.deleted-*`.
- [x] `uninstall.php`: только набор `lingocanvas_*`; служебные опции 0.4.x убираются всегда, таблицы (включая `lingocanvas_bak_*`), опции, метаполя, транзиенты и папка флагов — только с галочкой владельца; `mlp_*` и `wp-mlp-flags` не затрагиваются никогда.
- [x] Тесты: удалены тесты удалённого кода, добавлены `tests/Storage/UninstallTest.php` и обновлён `tests/PluginActivationTest.php` (чистая установка создаёт четыре таблицы и не пишет служебных опций).
- [x] `bin/`: удалён `smoke-test-migration.php` (сценарии S2–S5), смоук оставлен S1 и S6; `bin/mariadb-test/` сокращён до `reset` / `fresh` / `upgrade` (после правки не запускался).
- [x] Документация: удалён `docs/PREFIX_MIGRATION.md`, README.md / README_RU.md, readme.txt (changelog — одна запись `= 0.4.3 =`), QUICK_START.
- [x] Версия поднята до 0.4.3 сборкой `bin/build-zip.php`: `D:\1PythonProjects\1_WORDPRESS\translatepress\dist\lingocanvas-0.4.3.zip` (121 файл, одна папка `lingocanvas/`, `php -l` чисто, следов `mlp_*`/`PrefixMigration`/`DataPrefix` нет).
- [x] Замечание Codex исправлено: `Schema::install()` сохраняет `lingocanvas_db_version` только если после dbDelta все 4 таблицы существуют (`SHOW TABLES LIKE`), иначе возвращает `false`, и `maybeUpgrade()` повторяет установку на следующем запросе в админке. Тесты в `tests/PluginActivationTest.php`; phpunit 1089 зелёные, plugin-check ERROR=0.
- [x] Проверки 19.10.2026 (без сборки, без коммита):
  - PHPCS (WordPress, PHPCS 4.0.4 + WPCS 3.4.1, лежат в `D:\LingoCanvasTestEnv\tools`, запуск `tools/phpcs.sh phpcs --standard=WordPress src lingocanvas.php uninstall.php`): было 1981 ошибка / 156 предупреждений, стало 1973 / 156. Остаток почти целиком стиль (camelCase имён, имена файлов классов PSR-4, формат докблоков, CRLF в 29 файлах, выравнивание); безопасностных замечаний (экранирование, nonce, SQL без prepare) нет. Исправлено: Yoda (2), пробелы после оператора и запятой (5), скобки в смешанном `||`/`&&` (1).
  - `bin/plugin-check.sh`: ERROR=0, WARNING=1 (`load_plugin_textdomain` discouraged). `bin/smoke-test.sh`: S1 53 ok / 4 skip / 0 fail, S6 28 ok / 0 fail. phpunit: 1087 тестов зелёные.
  - MariaDB: чистая установка (`lc_fresh`) 18/18 проверок; обновление копии мигрированного сайта (0.4.2 -> рабочая копия, 22 страницы, отпечаток данных) - данные и опции идентичны, страницы идентичны, кроме двух с шорткодом `[mlp_language_switcher]` в контенте записи (шорткод удалён по решению владельца, в тексте остаётся буквально).
  - Найдено и исправлено: `uninstall.php` не удалял опцию `lingocanvas_bak_<метка>_options` (копия опций от прошлых версий); теперь удаляется вместе с резервными таблицами (с галочкой владельца), тест в `UninstallTest`.
  - `bin/mariadb-test/run.sh`: добавлены `snap`, `compare`, `swap`, `admin`, `uninstall`, сайты `fresh` / `u42` / `u42un`, сценарий `fresh` расширен (строки, REST, /en/, виджет, шорткод, карта сайта); исправлена работа `curl.exe` с путями `/d/...`.

## Ребрендинг LingoCanvas

Название: LingoCanvas (разработчик CenterAI, контрибьютор WP.org `centeraieu`), slug и text domain `lingocanvas`, главный файл `lingocanvas.php`, версия 0.3.13. Решение выше про «WP Multilang Press» и `wp-mlp` заменено этим разделом.

План и статус:

1. [x] Совместимость данных: таблицы `mlp_*`, опции `mlp_*`, метаполя `_mlp_*`, REST `mlp/v1`, шорткод `[mlp_language_switcher]`, константы `WP_MLP_OPENAI_*`, файлы `wp-mlp.env*`, `uploads/wp-mlp-flags`, `/wp-mlp-sitemap.xml` и слаги админ-страниц не менялись.
2. [x] Защита перехода (`lingocanvas.php`): пока прежний плагин активен, LingoCanvas ничего не загружает и показывает уведомление; активация LingoCanvas выключает прежний плагин без его хука деактивации и откладывает свою настройку до следующего запроса; при удалении прежнего плагина общие данные не стираются.
3. [x] Хуки переименованы в `lingocanvas_*`, старые `mlp_*` работают как устаревшие алиасы (`apply_filters_deprecated` / `do_action_deprecated`).
4. [x] Сборка: `bin/build-zip.php` даёт `dist/lingocanvas-<версия>.zip` (папка `lingocanvas/`); переводы `languages/lingocanvas-ru_RU.*`, `lingocanvas.pot`.
5. [x] Документация: readme.txt (FAQ про переход, список хуков, changelog), README.md / README_RU.md (раздел «Переход с WP Multilang Press», таблица хуков, список неизменённых идентификаторов), QUICK_START, user-guide, data-storage, technical-spec.
6. [x] Префикс сообщений `error_log`: `[wp-mlp]` -> `[lingocanvas]` (PHP; JS-консоль не менялась).
7. [ ] Проверить на реальном сайте переход с установленного WP Multilang Press (активация, откат, удаление старого плагина).

## Журнал

- i18n-хвосты: `StringTranslationPage` использует `_x( ..., 'mid-sentence', ... )` для «OpenAI key/model» посреди фразы (в `.po` отдельные записи с `msgctxt`); добавлены русские переводы для сообщения о версии PHP (`wp-mlp.php`), «SEO/GEO» и ошибки недопустимого протокола ссылки; в `tests/stubs.php` добавлена заглушка `_x`; `.mo`, `.l10n.php`, `.pot` пересобраны.
- Смоук-тест в настоящем WordPress: `bash bin/smoke-test.sh` (+ `bin/smoke-test-blueprint.json`, `bin/smoke-test.php`, `bin/smoke-test-render.php`), WordPress Playground, PHP 8.3, WP_DEBUG. Проверяет активацию и таблицы, фронт на языке по умолчанию и `/en/` (hreflang), GET-маршруты REST, ручной перевод и отклонение `javascript:` в href, запросы с `%i`, встроенный ru_RU, удаление данных и `debug.log`. Ограничение: Playground работает на SQLite, где `BINARY = UNHEX()` не совпадает, поэтому проверка отображения перевода на `/en/` помечается `SKIP`.
- Документация: README.md / README_RU.md / docs/QUICK_START.md — `readme.txt`, `languages/` и пересборка переводов, `bin/plugin-check.sh`, `bin/smoke-test.sh`, состав ZIP и синхронизация `Stable tag`.
