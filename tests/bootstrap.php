<?php
/**
 * Bootstrap юнит-тестов.
 *
 * WordPress здесь НЕ загружается: тестируется только чистая логика
 * (нормализация строк, хеши, разбор URL, извлечение узлов из DOM).
 * Всё, что зависит от $wpdb и хуков, проверяется вручную на реальном сайте.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

if ( is_readable( __DIR__ . '/../vendor/autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/autoload.php';
} else {
	// Composer не обязателен: рантайм-зависимостей у плагина нет, а PHPUnit
	// можно запускать и phar-архивом.
	spl_autoload_register(
		static function ( string $class ): void {
			foreach ( array( 'LingoCanvas\\Tests\\' => __DIR__, 'LingoCanvas\\' => __DIR__ . '/../src' ) as $prefix => $base ) {
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
require_once __DIR__ . '/stubs_visibility.php';

if ( ! defined( 'LINGOCANVAS_DIR' ) ) {
	define( 'LINGOCANVAS_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'LINGOCANVAS_URL' ) ) {
	define( 'LINGOCANVAS_URL', 'https://example.test/wp-content/plugins/lingocanvas/' );
}

if ( ! defined( 'LINGOCANVAS_VERSION' ) ) {
	define( 'LINGOCANVAS_VERSION', '0.3.0' );
}

// Константы времени, на которые ссылаются константы классов.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
