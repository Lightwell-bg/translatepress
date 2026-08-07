<?php
/**
 * Хеши, которыми идентифицируются строки.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * SHA-256 в hex-виде.
 *
 * В БД хеши лежат в колонках binary(32) (ТЗ 6.1), но в PHP они всегда
 * шестнадцатеричные строки: сырые байты пришлось бы экранировать при каждом
 * запросе. В SQL используются UNHEX() при записи и HEX() при чтении — так
 * запрос остаётся текстовым, а колонка занимает 32 байта вместо 64.
 */
final class Hash {

	/**
	 * Разделитель частей составного ключа.
	 *
	 * Unit Separator (0x1F) выбран потому, что он не встречается в тексте
	 * страниц и в кодах языка, значит склейка `a|b` и `ab|` не совпадут.
	 */
	private const SEPARATOR = "\x1F";

	/**
	 * SHA-256 значения в hex.
	 *
	 * @param string $value Значение.
	 */
	public static function of( string $value ): string {
		return hash( 'sha256', $value );
	}

	/**
	 * SHA-256 составного ключа.
	 *
	 * @param list<string|int|null> $parts Части ключа в фиксированном порядке.
	 */
	public static function ofParts( array $parts ): string {
		$normalized = array_map(
			static fn( $part ): string => null === $part ? '' : (string) $part,
			$parts
		);

		return self::of( implode( self::SEPARATOR, $normalized ) );
	}

	/**
	 * Похоже ли значение на hex-хеш SHA-256.
	 *
	 * Проверяется перед подстановкой в UNHEX(): в SQL не должно попасть
	 * ничего, кроме 64 шестнадцатеричных символов.
	 *
	 * @param string $value Значение.
	 */
	public static function isValid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $value );
	}
}
