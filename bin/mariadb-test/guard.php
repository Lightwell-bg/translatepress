<?php
/**
 * Safety guard of the MariaDB harness.
 *
 * Every script of bin/mariadb-test/ requires this file first. It stops the run unless WordPress
 * is wired to the dedicated local test MariaDB (127.0.0.1:3307) and to a database whose name
 * starts with "lc_". Run through WP-CLI: wp eval-file bin/mariadb-test/<script>.php
 *
 * Not part of the plugin ZIP.
 *
 * @package LingoCanvas
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI: wp eval-file bin/mariadb-test/<script>.php\n" ); // phpcs:ignore
	exit( 1 );
}

/**
 * Aborts the run with a message.
 *
 * @param string $message What is wrong.
 */
function lc_mdb_abort( string $message ): never {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( '[mariadb-test guard] ' . $message );
	}

	fwrite( STDERR, '[mariadb-test guard] ' . $message . "\n" ); // phpcs:ignore
	exit( 1 );
}

if ( ! defined( 'DB_HOST' ) || '127.0.0.1:3307' !== DB_HOST ) {
	lc_mdb_abort( 'DB_HOST must be exactly 127.0.0.1:3307 (the dedicated test MariaDB).' );
}

if ( ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^lc_[A-Za-z0-9_]+$/D', (string) DB_NAME ) ) {
	lc_mdb_abort( 'DB_NAME must start with "lc_" (a test database).' );
}

$lc_mdb_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

if ( '127.0.0.1' !== $lc_mdb_host && 'localhost' !== $lc_mdb_host ) {
	lc_mdb_abort( 'The site URL host must be 127.0.0.1 or localhost, got "' . $lc_mdb_host . '".' );
}

unset( $lc_mdb_host );
