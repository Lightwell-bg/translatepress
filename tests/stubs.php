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

if ( ! function_exists( 'user_trailingslashit' ) ) {
	/**
	 * @param string $value Строка.
	 */
	function user_trailingslashit( string $value ): string {
		return $value;
	}
}
