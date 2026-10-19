<?php
/**
 * Минимальные заглушки функций WordPress.
 *
 * Юнит-тесты не поднимают WordPress. Здесь объявлены только те функции,
 * которые вызывает тестируемая чистая логика: перевод строк, санитизация
 * и разбор URL. Поведение упрощённое, но совпадающее по смыслу.
 *
 * @package LingoCanvas
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

if ( ! function_exists( '_x' ) ) {
	/**
	 * @param string $text    Строка.
	 * @param string $context Контекст перевода.
	 * @param string $domain  Текстовый домен.
	 */
	function _x( string $text, string $context, string $domain = 'default' ): string { // phpcs:ignore
		unset( $context, $domain );

		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single Форма для 1.
	 * @param string $plural Форма для остальных чисел.
	 * @param int    $number Число.
	 * @param string $domain Текстовый домен.
	 */
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { // phpcs:ignore
		unset( $domain );

		return 1 === $number ? $single : $plural;
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
		$url = str_replace( ' ', '%20', trim( $url ) );
		// Как в ядре: недопустимые символы (`<`, `"`, `>` и т. п.) вырезаются, %XX и байты UTF-8 остаются.
		$url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url );

		if ( '' !== $url && '/' === $url[0] ) {
			return $url;
		}

		return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}

if ( ! function_exists( 'wp_allowed_protocols' ) ) {
	/**
	 * Список схем ядра по умолчанию.
	 *
	 * @return list<string>
	 */
	function wp_allowed_protocols(): array {
		return array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );
	}
}

if ( ! function_exists( 'wp_kses_bad_protocol' ) ) {
	/**
	 * Упрощённая копия ядра: срезает недопустимую схему в начале строки.
	 *
	 * @param string       $content           Адрес.
	 * @param list<string> $allowed_protocols Допустимые схемы.
	 */
	function wp_kses_bad_protocol( string $content, array $allowed_protocols ): string {
		$content = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content );

		if ( 1 === preg_match( '/^\s*([A-Za-z][A-Za-z0-9+.\-]*):/', $content, $m ) && ! in_array( strtolower( $m[1] ), $allowed_protocols, true ) ) {
			return (string) preg_replace( '/^\s*[A-Za-z][A-Za-z0-9+.\-]*:/', '', $content );
		}

		return $content;
	}
}

/**
 * Учёт вызовов do_action() — на время одного теста.
 *
 * @param string|null $tag   Имя хука для записи в журнал.
 * @param bool        $reset Если true, журнал очищается перед возвратом.
 * @return list<string>
 */
function lingocanvas_test_actions( ?string $tag = null, bool $reset = false ): array {
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

		lingocanvas_test_actions( $tag );
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

if ( ! function_exists( 'lingocanvas_test_filter' ) ) {
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
	function lingocanvas_test_filter( string $tag = '', ?callable $callback = null ): array {
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
		$filters = lingocanvas_test_filter();

		if ( ! isset( $filters[ $tag ] ) ) {
			return $value;
		}

		return $filters[ $tag ]( $value, ...$args );
	}
}

if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	/**
	 * Устаревший фильтр: как в WordPress, обработчик старого имени получает
	 * массив аргументов; предупреждение об устаревании в тестах не нужно.
	 *
	 * @param string       $tag         Старое имя фильтра.
	 * @param array<mixed> $args        Аргументы (первый — фильтруемое значение).
	 * @param string       $version     Версия, с которой имя устарело.
	 * @param string       $replacement Новое имя.
	 */
	function apply_filters_deprecated( string $tag, array $args, string $version, string $replacement = '' ) {
		unset( $version, $replacement );

		return apply_filters( $tag, ...$args );
	}
}

