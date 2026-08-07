<?php
/**
 * Минимальные заглушки функций WordPress.
 *
 * Юнит-тесты не поднимают WordPress. Здесь объявлены только те функции,
 * которые вызывает тестируемая чистая логика: перевод строк, санитизация
 * и разбор URL. Поведение упрощённое, но совпадающее по смыслу.
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Строка.
	 * @param string $domain Текстовый домен.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Строка.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Строка.
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $text Строка.
	 */
	function sanitize_text_field( string $text ): string {
		$text = wp_strip_all_tags( $text );

		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Строка.
	 */
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Значение.
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data  Значение.
	 * @param int   $flags Флаги json_encode().
	 */
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Грубое приближение wp_kses: оставляет разрешённые теги, режет остальные.
	 *
	 * Настоящая фильтрация атрибутов — забота WordPress, и подменять её здесь
	 * бессмысленно: тесты проверяют логику плагина вокруг неё, а не саму kses.
	 *
	 * @param string                            $html    Разметка.
	 * @param array<string, array<string, bool>> $allowed Разрешённые теги.
	 */
	function wp_kses( string $html, array $allowed ): string {
		$tags = '';

		foreach ( array_keys( $allowed ) as $tag ) {
			$tags .= '<' . $tag . '>';
		}

		return strip_tags( $html, $tags );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       Адрес.
	 * @param int    $component Компонент PHP_URL_*.
	 * @return array<string, mixed>|string|int|false|null
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $value Строка.
	 */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $value Строка.
	 */
	function trailingslashit( string $value ): string {
		return untrailingslashit( $value ) . '/';
	}
}

/**
 * Хранилище опций в памяти — на время одного теста.
 *
 * @param array<string, mixed>|null $reset Если передан массив, хранилище им заменяется.
 * @return array<string, mixed>
 */
function wp_mlp_test_options( ?array $reset = null ): array {
	static $options = array();

	if ( null !== $reset ) {
		$options = $reset;
	}

	return $options;
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Ключ.
	 * @param mixed  $default Значение по умолчанию.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		$options = wp_mlp_test_options();

		return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name  Ключ.
	 * @param mixed  $value Значение.
	 */
	function update_option( string $name, $value ): bool {
		$options          = wp_mlp_test_options();
		$options[ $name ] = $value;

		wp_mlp_test_options( $options );

		return true;
	}
}

if ( ! function_exists( 'user_trailingslashit' ) ) {
	/**
	 * @param string $value Строка.
	 */
	function user_trailingslashit( string $value ): string {
		return $value;
	}
}
