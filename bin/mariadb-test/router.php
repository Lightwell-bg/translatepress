<?php
/**
 * Router for the PHP built-in web server (php -S 127.0.0.1:PORT -t <wordpress dir> router.php).
 * Serves existing static files directly and sends every other request to WordPress index.php,
 * which is what Apache/nginx rewrite rules do. Local test sites only.
 *
 * @package LingoCanvas
 */

$lc_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore
$lc_file = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/\\' ) . $lc_path; // phpcs:ignore

// Existing files (static assets and real .php entry points such as wp-login.php) are served as they are.
if ( '/' !== $lc_path && is_file( $lc_file ) ) {
	return false;
}

// An existing directory with its own index.php (wp-admin/) is served natively too.
if ( is_dir( $lc_file ) && is_file( rtrim( $lc_file, '/\\' ) . '/index.php' ) && '/' !== $lc_path ) {
	return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/\\' ) . '/index.php'; // phpcs:ignore

require $_SERVER['SCRIPT_FILENAME']; // phpcs:ignore
