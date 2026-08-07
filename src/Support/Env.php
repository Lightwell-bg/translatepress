<?php
/**
 * Чтение переменных окружения из .env.
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
	 * Загружен ли файл в этом запросе.
	 */
	private static bool $loaded = false;

	/**
	 * Значения, прочитанные из файла.
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
	 * Читает файл и запоминает значения.
	 *
	 * Значения, уже заданные на уровне сервера (реальный environment
	 * хостинга), не перезаписываются: серверная переменная приоритетнее файла.
	 *
	 * @param string $path Путь к файлу `.env`.
	 */
	public static function load( string $path ): void {
		if ( self::$loaded ) {
			return;
		}

		self::$loaded = true;

		if ( ! is_readable( $path ) ) {
			return;
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return;
		}

		foreach ( self::parse( $contents ) as $key => $value ) {
			if ( false !== getenv( $key ) ) {
				continue;
			}

			self::$values[ $key ] = $value;

			// Дублируем в окружение процесса, если хостинг это позволяет:
			// так значение увидит и сторонний код, читающий getenv() напрямую.
			if ( function_exists( 'putenv' ) ) {
				putenv( $key . '=' . $value );
			}
		}
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

		self::$loaded = false;
		self::$values = array();
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

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
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
			return substr( $value, 1, -1 );
		}

		return $value;
	}
}
