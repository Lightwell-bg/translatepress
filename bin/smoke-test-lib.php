<?php
/**
 * Shared helpers of the Playground smoke test (bin/smoke-test.sh). Required by
 * bin/smoke-test.php (scenario S1) and bin/smoke-test-s6.php (S6).
 * Not part of the plugin ZIP.
 *
 * State and results accumulate in /out/smoke.json (one /out dir per scenario).
 */

const MLP_SMOKE_FILE = '/out/smoke.json';

$GLOBALS['lingocanvas_smoke'] = is_readable( MLP_SMOKE_FILE )
	? json_decode( (string) file_get_contents( MLP_SMOKE_FILE ), true )
	: array(
		'checks'    => array(),
		'details'   => array(),
		'log_lines' => array(),
	);

/**
 * Records a check. A note starting with "SKIP:" turns a passing check into SKIP in the table.
 */
function lingocanvas_smoke_check( string $name, bool $ok, string $note = '' ): void {
	$GLOBALS['lingocanvas_smoke']['checks'][] = array(
		'name' => $name,
		'ok'   => $ok,
		'note' => $note,
	);
}

/**
 * Writes the result file (also from the shutdown handler, so a fatal still leaves a report).
 */
function lingocanvas_smoke_flush(): void {
	$log = WP_CONTENT_DIR . '/debug.log';
	$out = array();

	if ( is_readable( $log ) ) {
		copy( $log, '/out/debug.log' );

		foreach ( file( $log, FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
			if ( preg_match( '/lingocanvas|WordPress database error|Doing it wrong|Fatal|Warning|Deprecated|Notice|Cannot redeclare|already defined|Parse error/i', $line ) ) {
				$out[] = $line;
			}
		}
	}

	$GLOBALS['lingocanvas_smoke']['log_lines'] = $out;

	file_put_contents( MLP_SMOKE_FILE, wp_json_encode( $GLOBALS['lingocanvas_smoke'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}

register_shutdown_function(
	static function (): void {
		$e = error_get_last();

		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			lingocanvas_smoke_check( 'FATAL', false, $e['message'] . ' @ ' . $e['file'] . ':' . $e['line'] );
		}

		lingocanvas_smoke_flush();
	}
);

/**
 * Runs a step, turning exceptions into a failed check.
 */
function lingocanvas_smoke_step( string $name, callable $fn ): void {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		lingocanvas_smoke_check( $name, false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}

/**
 * Result of a simulated front-end request saved by smoke-test-render.php.
 *
 * @return array{status:int, location:string, content_type:string, body:string}|null
 */
function lingocanvas_smoke_page( string $name ): ?array {
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
 * Deprecation notices and "Doing it wrong" are returned too.
 *
 * @param list<string> $ignore_substrings Lines containing any of these are skipped (expected noise).
 * @return list<string>
 */
function lingocanvas_smoke_log_bad( array $ignore_substrings = array() ): array {
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
 * Names of the plugin tables ({prefix}lingocanvas_*) currently in the database.
 *
 * @return list<string>
 */
function lingocanvas_smoke_tables(): array {
	global $wpdb;

	$out    = array();
	$prefix = $wpdb->prefix . 'lingocanvas_';

	foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
		// Service copies ({prefix}lingocanvas_bak_*) are not part of the live set.
		if ( 0 === strpos( (string) $table, $prefix ) && 0 !== strpos( substr( (string) $table, strlen( $prefix ) ), 'bak_' ) ) {
			$out[] = (string) $table;
		}
	}

	sort( $out );

	return $out;
}
