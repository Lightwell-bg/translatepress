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
6. [ ] Тесты, повторный Plugin Check, финальное ревью, сборка ZIP.

## Журнал

- i18n-хвосты: `StringTranslationPage` использует `_x( ..., 'mid-sentence', ... )` для «OpenAI key/model» посреди фразы (в `.po` отдельные записи с `msgctxt`); добавлены русские переводы для сообщения о версии PHP (`wp-mlp.php`), «SEO/GEO» и ошибки недопустимого протокола ссылки; в `tests/stubs.php` добавлена заглушка `_x`; `.mo`, `.l10n.php`, `.pot` пересобраны.
- Смоук-тест в настоящем WordPress: `bash bin/smoke-test.sh` (+ `bin/smoke-test-blueprint.json`, `bin/smoke-test.php`, `bin/smoke-test-render.php`), WordPress Playground, PHP 8.3, WP_DEBUG. Проверяет активацию и таблицы, фронт на языке по умолчанию и `/en/` (hreflang), GET-маршруты REST, ручной перевод и отклонение `javascript:` в href, запросы с `%i`, встроенный ru_RU, удаление данных и `debug.log`. Ограничение: Playground работает на SQLite, где `BINARY = UNHEX()` не совпадает, поэтому проверка отображения перевода на `/en/` помечается `SKIP`.
- Документация: README.md / README_RU.md / docs/QUICK_START.md — `readme.txt`, `languages/` и пересборка переводов, `bin/plugin-check.sh`, `bin/smoke-test.sh`, состав ZIP и синхронизация `Stable tag`.
