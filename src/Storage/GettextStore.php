<?php
/**
 * Хранилище gettext-части словаря.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * То, что {@see \WpMlp\I18n\GettextRegistry} требует от хранилища.
 *
 * Интерфейс введён ради тестируемости самой важной части контура —
 * порядка «наше переопределение → языковой пакет → оригинал» и правила
 * «в базу пишем только непокрытое». Проверить их на настоящем
 * {@see GettextRepository} нельзя: он ходит в `$wpdb`, которого в тестах
 * этого проекта нет вовсе. С интерфейсом та же логика гоняется на
 * массиве в памяти, без единой заглушки SQL.
 */
interface GettextStore {

	/**
	 * Ручные переопределения gettext-строк для языка.
	 *
	 * @param string $locale       Целевой язык (короткий код).
	 * @param int    $cacheVersion Номер версии кэша переводов.
	 * @return array<string, string> Ключ GettextKey::lookup() => перевод.
	 */
	public function overridesFor( string $locale, int $cacheVersion ): array;

	/**
	 * Заводит строки, которых ещё нет в словаре.
	 *
	 * @param list<array{msgid: string, domain: string, context: string, plural_key: ?int}> $rows Новые строки.
	 * @return int Сколько строк добавлено.
	 */
	public function insertMissing( array $rows ): int;
}
