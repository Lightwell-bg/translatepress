<?php
/**
 * Идентичность gettext-строки.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

use WpMlp\Support\Hash;

/**
 * Тройка `(msgid, domain, context)` плюс форма множественного числа — то,
 * чем gettext-строка отличается от любой другой (ТЗ 4.8).
 *
 * Отдельный класс, потому что ключ считают двое и обязаны считать
 * одинаково: {@see GettextRepository} собирает его из строк БД при
 * загрузке словаря, {@see GettextRegistry} — из аргументов фильтра на
 * каждый вызов. Разойдись они хоть в порядке частей — переопределения
 * просто перестали бы находиться, молча.
 *
 * Ключей два, и это осознанно:
 *
 * - {@see lookup()} — дешёвая склейка для поиска в памяти. Фильтр
 *   `gettext` срабатывает сотни, а на тяжёлых страницах тысячи раз за
 *   запрос, и считать на каждый вызов SHA-256 (как делает uniqHash())
 *   значит платить за идентичность, которая нужна только при записи.
 * - {@see uniqHash()} — настоящая идентичность строки в БД, ровно та же
 *   формула из семи частей, что и у остальных видов строк
 *   (см. Rendering\Extractor::makeSegment()).
 */
final class GettextKey {

	/**
	 * Локаль оригинала gettext-строки.
	 *
	 * `msgid` в экосистеме WordPress по определению английский: это
	 * литерал из исходников темы или плагина, а не текст сайта. Поэтому у
	 * `kind = 'gettext'` `source_locale` — всегда `en_US`, независимо от
	 * того, на каком языке пишет контент владелец сайта. Именно это делает
	 * словарь общим для всех целевых языков сразу: строка «Reply» одна и
	 * та же и для русского сайта, и для болгарского.
	 */
	public const SOURCE_LOCALE = 'en_US';

	/**
	 * Значение `kind` в таблице `sources`.
	 */
	public const KIND = 'gettext';

	/**
	 * Дешёвый ключ для карты в памяти. Чистая функция.
	 *
	 * @param string   $msgid     Оригинал строки (английский литерал из кода).
	 * @param string   $domain    Text domain, например `default` или `woocommerce`.
	 * @param string   $context   Контекст из `_x()`, пустая строка — если его нет.
	 * @param int|null $pluralKey Номер формы множественного числа, null — не множественное.
	 */
	public static function lookup( string $msgid, string $domain, string $context, ?int $pluralKey ): string {
		return implode(
			"\x1F",
			array( $domain, $context, null === $pluralKey ? '' : (string) $pluralKey, $msgid )
		);
	}

	/**
	 * `uniq_hash` строки в таблице `sources`. Чистая функция.
	 *
	 * Порядок частей повторяет уникальный индекс ТЗ 6.1 и обязан совпадать
	 * с Extractor::makeSegment() — см. HashCompatibilityTest.
	 *
	 * `context_hash` здесь — хеш пустой строки, как и у всех остальных
	 * видов строк: это поле про МЕСТО использования, зарезервированное на
	 * будущее, а не про контекст `_x()`. Контекст `_x()` живёт только в
	 * части `gettext_context`, путать их нельзя.
	 *
	 * @param string   $msgid     Оригинал строки.
	 * @param string   $domain    Text domain.
	 * @param string   $context   Контекст из `_x()`.
	 * @param int|null $pluralKey Номер формы множественного числа.
	 */
	public static function uniqHash( string $msgid, string $domain, string $context, ?int $pluralKey ): string {
		return Hash::ofParts(
			array(
				self::SOURCE_LOCALE,
				self::KIND,
				Hash::of( $msgid ),
				Hash::of( '' ),
				$domain,
				$context,
				null === $pluralKey ? '' : (string) $pluralKey,
			)
		);
	}
}
