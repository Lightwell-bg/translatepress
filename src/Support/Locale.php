<?php
/**
 * Валидация и нормализация языковых кодов и URL-слагов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Единственное место, где решается, что считать допустимым кодом языка.
 *
 * Коды языка попадают в имена ключей кэша, в SQL и в URL, поэтому они
 * проверяются по allowlist-регулярке (раздел 13 ТЗ), а не экранированием.
 */
final class Locale {

	/**
	 * Максимальная длина кода: столько же, сколько в колонках БД (varchar(20)).
	 */
	public const MAX_LENGTH = 20;

	/**
	 * Максимальная длина URL-слага языка.
	 */
	public const MAX_SLUG_LENGTH = 20;

	/**
	 * Приводит код к каноническому виду: нижний регистр, `_` → `-`.
	 *
	 * @param string $locale Исходный код, например ` pt_BR `.
	 */
	public static function normalize( string $locale ): string {
		return strtolower( str_replace( '_', '-', trim( $locale ) ) );
	}

	/**
	 * Допустим ли код языка.
	 *
	 * Формат: 2–8 латинских букв, далее сколько угодно субтегов из 2–8
	 * букв/цифр через дефис. Примеры: `ru`, `en`, `pt-br`, `zh-hans-cn`.
	 *
	 * @param string $locale Нормализованный или сырой код.
	 */
	public static function isValid( string $locale ): bool {
		$locale = self::normalize( $locale );

		if ( '' === $locale || strlen( $locale ) > self::MAX_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z]{2,8}(-[a-z0-9]{2,8})*$/', $locale );
	}

	/**
	 * Допустим ли URL-слаг языка.
	 *
	 * Слаг попадает в rewrite-правила и в путь, поэтому разрешены только
	 * строчные буквы, цифры и дефис между ними.
	 *
	 * @param string $slug Слаг из настроек.
	 */
	public static function isValidSlug( string $slug ): bool {
		$slug = strtolower( trim( $slug ) );

		if ( '' === $slug || strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Приводит слаг к каноническому виду.
	 *
	 * @param string $slug Сырой слаг.
	 */
	public static function normalizeSlug( string $slug ): string {
		return strtolower( trim( $slug ) );
	}

	/**
	 * Код в форме BCP-47 для атрибутов `lang` и `hreflang`.
	 *
	 * `pt-br` → `pt-BR`, `zh-hans-cn` → `zh-Hans-CN`. Регистр не влияет на
	 * трактовку поисковиками, но так значение выглядит канонично.
	 *
	 * @param string $locale Нормализованный код.
	 */
	public static function toBcp47( string $locale ): string {
		$parts = explode( '-', self::normalize( $locale ) );

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index ) {
				continue;
			}

			if ( 4 === strlen( $part ) ) {
				// Субтег письменности: Hans, Cyrl.
				$parts[ $index ] = ucfirst( $part );
			} elseif ( strlen( $part ) <= 3 ) {
				// Субтег региона: BR, 419.
				$parts[ $index ] = strtoupper( $part );
			}
		}

		return implode( '-', $parts );
	}
}
