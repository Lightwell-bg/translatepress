<?php
/**
 * Проверка протокола адреса, который попадёт в href/src.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Не даёт сохранить или вывести адрес с опасной схемой (`javascript:`,
 * `data:`, `vbscript:`): иначе переводчик мог бы положить в перевод ссылки
 * stored XSS, который сработает у каждого посетителя.
 *
 * Допустимы схемы из wp_allowed_protocols() (http, https, mailto, tel…),
 * а также относительные адреса, `//host`, `?query` и `#anchor`.
 */
final class UrlGuard {

	/** Атрибуты, значение которых — адрес. */
	private const URL_ATTRIBUTES = array( 'href', 'src' );

	/**
	 * Адресный ли это атрибут.
	 *
	 * @param string|null $attribute Имя атрибута.
	 */
	public static function isUrlAttribute( ?string $attribute ): bool {
		return null !== $attribute && in_array( strtolower( $attribute ), self::URL_ATTRIBUTES, true );
	}

	/**
	 * Похожа ли исходная строка на адрес (одно «слово» без пробелов).
	 *
	 * В таблице sources у ссылок и у `title`/`alt` один и тот же kind, а имя
	 * атрибута знают только места использования, поэтому на сохранении адрес
	 * опознаётся по исходнику: ссылки не содержат пробелов, подписи — как
	 * правило, содержат.
	 *
	 * @param string $sourceText Исходный текст строки.
	 */
	public static function looksLikeUrl( string $sourceText ): bool {
		$sourceText = trim( $sourceText );

		if ( '' === $sourceText || 1 === preg_match( '/\s/u', $sourceText ) ) {
			return false;
		}

		// Одно слово без признаков адреса («Company») адресом не считается, иначе перевод
		// подписи вроде «Company: Acme» отклонялся бы. Это ранняя проверка: авторитетная
		// защита — проверка при подстановке в Segment, где известно настоящее имя атрибута.
		return 1 === preg_match( '/^(?:[a-z][a-z0-9+.\-]*:|\/|#|\?|\.{1,2}\/|www\.)/i', $sourceText )
			|| str_contains( $sourceText, '/' );
	}

	/**
	 * Безопасен ли адрес по схеме. Пустая строка безопасна.
	 *
	 * @param string $value Значение href/src.
	 */
	public static function isSafe( string $value ): bool {
		// Браузер игнорирует управляющие символы и пробелы внутри схемы, а
		// также понимает числовые и именованные сущности вместо `:`.
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = (string) preg_replace( '/[\x00-\x20\x7F]+/u', '', $decoded );

		if ( '' === $decoded ) {
			return true;
		}

		if ( 1 !== preg_match( '/^([A-Za-z][A-Za-z0-9+.\-]*):/', $decoded, $matches ) ) {
			return true;
		}

		$allowed = function_exists( 'wp_allowed_protocols' )
			? wp_allowed_protocols()
			: array( 'http', 'https', 'mailto', 'tel' );

		return in_array( strtolower( $matches[1] ), array_map( 'strtolower', $allowed ), true );
	}
}
