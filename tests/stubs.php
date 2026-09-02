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
	 * Ядро сначала вырезает `<script>`/`<style>` целиком, вместе с
	 * содержимым, и только потом снимает остальные теги — просто
	 * strip_tags() этого не делает (оставляет текст внутри script/style).
	 *
	 * @param string $text Строка.
	 */
	function wp_strip_all_tags( string $text ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

		return trim( strip_tags( $text ) );
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

if ( ! function_exists( 'wp_mlp_test_filter' ) ) {
	/**
	 * Подменяет обработчик фильтра на время теста.
	 *
	 * Полноценной системы хуков здесь нет и не нужно: фильтров у плагина
	 * единицы, и проверять требуется ровно одно — что значение проходит
	 * через них и его можно заменить.
	 *
	 * @param string        $tag      Имя фильтра.
	 * @param callable|null $callback Обработчик, либо null — снять.
	 * @return array<string, callable>
	 */
	function wp_mlp_test_filter( string $tag = '', ?callable $callback = null ): array {
		static $filters = array();

		if ( '' === $tag ) {
			return $filters;
		}

		if ( null === $callback ) {
			unset( $filters[ $tag ] );
		} else {
			$filters[ $tag ] = $callback;
		}

		return $filters;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Прогоняет значение через обработчик, если тест его подменил.
	 *
	 * @param string $tag   Имя фильтра.
	 * @param mixed  $value Значение.
	 * @param mixed  ...$args Остальные аргументы фильтра.
	 */
	function apply_filters( string $tag, $value, ...$args ) {
		$filters = wp_mlp_test_filter();

		if ( ! isset( $filters[ $tag ] ) ) {
			return $value;
		}

		return $filters[ $tag ]( $value, ...$args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Заглушка регистрации хука: тесты вызывают обработчики напрямую, а
	 * не через диспетчер WordPress, поэтому регистрацию достаточно принять
	 * и забыть. Возвращаемое значение совпадает с настоящим.
	 *
	 * @param string $tag      Имя хука.
	 * @param mixed  $callback Обработчик.
	 * @param int    $priority Приоритет.
	 * @param int    $args     Число аргументов.
	 */
	function add_action( string $tag, $callback, int $priority = 10, int $args = 1 ): bool {
		unset( $tag, $callback, $priority, $args );

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string $tag      Имя фильтра.
	 * @param mixed  $callback Обработчик.
	 * @param int    $priority Приоритет.
	 * @param int    $args     Число аргументов.
	 */
	function add_filter( string $tag, $callback, int $priority = 10, int $args = 1 ): bool {
		unset( $tag, $callback, $priority, $args );

		return true;
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

/**
 * Тип текущего запроса — на время одного теста.
 *
 * Константы REST_REQUEST/XMLRPC_REQUEST/WP_CLI сюда не входят намеренно:
 * определённую константу нельзя вернуть обратно в пределах процесса, а
 * запускать ради трёх однострочных проверок отдельный процесс дороже, чем
 * они стоят. Проверяются функции, которые действительно можно подменить.
 *
 * @param array<string, bool>|null $reset Если передан массив, контекст им заменяется.
 * @return array<string, bool>
 */
function wp_mlp_test_request( ?array $reset = null ): array {
	static $context = array();

	if ( null !== $reset ) {
		$context = $reset;
	}

	return $context;
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) ( wp_mlp_test_request()['is_admin'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return (bool) ( wp_mlp_test_request()['ajax'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return (bool) ( wp_mlp_test_request()['cron'] ?? false );
	}
}

if ( ! function_exists( 'get_available_languages' ) ) {
	/**
	 * Установленные языковые пакеты. Настоящая функция сканирует
	 * `wp-content/languages`; в тестах список задаётся контекстом запроса.
	 *
	 * @return list<string>
	 */
	function get_available_languages(): array {
		return (array) ( wp_mlp_test_request()['languages'] ?? array() );
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

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Каталог загрузок. Тест подменяет его через wp_mlp_test_uploads(),
	 * чтобы проверять поиск файла флага на настоящей файловой системе.
	 *
	 * @return array{basedir: string, baseurl: string}
	 */
	function wp_upload_dir(): array {
		$dir = wp_mlp_test_uploads();

		return array(
			'basedir' => $dir,
			'baseurl' => 'https://example.test/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'wp_mlp_test_uploads' ) ) {
	/**
	 * Каталог, который стаб wp_upload_dir() выдаёт за папку загрузок.
	 *
	 * @param string|null $set Новое значение, либо null — только прочитать.
	 */
	function wp_mlp_test_uploads( ?string $set = null ): string {
		static $dir = '';

		if ( null !== $set ) {
			$dir = $set;
		}

		return '' !== $dir ? $dir : sys_get_temp_dir() . '/wp-mlp-uploads';
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Одноразовый ключ. Без WordPress проверять нечего, поэтому значение
	 * предсказуемое: тестам важно, что оно попало в адрес, а не какое оно.
	 *
	 * @param string $action Имя действия.
	 */
	function wp_create_nonce( string $action = '-1' ): string {
		return 'nonce-' . md5( $action );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Добавляет параметры к адресу. Упрощённая версия: покрывает только ту
	 * форму вызова, что использует плагин, — массив параметров и адрес.
	 *
	 * @param array<string, string|int> $args Параметры.
	 * @param string                    $url  Исходный адрес.
	 */
	function add_query_arg( array $args, string $url ): string {
		$parts = explode( '#', $url, 2 );
		$base  = $parts[0];
		$hash  = isset( $parts[1] ) ? '#' . $parts[1] : '';

		foreach ( $args as $key => $value ) {
			$base .= ( str_contains( $base, '?' ) ? '&' : '?' )
				. rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return $base . $hash;
	}
}

if ( ! function_exists( '_prime_post_caches' ) ) {
	/**
	 * Прогрев кэша записей. Без WordPress кэшировать нечего, но функция
	 * должна существовать: Sitemap зовёт её перед обходом списка id.
	 *
	 * @param list<int> $ids      Идентификаторы записей.
	 * @param bool      $terms    Прогреть таксономии.
	 * @param bool      $metaData Прогреть метаполя.
	 */
	function _prime_post_caches( array $ids, bool $terms = true, bool $metaData = true ): void {
		unset( $ids, $terms, $metaData );
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
