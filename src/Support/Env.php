<?php
/**
 * Чтение переменных окружения из .env-файлов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Минимальный загрузчик `.env` без зависимости от composer-пакетов.
 *
 * Секреты (ключ OpenAI) по требованиям проекта хранятся только в `.env`,
 * никогда в БД, HTML, JS или логах. WordPress `.env` не читает сам, поэтому
 * нужен свой парсер — но он на 20 строк, тянуть vlucas/phpdotenv в рантайм
 * плагина ради этого не стоит.
 */
final class Env {

	/**
	 * Уже прочитанные файлы (путь => true): один и тот же файл читается однажды.
	 *
	 * @var array<string, true>
	 */
	private static array $loaded = array();

	/**
	 * Значения, прочитанные из файлов.
	 *
	 * Собственное хранилище, а не только `putenv()`: на многих shared-хостингах
	 * `putenv` отключён через `disable_functions`, и тогда `getenv()` вернул бы
	 * пустоту даже при полностью корректном `.env`. Из-за этого ключ считался
	 * бы ненастроенным, а кнопка перевода молча не появлялась.
	 *
	 * @var array<string, string>
	 */
	private static array $values = array();

	/**
	 * Из какого файла пришло каждое значение (имя переменной => путь к файлу).
	 *
	 * @var array<string, string>
	 */
	private static array $sources = array();

	/**
	 * Защитная первая строка PHP-файла с секретами.
	 */
	public const GUARD = '<?php exit; ?>';

	/**
	 * Защищаемые файлы, у которых защитной строки нет (пропущены).
	 *
	 * @var list<string>
	 */
	private static array $unguarded = array();

	/**
	 * Читает файл и запоминает значения.
	 *
	 * Можно вызывать несколько раз с разными файлами: побеждает тот файл, что
	 * загружен раньше, — уже заданное непустое значение не перезаписывается.
	 * Так же не перезаписываются значения, заданные на уровне сервера (реальный
	 * environment хостинга): серверная переменная приоритетнее любого файла.
	 * Пустые значения (`KEY=`) пропускаются, чтобы пустая строка в первом файле
	 * не скрывала настоящее значение из следующего.
	 *
	 * @param string $path Путь к файлу `.env`.
	 */
	public static function load( string $path ): void {
		if ( isset( self::$loaded[ $path ] ) ) {
			return;
		}

		self::$loaded[ $path ] = true;

		if ( ! is_readable( $path ) ) {
			return;
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return;
		}

		foreach ( self::parse( $contents ) as $key => $value ) {
			if ( '' === $value || '' !== self::get( $key ) ) {
				continue;
			}

			self::$values[ $key ]  = $value;
			self::$sources[ $key ] = $path;

			// Дублируем в окружение процесса, если хостинг это позволяет:
			// так значение увидит и сторонний код, читающий getenv() напрямую.
			if ( function_exists( 'putenv' ) ) {
				putenv( $key . '=' . $value );
			}
		}
	}

	/**
	 * Откуда взято значение переменной.
	 *
	 * @param string $key Имя переменной.
	 * @return string|null Путь к файлу, `'process'` для переменной окружения
	 *                     сервера или null, если значения нет.
	 */
	public static function sourceOf( string $key ): ?string {
		if ( isset( self::$sources[ $key ] ) ) {
			// Если кто-то подменил значение в окружении процесса (putenv из другого
			// плагина), get() вернёт уже его — источник должен это отражать.
			$current = getenv( $key );

			if ( is_string( $current ) && '' !== $current && ( self::$values[ $key ] ?? '' ) !== $current ) {
				return 'process';
			}

			return self::$sources[ $key ];
		}

		return '' !== self::get( $key ) ? 'process' : null;
	}

	/**
	 * Читает PHP-файл с защитной первой строкой (`<?php exit; ?>`).
	 *
	 * Файл внутри веб-корня (wp-content) доступен по прямой ссылке, поэтому его
	 * первая строка обязана прерывать выполнение: тогда запрос к файлу вернёт
	 * пустую страницу, а не секреты. Файл без такой строки не используется —
	 * он запоминается, чтобы страница настроек могла предупредить владельца.
	 *
	 * @param string $path Путь к файлу `wp-mlp.env.php`.
	 */
	public static function loadGuarded( string $path ): void {
		if ( isset( self::$loaded[ $path ] ) ) {
			return;
		}

		if ( ! is_readable( $path ) ) {
			self::$loaded[ $path ] = true;

			return;
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			self::$loaded[ $path ] = true;

			return;
		}

		$firstLine = preg_split( '/\R/', ltrim( $contents, "\xEF\xBB\xBF" ), 2 );

		if ( ! is_array( $firstLine ) || self::GUARD !== trim( $firstLine[0] ) ) {
			self::$loaded[ $path ] = true;
			self::$unguarded[]     = $path;

			return;
		}

		self::load( $path );
	}

	/**
	 * Файлы `wp-mlp.env.php`, пропущенные из-за отсутствия защитной строки.
	 *
	 * @return list<string>
	 */
	public static function unguardedFiles(): array {
		return self::$unguarded;
	}

	/**
	 * Значение переменной окружения.
	 *
	 * @param string $key     Имя переменной.
	 * @param string $default Значение по умолчанию.
	 */
	public static function get( string $key, string $default = '' ): string {
		// Реальное окружение сервера приоритетнее файла.
		$fromEnv = getenv( $key );

		if ( is_string( $fromEnv ) && '' !== $fromEnv ) {
			return $fromEnv;
		}

		$fromFile = self::$values[ $key ] ?? '';

		return '' !== $fromFile ? $fromFile : $default;
	}

	/**
	 * Сбрасывает состояние. Нужен только тестам.
	 *
	 * Убирает и то, что было записано в окружение процесса: иначе значения
	 * пережили бы «сброс» и протекли в следующий тест через getenv().
	 */
	public static function reset(): void {
		if ( function_exists( 'putenv' ) ) {
			foreach ( array_keys( self::$values ) as $key ) {
				putenv( (string) $key );
			}
		}

		self::$loaded  = array();
		self::$values  = array();
		self::$sources = array();
		self::$unguarded = array();
	}

	/**
	 * Разбирает содержимое `.env` в пары ключ-значение. Чистая функция.
	 *
	 * Поддерживает `KEY=value`, пустые строки, `# комментарии` и значения
	 * в кавычках. Никакой интерполяции переменных — она не нужна для
	 * плоского списка ключей этого плагина и добавляет риск инъекции.
	 *
	 * @param string $contents Содержимое файла.
	 * @return array<string, string>
	 */
	public static function parse( string $contents ): array {
		$values = array();

		foreach ( preg_split( '/\R/', $contents ) ?: array() as $line ) {
			$line = trim( $line );

			// Строки `<?php …` — защитная шапка файла wp-mlp.env.php, не данные.
			if ( '' === $line || str_starts_with( $line, '#' ) || str_starts_with( $line, '<?php' ) ) {
				continue;
			}

			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $line, 2 );

			$key = trim( $key );

			if ( '' === $key || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $key ) ) {
				continue;
			}

			$values[ $key ] = self::unquote( trim( $value ) );
		}

		return $values;
	}

	/**
	 * Снимает окружающие кавычки со значения.
	 *
	 * @param string $value Сырое значение после `=`.
	 */
	private static function unquote( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		$first = $value[0];
		$last  = $value[ strlen( $value ) - 1 ];

		if ( strlen( $value ) >= 2 && $first === $last && ( '"' === $first || "'" === $first ) ) {
			return trim( substr( $value, 1, -1 ) );
		}

		return trim( $value );
	}
}
