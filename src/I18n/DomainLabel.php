<?php
/**
 * Человекочитаемое имя text domain.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

/**
 * Превращает text domain в то, что понятно владельцу сайта.
 *
 * `woocommerce`, `twentytwentyfour`, `default` — это имена для
 * разработчика. Человек, который переводит интерфейс, должен видеть
 * «Плагин: WooCommerce» и «WordPress (ядро)», иначе список строк
 * невозможно осмысленно фильтровать: непонятно, что именно ты правишь и
 * где это вылезет на сайте.
 *
 * Класс намеренно не знает о WordPress: карты «домен → название» собирает
 * вызывающий код из `wp_get_theme()` и `get_plugins()`, а сюда они
 * приходят готовыми. Так правило проверяется тестом без установленного
 * WordPress, как и всё остальное в этом проекте.
 */
final class DomainLabel {

	/**
	 * Домен строк самого WordPress.
	 */
	public const CORE = 'default';

	/**
	 * @param string                $domain  Text domain из gettext-вызова.
	 * @param array<string, string> $themes  Домен => название темы.
	 * @param array<string, string> $plugins Домен => название плагина.
	 */
	public static function format( string $domain, array $themes = array(), array $plugins = array() ): string {
		if ( '' === $domain || self::CORE === $domain ) {
			return __( 'WordPress (ядро)', 'wp-mlp' );
		}

		if ( isset( $themes[ $domain ] ) && '' !== $themes[ $domain ] ) {
			/* translators: %s: theme name */
			return sprintf( __( 'Тема: %s', 'wp-mlp' ), $themes[ $domain ] );
		}

		if ( isset( $plugins[ $domain ] ) && '' !== $plugins[ $domain ] ) {
			/* translators: %s: plugin name */
			return sprintf( __( 'Плагин: %s', 'wp-mlp' ), $plugins[ $domain ] );
		}

		/*
		 * Домен, который не удалось сопоставить ни с темой, ни с плагином:
		 * плагин мог быть отключён или удалён, а его строки остались в
		 * словаре. Показываем сам домен — это всё ещё полезнее, чем пустая
		 * ячейка, и сразу объясняет, почему строку больше не видно на сайте.
		 */
		return $domain;
	}
}
