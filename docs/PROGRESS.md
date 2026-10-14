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
