<?php
/**
 * Shared helpers of the Playground smoke test (bin/smoke-test.sh). Required by
 * bin/smoke-test.php (scenario S1) and bin/smoke-test-migration.php (S2-S5).
 * Not part of the plugin ZIP.
 *
 * State and results accumulate in /out/smoke.json (one /out dir per scenario).
 */

const MLP_SMOKE_FILE = '/out/smoke.json';

$GLOBALS['mlp_smoke'] = is_readable( MLP_SMOKE_FILE )
	? json_decode( (string) file_get_contents( MLP_SMOKE_FILE ), true )
	: array(
		'checks'    => array(),
		'details'   => array(),
		'log_lines' => array(),
	);

/**
 * Records a check. A note starting with "SKIP:" turns a passing check into SKIP in the table.
 */
function mlp_smoke_check( string $name, bool $ok, string $note = '' ): void {
	$GLOBALS['mlp_smoke']['checks'][] = array(
		'name' => $name,
		'ok'   => $ok,
		'note' => $note,
	);
}

/**
 * Writes the result file (also from the shutdown handler, so a fatal still leaves a report).
 */
function mlp_smoke_flush(): void {
	$log = WP_CONTENT_DIR . '/debug.log';
	$out = array();

	if ( is_readable( $log ) ) {
		copy( $log, '/out/debug.log' );

		foreach ( file( $log, FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
			if ( preg_match( '/lingocanvas|wp-mlp|mlp_|WordPress database error|Doing it wrong|Fatal|Warning|Deprecated|Notice|Cannot redeclare|already defined|Parse error/i', $line ) ) {
				$out[] = $line;
			}
		}
	}

	$GLOBALS['mlp_smoke']['log_lines'] = $out;

	file_put_contents( MLP_SMOKE_FILE, wp_json_encode( $GLOBALS['mlp_smoke'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}

register_shutdown_function(
	static function (): void {
		$e = error_get_last();

		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			mlp_smoke_check( 'FATAL', false, $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'] );
		}

		mlp_smoke_flush();
	}
);

/**
 * Runs a step, turning exceptions into a failed check.
 */
function mlp_smoke_step( string $name, callable $fn ): void {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		mlp_smoke_check( $name, false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}

/**
 * Result of a simulated front-end request saved by smoke-test-render.php.
 *
 * @return array{status:int, location:string, content_type:string, body:string}|null
 */
function mlp_smoke_page( string $name ): ?array {
	$meta = '/out/page-' . $name . '.json';
	$html = '/out/page-' . $name . '.html';

	if ( ! is_readable( $meta ) ) {
		return null;
	}

	$m = json_decode( (string) file_get_contents( $meta ), true );

	return array(
		'status'       => (int) ( $m['status'] ?? 0 ),
		'location'     => (string) ( $m['location'] ?? '' ),
		'content_type' => (string) ( $m['content_type'] ?? '' ),
		'body'         => is_readable( $html ) ? (string) file_get_contents( $html ) : '',
	);
}

/**
 * Lines of debug.log that signal a real problem (fatal, warning, notice, redeclare, DB error).
 * Deprecation notices and "Doing it wrong" are returned separately by mlp_smoke_log_info().
 *
 * @param list<string> $ignore_substrings Lines containing any of these are skipped (expected noise).
 * @return list<string>
 */
function mlp_smoke_log_bad( array $ignore_substrings = array() ): array {
	$log = WP_CONTENT_DIR . '/debug.log';
	$bad = array();

	if ( ! is_readable( $log ) ) {
		return $bad;
	}

	foreach ( file( $log, FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
		foreach ( $ignore_substrings as $skip ) {
			if ( false !== strpos( $line, $skip ) ) {
				continue 2;
			}
		}

		if ( preg_match( '/PHP (Fatal|Warning|Notice|Parse)|Fatal error|Warning:|Notice:|Cannot redeclare|already defined|WordPress database error|Doing it wrong|Deprecated/i', $line ) ) {
			$bad[] = $line;
		}
	}

	return $bad;
}

/**
 * All mlp_* table names currently in the database.
 *
 * @return list<string>
 */
function mlp_smoke_tables(): array {
	global $wpdb;

	$out = array();

	foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
		if ( 0 === strpos( (string) $table, $wpdb->prefix . 'mlp_' ) ) {
			$out[] = (string) $table;
		}
	}

	sort( $out );

	return $out;
}

/**
 * Row counts of all mlp_* tables plus a hash of the raw mlp_settings option value.
 *
 * @return array{counts:array<string,int>, settings_hash:string}
 */
function mlp_smoke_snapshot(): array {
	global $wpdb;

	$counts = array();

	foreach ( mlp_smoke_tables() as $table ) {
		// phpcs:ignore
		$counts[ $table ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	// phpcs:ignore
	$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mlp_settings' ) );

	return array(
		'counts'        => $counts,
		'settings_hash' => md5( $raw ),
	);
}
