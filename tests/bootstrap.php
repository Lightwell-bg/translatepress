<?php
/**
 * Bootstrap юнит-тестов.
 *
 * WordPress здесь НЕ загружается: тестируется только чистая логика
 * (нормализация строк, хеши, разбор URL, извлечение узлов из DOM).
 * Всё, что зависит от $wpdb и хуков, проверяется вручную на реальном сайте.
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
} else {
	// Composer не обязателен: рантайм-зависимостей у плагина нет, а PHPUnit
	// можно запускать и phar-архивом.
	spl_autoload_register(
		static function ( string $class ): void {
			foreach ( array( 'WpMlp\\Tests\\' => __DIR__, 'WpMlp\\' => __DIR__ . '/../src' ) as $prefix => $base ) {
				if ( ! str_starts_with( $class, $prefix ) ) {
					continue;
				}

				$path = $base . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}

				return;
			}
		}
	);
}

require_once __DIR__ . '/stubs.php';

if ( ! defined( 'WP_MLP_DIR' ) ) {
	define( 'WP_MLP_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Константы времени, на которые ссылаются константы классов.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
