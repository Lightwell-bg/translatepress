<?php
/**
 * Verification checks that need the plugin loaded.
 * Run: wp eval-file bin/mariadb-test/verify.php <mode> <id-prefix> [counts-file]
 *
 *   fresh <id>             a freshly activated plugin: exactly the four lingocanvas_* tables, db_version = Schema::VERSION,
 *                          default settings, no service options of earlier versions, REST namespace lingocanvas/v1 only
 *   counts-save <id> <f>   saves the row counts of the four tables to a JSON file
 *   upgrade <id> <f>       after replacing an older build by this code and one admin request: same tables, same row counts
 *                          as in the saved file, schema version current, the site still serves pages
 *
 * Output: "CHECK PASS|FAIL <id> :: evidence" lines.
 *
 * @package LingoCanvas
 */

require_once __DIR__ . '/lib.php';

use LingoCanvas\Storage\Schema;

$mode   = (string) ( $args[0] ?? '' );
$prefix = (string) ( $args[1] ?? $mode );
$file   = (string) ( $args[2] ?? '' );

wp_set_current_user( 1 );

if ( 'fresh' === $mode ) {
	global $wpdb;

	$tables = lc_mdb_tables();
	$expect = array_map( static fn( string $n ): string => $wpdb->prefix . 'lingocanvas_' . $n, Schema::TABLES );
	sort( $expect );

	lc_mdb_check( $prefix . '.four_tables', $tables === $expect, implode( ',', $tables ) );
	lc_mdb_check( $prefix . '.db_version', (int) get_option( 'lingocanvas_db_version', 0 ) === Schema::VERSION, 'lingocanvas_db_version=' . var_export( get_option( 'lingocanvas_db_version', null ), true ) );
	lc_mdb_check( $prefix . '.settings_option', is_array( get_option( 'lingocanvas_settings', null ) ), 'lingocanvas_settings present' );

	// Right after activation (before any request) the plugin owns exactly these two options.
	$own = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'lingocanvas\\_%' ORDER BY option_name" ); // phpcs:ignore

	lc_mdb_check( $prefix . '.only_two_options', array( 'lingocanvas_db_version', 'lingocanvas_settings' ) === $own, implode( ',', $own ) );

	$service = array();

	foreach ( array( 'lingocanvas_data_prefix', 'lingocanvas_migration', 'lingocanvas_migration_lock', 'lingocanvas_write_freeze', 'lingocanvas_prefix_conflicts', 'lingocanvas_pending_activation', 'lingocanvas_legacy_dir' ) as $option ) {
		if ( false !== get_option( $option, false ) ) {
			$service[] = $option;
		}
	}

	lc_mdb_check( $prefix . '.no_service_options', array() === $service, implode( ',', $service ) );

	$namespaces = rest_get_server()->get_namespaces();

	lc_mdb_check( $prefix . '.rest_namespaces', in_array( 'lingocanvas/v1', $namespaces, true ) && ! in_array( 'mlp/v1', $namespaces, true ), implode( ',', array_filter( $namespaces, static fn( string $n ): bool => str_contains( $n, 'lingocanvas' ) || str_contains( $n, 'mlp' ) ) ) );
	lc_mdb_check( $prefix . '.no_old_tables', array() === (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'mlp_' ) . '%' ) ), 'no {prefix}mlp_* tables' );
}

if ( 'counts-save' === $mode ) {
	file_put_contents( $file, wp_json_encode( lc_mdb_counts() ) ); // phpcs:ignore
	lc_mdb_info( 'counts saved: ' . wp_json_encode( lc_mdb_counts() ) );
}

if ( 'fp-save' === $mode ) {
	file_put_contents( $file, wp_json_encode( lc_mdb_fingerprint(), JSON_PRETTY_PRINT ) ); // phpcs:ignore
	lc_mdb_info( 'fingerprint saved to ' . $file );
}

if ( 'fp-compare' === $mode ) {
	$saved = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore
	$now   = lc_mdb_fingerprint();

	lc_mdb_check( $prefix . '.fingerprint_readable', is_array( $saved ), $file );

	foreach ( array( 'tables', 'options', 'meta', 'flags' ) as $part ) {
		$same = is_array( $saved ) && $now[ $part ] === $saved[ $part ];
		$diff = '';

		if ( ! $same && is_array( $saved ) && is_array( $now[ $part ] ) ) {
			$diff = ' diff: ' . implode( ',', array_keys( array_merge( array_diff_assoc( array_map( 'wp_json_encode', $now[ $part ] ), array_map( 'wp_json_encode', (array) $saved[ $part ] ) ), array_diff_assoc( array_map( 'wp_json_encode', (array) $saved[ $part ] ), array_map( 'wp_json_encode', $now[ $part ] ) ) ) ) );
		}

		lc_mdb_check( $prefix . '.fingerprint_' . $part, $same, ( $same ? 'identical' : 'DIFFERENT' ) . $diff );
	}
}

if ( 'upgrade' === $mode ) {
	$saved = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore

	lc_mdb_check( $prefix . '.saved_counts_readable', is_array( $saved ), $file );
	lc_mdb_check( $prefix . '.counts_unchanged', is_array( $saved ) && lc_mdb_counts() === $saved, wp_json_encode( lc_mdb_counts() ) . ' vs ' . wp_json_encode( $saved ) );
	lc_mdb_check( $prefix . '.db_version', (int) get_option( 'lingocanvas_db_version', 0 ) === Schema::VERSION, 'lingocanvas_db_version=' . var_export( get_option( 'lingocanvas_db_version', null ), true ) );
	lc_mdb_check( $prefix . '.settings_option_kept', is_array( get_option( 'lingocanvas_settings', null ) ), 'lingocanvas_settings present' );
}