if ( ! function_exists( 'do_action_deprecated' ) ) {
	/**
	 * Устаревшее действие: делегирует обычному do_action заглушки.
	 *
	 * @param string       $tag         Старое имя действия.
	 * @param array<mixed> $args        Аргументы.
	 * @param string       $version     Версия, с которой имя устарело.
	 * @param string       $replacement Новое имя.
	 */
	function do_action_deprecated( string $tag, array $args, string $version, string $replacement = '' ): void {
		unset( $version, $replacement );

		do_action( $tag, ...$args );
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
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

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
function lingocanvas_test_options( ?array $reset = null, bool $keepAutoload = false ): array {
	static $options = array();

	if ( null !== $reset ) {
		$options = $reset;

		// Признаки автозагрузки живут рядом с опциями: сброс опций сбрасывает и их
		// (кроме записи одной опции изнутри заглушек и двойника базы).
		if ( ! $keepAutoload ) {
			$GLOBALS['lingocanvas_test_autoload'] = array();
		}
	}

	return $options;
}

/**
 * Приводит аргумент $autoload к значению столбца `autoload`.
 *
 * @param mixed $autoload true/false/'yes'/'no'/null.
 */
function lingocanvas_test_autoload_value( $autoload ): string {
	if ( false === $autoload || 'no' === $autoload || 'off' === $autoload ) {
		return 'no';
	}

	return 'yes';
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Ключ.
	 * @param mixed  $default Значение по умолчанию.
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		$options = lingocanvas_test_options();

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
function lingocanvas_test_request( ?array $reset = null ): array {
	static $context = array();

	if ( null !== $reset ) {
		$context = $reset;
	}

	return $context;
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) ( lingocanvas_test_request()['is_admin'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return (bool) ( lingocanvas_test_request()['ajax'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return (bool) ( lingocanvas_test_request()['cron'] ?? false );
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
		return (array) ( lingocanvas_test_request()['languages'] ?? array() );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name  Ключ.
	 * @param mixed  $value Значение.
	 */
	function update_option( string $name, $value, $autoload = null ): bool {
		$options          = lingocanvas_test_options();
		$options[ $name ] = $value;

		lingocanvas_test_options( $options, true );

		if ( null !== $autoload ) {
			$GLOBALS['lingocanvas_test_autoload'][ $name ] = lingocanvas_test_autoload_value( $autoload );
		}

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Как в WordPress: false, если опция уже существует.
	 *
	 * @param string $name  Ключ.
	 * @param mixed  $value Значение.
	 */
	function add_option( string $name, $value = '', string $deprecated = '', $autoload = 'yes' ): bool {
		$options = lingocanvas_test_options();

		if ( array_key_exists( $name, $options ) ) {
			return false;
		}

		$options[ $name ] = $value;

		lingocanvas_test_options( $options, true );

		$GLOBALS['lingocanvas_test_autoload'][ $name ] = lingocanvas_test_autoload_value( $autoload );

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $name Ключ.
	 */
	function delete_option( string $name ): bool {
		$options = lingocanvas_test_options();

		if ( ! array_key_exists( $name, $options ) ) {
			return false;
		}

		unset( $options[ $name ] );
		unset( $GLOBALS['lingocanvas_test_autoload'][ $name ] );

		lingocanvas_test_options( $options, true );

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
function lingocanvas_test_postmeta( ?array $reset = null ): array {
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
		$store = lingocanvas_test_postmeta();
		$value = $store[ $postId ][ $key ] ?? ( $single ? '' : array() );

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Каталог загрузок. Тест подменяет его через lingocanvas_test_uploads(),
	 * чтобы проверять поиск файла флага на настоящей файловой системе.
	 *
	 * @return array{basedir: string, baseurl: string}
	 */
	function wp_upload_dir(): array {
		$dir = lingocanvas_test_uploads();

		return array(
			'basedir' => $dir,
			'baseurl' => 'https://example.test/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'lingocanvas_test_uploads' ) ) {
	/**
	 * Каталог, который стаб wp_upload_dir() выдаёт за папку загрузок.
	 *
	 * @param string|null $set Новое значение, либо null — только прочитать.
	 */
	function lingocanvas_test_uploads( ?string $set = null ): string {
		static $dir = '';

		if ( null !== $set ) {
			$dir = $set;
		}

		return '' !== $dir ? $dir : sys_get_temp_dir() . '/lingocanvas-uploads';
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
		$store                    = lingocanvas_test_postmeta();
		$store[ $postId ][ $key ] = $value;

		lingocanvas_test_postmeta( $store );

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * @param int    $postId Идентификатор записи.
	 * @param string $key    Ключ метаполя.
	 */
	function delete_post_meta( int $postId, string $key ): bool {
		$store = lingocanvas_test_postmeta();
		unset( $store[ $postId ][ $key ] );

		lingocanvas_test_postmeta( $store );

		return true;
	}
}

if ( ! function_exists( 'update_meta_cache' ) ) {
	/**
	 * Прогрев метаполей. Считает вызовы, чтобы тест мог проверить «одним запросом».
	 *
	 * @param string    $type Тип объекта.
	 * @param list<int> $ids  Идентификаторы.
	 * @return array<int, mixed>
	 */
	function update_meta_cache( string $type, array $ids ): array {
		$GLOBALS['lingocanvas_test_meta_primed'][] = array( $type, $ids );

		return array();
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/**
	 * Все хуки считаются уже отработавшими, пока тест не скажет обратное
	 * через `lingocanvas_test_wp()['did_action'][ $tag ]`.
	 *
	 * @param string $tag Имя хука.
	 */
	function did_action( string $tag ): int {
		return (int) ( lingocanvas_test_wp()['did_action'][ $tag ] ?? 1 );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Результат задаёт тест: `lingocanvas_test_wp()['get_posts']` (список id или колбэк).
	 *
	 * @param array<string, mixed> $args Параметры.
	 * @return list<int>
	 */
	function get_posts( array $args = array() ): array {
		$result = lingocanvas_test_wp()['get_posts'] ?? array();

		return is_callable( $result ) ? (array) $result( $args ) : (array) $result;
	}
}

if ( ! function_exists( 'get_post_modified_time' ) ) {
	/**
	 * @param string $format Формат.
	 * @param bool   $gmt    В GMT.
	 * @param int    $post   Запись.
	 */
	function get_post_modified_time( string $format = 'U', bool $gmt = false, $post = null ) {
		unset( $format, $gmt, $post );

		return '2026-01-01T00:00:00+00:00';
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Совпадает с заглушкой wp_create_nonce(): верен только выданный ею ключ.
	 *
	 * @param string $nonce  Ключ.
	 * @param string $action Действие.
	 * @return int|false
	 */
	function wp_verify_nonce( string $nonce, string $action = '-1' ) {
		return wp_create_nonce( $action ) === $nonce ? 1 : false;
	}
}

if ( ! function_exists( 'is_404' ) ) {
	function is_404(): bool {
		return (bool) ( lingocanvas_test_wp()['is_404'] ?? false );
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	function is_feed(): bool {
		return (bool) ( lingocanvas_test_wp()['is_feed'] ?? false );
	}
}

if ( ! function_exists( 'is_preview' ) ) {
	function is_preview(): bool {
		return (bool) ( lingocanvas_test_wp()['is_preview'] ?? false );
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * Одиночная ли страница: тест задаёт `lingocanvas_test_wp()['is_singular']`.
	 */
	function is_singular(): bool {
		return (bool) ( lingocanvas_test_wp()['is_singular'] ?? false );
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/**
	 * Объект запроса: тест задаёт `lingocanvas_test_wp()['queried_object']` (WP_Post, WP_Term и т. п.).
	 *
	 * @return object|null
	 */
	function get_queried_object() {
		return lingocanvas_test_wp()['queried_object'] ?? null;
	}
}

if ( ! function_exists( 'is_search' ) ) {
	function is_search(): bool {
		return (bool) ( lingocanvas_test_wp()['is_search'] ?? false );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Запоминает маршруты: `$GLOBALS['lingocanvas_test_routes'][ namespace . route ] = args`.
	 *
	 * @param string               $namespace Пространство имён.
	 * @param string               $route     Маршрут.
	 * @param array<string, mixed> $args      Параметры.
	 */
	function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
		$GLOBALS['lingocanvas_test_routes'][ $namespace . $route ] = $args;

		return true;
	}
}

if ( ! function_exists( 'get_post_ancestors' ) ) {
	/**
	 * @param int $post Запись.
	 * @return list<int>
	 */
	function get_post_ancestors( $post ): array {
		unset( $post );

		return array();
	}
}

/*
 * Заглушки ядра WordPress для ссылок на редактор и REST-поиска страниц.
 * Всё состояние живёт в одном хранилище, которое тест заполняет сам.
 */

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Урезанная запись: только поля, которые читает плагин.
	 */
	class WP_Post { // phpcs:ignore
		public int $ID          = 0;
		public string $post_status = 'publish';
		public string $post_type   = 'post';
		public string $post_title  = '';
		public string $post_name   = '';
		public string $post_password = '';

		/**
		 * @param array<string, mixed> $data Поля записи.
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Запрос без БД: результат отдаёт колбэк `query` из хранилища теста.
	 */
	class WP_Query { // phpcs:ignore
		/** @var list<array<string, mixed>> */
		public static array $calls = array();

		/** @var list<WP_Post> */
		public array $posts = array();

		/**
		 * @param array<string, mixed> $args Параметры запроса.
		 */
		public function __construct( array $args = array() ) {
			self::$calls[] = $args;
			$callback      = lingocanvas_test_wp()['query'] ?? null;
			$this->posts   = is_callable( $callback ) ? (array) $callback( $args ) : array();
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request { // phpcs:ignore
		/**
		 * @param array<string, mixed> $params Параметры запроса.
		 */
		public function __construct( private array $params = array() ) {
		}

		/**
		 * @param string $key Имя параметра.
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { // phpcs:ignore
		/**
		 * @param mixed $data   Тело ответа.
		 * @param int   $status Код ответа.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

/**
 * Хранилище заглушек: `posts` (id => WP_Post), `permalinks` (id => адрес),
 * `types` (тип => [название, публичный]), `caps` (право => bool, по
 * умолчанию разрешено всё), `url_to_id` (адрес => id), `query` (колбэк WP_Query).
 *
 * @param array<string, mixed>|null $reset Если передан массив, хранилище им заменяется.
 * @return array<string, mixed>
 */
function lingocanvas_test_wp( ?array $reset = null ): array {
	static $store = array();

	if ( null !== $reset ) {
		$store = $reset;
	}

	return $store;
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @param int|WP_Post $post Запись или id.
	 */
	function get_post( $post ): ?WP_Post {
		if ( $post instanceof WP_Post ) {
			return $post;
		}

		return lingocanvas_test_wp()['posts'][ (int) $post ] ?? null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * @param int|WP_Post $post Запись или id.
	 */
	function get_permalink( $post ) {
		$post = get_post( $post );

		if ( null === $post ) {
			return false;
		}

		return lingocanvas_test_wp()['permalinks'][ $post->ID ] ?? 'https://example.test/' . $post->post_name . '/';
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * @param string $type Тип записи.
	 */
	function get_post_type_object( string $type ): ?object {
		$types = lingocanvas_test_wp()['types'] ?? array(
			'post'       => array( 'Запись', true ),
			'page'       => array( 'Страница', true ),
			'attachment' => array( 'Медиафайл', true ),
			'secret'     => array( 'Секрет', false ),
		);

		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}

		return (object) array(
			'public' => $types[ $type ][1],
			'labels' => (object) array( 'singular_name' => $types[ $type ][0] ),
		);
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * @param array<string, mixed> $args Фильтр (учитывается только `public`).
	 * @return list<string>
	 */
	function get_post_types( array $args = array() ): array {
		unset( $args );

		$types = lingocanvas_test_wp()['types'] ?? array(
			'post'       => array( 'Запись', true ),
			'page'       => array( 'Страница', true ),
			'attachment' => array( 'Медиафайл', true ),
			'secret'     => array( 'Секрет', false ),
		);

		return array_keys( array_filter( $types, static fn( array $type ): bool => $type[1] ) );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * @param WP_Post $post Запись.
	 */
	function get_the_title( $post ): string {
		return $post->post_title;
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * @param string $text  Строка.
	 * @param int    $flags Флаги.
	 */
	function wp_specialchars_decode( string $text, int $flags = ENT_NOQUOTES ): string {
		return htmlspecialchars_decode( $text, $flags );
	}
}

if ( ! function_exists( 'url_to_postid' ) ) {
	/**
	 * @param string $url Адрес.
	 */
	function url_to_postid( string $url ): int {
		return (int) ( lingocanvas_test_wp()['url_to_id'][ $url ] ?? 0 );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $cap Право.
	 */
	function current_user_can( string $cap, ...$args ): bool {
		$caps = lingocanvas_test_wp()['caps'] ?? array();

		// Право на конкретный объект: сначала ключ вида `edit_post:15`, затем общий.
		if ( isset( $args[0] ) && array_key_exists( $cap . ':' . $args[0], $caps ) ) {
			return (bool) $caps[ $cap . ':' . $args[0] ];
		}

		return (bool) ( $caps[ $cap ] ?? true );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path Путь внутри админки.
	 */
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	/**
	 * @param int    $id      Идентификатор записи.
	 * @param string $context Контекст.
	 */
	function get_edit_post_link( int $id, string $context = 'display' ): string {
		unset( $context );

		return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url Адрес.
	 */
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * @param string $text   Строка.
	 * @param string $domain Текстовый домен.
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text   Строка.
	 * @param string $domain Текстовый домен.
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return esc_html( $text );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		/**
		 * @param string $code    Код ошибки.
		 * @param string $message Сообщение.
		 * @param mixed  $data    Данные (для REST — `status`).
		 */
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

/**
 * Хранилище HTTP-заглушек: `response` (ответ wp_remote_get или колбэк),
 * `calls` (список [url, args]), `transients` (имя => значение), `transient_ttl`.
 *
 * @param array<string, mixed>|null $reset Если передан массив, хранилище им заменяется.
 * @return array<string, mixed>
 */
function lingocanvas_test_http( ?array $reset = null ): array {
	static $store = array(
		'calls'      => array(),
		'transients' => array(),
	);

	if ( null !== $reset ) {
		$store = array_merge(
			array(
				'calls'      => array(),
				'transients' => array(),
			),
			$reset
		);
	}

	return $store;
}

/**
 * Запись в хранилище HTTP-заглушек (статика недоступна по ссылке из вызывающего кода).
 *
 * @param string $key   Ключ.
 * @param mixed  $value Значение.
 */
function lingocanvas_test_http_set( string $key, $value ): void {
	$store         = lingocanvas_test_http();
	$store[ $key ] = $value;
	lingocanvas_test_http( $store );
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Значение.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param string               $url  Адрес.
	 * @param array<string, mixed> $args Параметры.
	 * @return mixed
	 */
	function wp_remote_get( string $url, array $args = array() ) {
		$calls   = lingocanvas_test_http()['calls'];
		$calls[] = array( $url, $args );
		lingocanvas_test_http_set( 'calls', $calls );

		$response = lingocanvas_test_http()['response'] ?? null;

		return is_callable( $response ) ? $response( $url, $args ) : $response;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param mixed $response Ответ.
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param mixed $response Ответ.
	 */
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $name Имя.
	 * @return mixed
	 */
	function get_transient( string $name ) {
		return lingocanvas_test_http()['transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $name  Имя.
	 * @param mixed  $value Значение.
	 * @param int    $ttl   Время жизни, секунд.
	 */
	function set_transient( string $name, $value, int $ttl = 0 ): bool {
		$transients          = lingocanvas_test_http()['transients'];
		$transients[ $name ] = $value;
		lingocanvas_test_http_set( 'transients', $transients );
		lingocanvas_test_http_set( 'transient_ttl', $ttl );

		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_filesystem_method' ) ) {
	/**
	 * Не «direct»: переносить папки в тестах не нужно.
	 */
	function get_filesystem_method(): string {
		return 'ftpext';
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem(): bool { // phpcs:ignore
		return false;
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Объектного кэша в юнит-тестах нет: удалять нечего.
	 *
	 * @param string $key   Ключ.
	 * @param string $group Группа.
	 */
	function wp_cache_delete( $key, $group = '' ): bool {
		unset( $key, $group );

		return true;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Создаёт каталог со всеми родителями (как ядро: true, если каталог уже есть).
	 *
	 * @param string $target Путь.
	 */
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * @param string $file Путь к файлу.
	 */
	function wp_delete_file( string $file ): void {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * В WordPress прерывает запрос; в тестах бросает исключение с текстом сообщения.
	 *
	 * @param string               $message Сообщение.
	 * @param string               $title   Заголовок.
	 * @param array<string, mixed> $args    Параметры (`response`).
	 */
	function wp_die( $message = '', $title = '', $args = array() ): void {
		unset( $title );

		throw new \RuntimeException( 'wp_die:' . (int) ( $args['response'] ?? 0 ) . ':' . (string) $message ); // phpcs:ignore
	}
}

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * @param int $bytes Байты.
	 */
	function size_format( $bytes ): string {
		return (string) $bytes . ' B';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Как ядро: теги и процентные октеты вырезаются, переводы строк остаются.
	 *
	 * @param string $text Строка.
	 */
	function sanitize_textarea_field( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/%[a-f0-9]{2}/i', '', $text );

		return trim( (string) preg_replace( '/[ \t]+/', ' ', $text ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Ключ.
	 */
	function sanitize_key( $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'map_deep' ) ) {
	/**
	 * @param mixed    $value    Значение или массив.
	 * @param callable $callback Функция для скаляров.
	 * @return mixed
	 */
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = map_deep( $item, $callback );
			}

			return $value;
		}

		return is_scalar( $value ) ? $callback( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Упрощённо, как ядро для латиницы: нижний регистр, всё лишнее в дефис, процентные октеты сохраняются.
	 *
	 * @param string $title Заголовок.
	 */
	function sanitize_title( $title ): string {
		$title = strtolower( strip_tags( (string) $title ) );
		$title = (string) preg_replace_callback( '/[^\x20-\x7e]/', static fn( array $m ): string => '%' . bin2hex( $m[0] ), $title );
		$title = (string) preg_replace( '/[^%a-z0-9_\-]+/', '-', $title );

		return trim( (string) preg_replace( '/-+/', '-', $title ), '-' );
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	/**
	 * Экран админки из `$GLOBALS['lingocanvas_test_screen']` (идентификатор) или null.
	 */
	function get_current_screen(): ?object {
		$id = $GLOBALS['lingocanvas_test_screen'] ?? null;

		return null === $id ? null : (object) array( 'id' => (string) $id );
	}
}

if ( ! function_exists( 'rest_get_server' ) ) {
	/**
	 * Сервер REST: объект с get_routes(), заданный тестом в `$GLOBALS['lingocanvas_test_rest_routes']`
	 * (маршрут => список обработчиков, как у WP_REST_Server::get_routes()).
	 */
	function rest_get_server(): object {
		return new class() {
			/**
			 * @param string $namespace Пространство имён.
			 * @return array<string, mixed>
			 */
			public function get_routes( string $namespace = '' ): array {
				$routes = (array) ( $GLOBALS['lingocanvas_test_rest_routes'] ?? array() );

				return array_filter(
					$routes,
					static fn( string $route ): bool => '' === $namespace || str_starts_with( ltrim( $route, '/' ), $namespace ),
					ARRAY_FILTER_USE_KEY
				);
			}
		};
	}
}
