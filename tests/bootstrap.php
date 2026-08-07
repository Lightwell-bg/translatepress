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

require_once __DIR__ . '/../vendor/autoload.php';
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
