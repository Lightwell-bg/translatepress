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

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url Адрес.
	 */
	function esc_url_raw( string $url ): string {
		$url = trim( $url );

		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}

/**
 * Учёт вызовов do_action() — на время одного теста.
 *
 * @param string|null $tag   Имя хука для записи в журнал.
 * @param bool        $reset Если true, журнал очищается перед возвратом.
 * @return list<string>
 */
function wp_mlp_test_actions( ?string $tag = null, bool $reset = false ): array {
	static $fired = array();

	if ( $reset ) {
		$fired = array();
	}

	if ( null !== $tag ) {
		$fired[] = $tag;
	}

	return $fired;
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $tag  Имя хука.
	 * @param mixed  ...$args Аргументы хука.
	 */
	function do_action( string $tag, ...$args ): void {
		unset( $args );

		wp_mlp_test_actions( $tag );
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Без реальной системы хуков считаем, что ничего не подписано —
	 * тесты сами решают, вызывать ли соответствующие функции-заглушки.
	 *
	 * @param string $tag Имя хука.
	 */
	function has_action( string $tag ) {
		unset( $tag );

		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Без реальной системы хуков: тесты не регистрируют фильтры, поэтому
	 * значение просто возвращается без изменений.
	 *
	 * @param string $tag   Имя фильтра.
	 * @param mixed  $value Значение.
	 * @param mixed  ...$args Остальные аргументы фильтра.
	 */
	function apply_filters( string $tag, $value, ...$args ) {
		unset( $tag, $args );

		return $value;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Значение.
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
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

if ( ! function_exists( 'has_blocks' ) ) {
	/**
	 * Огрублённое, но достаточное для тестов приближение ядра: настоящая
	 * функция тоже, по сути, ищет комментарий-маркер блока в тексте.
	 *
	 * @param string $content `post_content`.
	 */
	function has_blocks( string $content ): bool {
		return false !== strpos( $content, '<!-- wp:' );
	}
}

if ( ! function_exists( 'wpautop' ) ) {
	/**
	 * Урезанная версия ядрового wpautop(): двойной перевод строки — новый
	 * абзац, одинарный — `<br />`. Настоящих edge-case'ов ядра (списки,
	 * таблицы, `<pre>` и т. п. внутри "голого" текста) не воспроизводит —
	 * для этого в реальных постах и так используются настоящие теги, а не
	 * голый текст, и до этой функции в PostContentExtractor такой HTML
	 * вообще не доходит (has_blocks() отсекает).
	 *
	 * @param string $text Исходный текст.
	 */
	function wpautop( string $text ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		$paragraphs = preg_split( '/\n\s*\n/', $text );
		$html       = array();

		foreach ( (array) $paragraphs as $paragraph ) {
			$paragraph = trim( (string) $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			// Уже блочная разметка — не заворачиваем её ещё раз в <p>.
			if ( 1 === preg_match( '#^<(h[1-6]|ul|ol|li|table|blockquote|figure|div|p)[ >]#i', $paragraph ) ) {
				$html[] = $paragraph;

				continue;
			}

			$html[] = '<p>' . nl2br( $paragraph ) . '</p>';
		}

		return implode( "\n", $html );
	}
}

/**
 * Хранилище postmeta в памяти — на время одного теста.
 *
 * @param array<int, array<string, mixed>>|null $reset Если передан массив, хранилище им заменяется.
 * @return array<int, array<string, mixed>>
 */
function wp_mlp_test_postmeta( ?array $reset = null ): array {
	static $meta = array();

	if ( null !== $reset ) {
		$meta = $reset;
	}

	return $meta;
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $postId Идентификатор записи.
	 * @param string $key    Ключ метаполя.
	 * @param bool   $single Вернуть одно значение вместо списка.
	 * @return mixed
	 */
	function get_post_meta( int $postId, string $key = '', bool $single = false ) {
		$store = wp_mlp_test_postmeta();
		$value = $store[ $postId ][ $key ] ?? ( $single ? '' : array() );

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * @param int    $postId Идентификатор записи.
	 * @param string $key    Ключ метаполя.
	 * @param mixed  $value  Значение.
	 */
	function update_post_meta( int $postId, string $key, $value ): bool {
		$store                    = wp_mlp_test_postmeta();
		$store[ $postId ][ $key ] = $value;

		wp_mlp_test_postmeta( $store );

		return true;
	}
}
