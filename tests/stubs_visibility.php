<?php
/**
 * Заглушки WordPress для тестов ручного показа записей на языках (админка и админ-панель).
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! function_exists( 'is_admin_bar_showing' ) ) {
	/**
	 * Показана ли админ-панель: по умолчанию да, тест выключает через `wp_mlp_test_wp()['admin_bar'] = false`.
	 */
	function is_admin_bar_showing(): bool {
		return (bool) ( wp_mlp_test_wp()['admin_bar'] ?? true );
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	/**
	 * Автосохранение: тест задаёт id через `wp_mlp_test_wp()['autosave'][ id ] = true`.
	 *
	 * @param int $postId Запись.
	 */
	function wp_is_post_autosave( $postId ) {
		return ! empty( wp_mlp_test_wp()['autosave'][ (int) $postId ] ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	/**
	 * Ревизия: тест задаёт id через `wp_mlp_test_wp()['revision'][ id ] = true`.
	 *
	 * @param int $postId Запись.
	 */
	function wp_is_post_revision( $postId ) {
		return ! empty( wp_mlp_test_wp()['revision'][ (int) $postId ] ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Скрытое поле с nonce (выводит и возвращает разметку).
	 *
	 * @param string $action  Действие.
	 * @param string $name    Имя поля.
	 * @param bool   $referer Поле referer (в тестах не нужно).
	 * @param bool   $echo    Выводить ли.
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ): string {
		unset( $referer );

		$html = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce( (string) $action ) . '">';

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $html;
	}
}
