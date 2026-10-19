<?php
/**
 * Shared helpers of the MariaDB harness: check reporting and a few direct database reads.
 *
 * Everything here reads the database directly (no plugin classes), so the same code works for any
 * plugin build. Not part of the plugin ZIP.
 *
 * @package LingoCanvas
 */

require_once __DIR__ . '/guard.php';

/** Table suffixes of the plugin. */
const LC_MDB_TABLES = array( 'sources', 'translations', 'occurrences', 'routes' );

/**
 * Prints one machine-readable check line: CHECK PASS|FAIL <id> :: <detail>.
 *
 * @param string $id     Check id, for example FRESH.tables.
 * @param bool   $ok     Result.
 * @param string $detail Short evidence.
 */
function lc_mdb_check( string $id, bool $ok, string $detail = '' ): bool {
	echo 'CHECK ' . ( $ok ? 'PASS' : 'FAIL' ) . ' ' . $id . ( '' !== $detail ? ' :: ' . $detail : '' ) . "\n"; // phpcs:ignore

	return $ok;
}

/**
 * Prints a plain informational line.
 *
 * @param string $line Text.
 */
function lc_mdb_info( string $line ): void {
	echo 'INFO ' . $line . "\n"; // phpcs:ignore
}

/**
 * Names of the existing plugin tables ({prefix}lingocanvas_*), without service copies (bak_*).
 *
 * @return list<string>
 */
function lc_mdb_tables(): array {
	global $wpdb;

	$prefix = $wpdb->prefix . 'lingocanvas_';
	$found  = array();

	foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
		if ( 0 === strpos( (string) $table, $prefix ) && 0 !== strpos( substr( (string) $table, strlen( $prefix ) ), 'bak_' ) ) {
			$found[] = (string) $table;
		}
	}

	sort( $found );

	return $found;
}

/**
 * Row counts of the plugin tables keyed by the short name (sources, translations, ...).
 *
 * @return array<string, int>
 */
function lc_mdb_counts(): array {
	global $wpdb;

	$counts = array();

	foreach ( LC_MDB_TABLES as $name ) {
		// phpcs:ignore
		$counts[ $name ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'lingocanvas_' . $name ) );
	}

	return $counts;
}

/**
 * Fingerprint of everything the plugin (and the old mlp_* copy) keeps in the database and in uploads.
 *
 * Per table: row count and an ordered row hash WITHOUT the last_seen_at column (it is allowed to move);
 * options by name with a value hash (transients, usage counters and the cache version excluded: they move on
 * every request); post meta _lingocanvas_*; the flag files.
 *
 * @return array<string, mixed>
 */
function lc_mdb_fingerprint(): array {
	global $wpdb;

	$fp = array(
		'tables'  => array(),
		'options' => array(),
		'meta'    => array(),
		'flags'   => array(),
	);

	$all = (array) $wpdb->get_col( 'SHOW TABLES' );

	foreach ( $all as $table ) {
		$table = (string) $table;

		if ( 0 !== strpos( $table, $wpdb->prefix . 'lingocanvas_' ) && 0 !== strpos( $table, $wpdb->prefix . 'mlp_' ) ) {
			continue;
		}

		// phpcs:disable
		$cols = array_values( array_filter( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ), static fn( $c ): bool => 'last_seen_at' !== $c ) );
		$pk   = (array) $wpdb->get_col( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), 4 );
		$ctx  = hash_init( 'sha256' );
		$n    = 0;
		$last = 0;
		$list = implode( ',', array_map( static fn( $c ): string => '`' . $c . '`', $cols ) );
		$ord  = implode( ',', array_map( static fn( $c ): string => '`' . $c . '`', $pk ) );

		// Keyset-free chunking by LIMIT/OFFSET: the data is static during the fingerprint.
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$list} FROM %i ORDER BY {$ord} LIMIT 5000 OFFSET %d", $table, $last ), ARRAY_N );
			foreach ( $rows as $row ) {
				hash_update( $ctx, implode( "\x1f", array_map( static fn( $v ): string => null === $v ? "\x00NULL" : (string) $v, $row ) ) . "\n" );
				++$n;
			}
			$last += 5000;
		} while ( count( $rows ) === 5000 );
		// phpcs:enable

		$fp['tables'][ substr( $table, strlen( $wpdb->prefix ) ) ] = array( 'rows' => $n, 'hash' => hash_final( $ctx ) );
	}

	ksort( $fp['tables'] );

	$names = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE 'lingocanvas\\_%' OR option_name LIKE 'mlp\\_%' ) ORDER BY option_name" ); // phpcs:ignore

	foreach ( $names as $name ) {
		if ( preg_match( '/_(usage_|cache_version)/', (string) $name ) || preg_match( '/^(lingocanvas|mlp)_usage_/', (string) $name ) ) {
			continue;
		}

		$fp['options'][ $name ] = md5( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ); // phpcs:ignore
	}

	$meta = (array) $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lingocanvas\\_%' OR meta_key LIKE '\\_mlp\\_%' ORDER BY meta_id", ARRAY_N ); // phpcs:ignore
	$fp['meta'] = array( 'rows' => count( $meta ), 'hash' => md5( wp_json_encode( $meta ) ) );

	$uploads = wp_get_upload_dir();

	foreach ( array( 'lingocanvas-flags', 'wp-mlp-flags' ) as $dir ) {
		foreach ( (array) glob( $uploads['basedir'] . '/' . $dir . '/*' ) as $file ) {
			$fp['flags'][ $dir . '/' . basename( $file ) ] = md5_file( $file );
		}
	}

	return $fp;
}
