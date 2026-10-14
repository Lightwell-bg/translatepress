<?php
/**
 * Smoke-test body, executed inside WordPress Playground by bin/smoke-test-blueprint.json
 * (see bin/smoke-test.sh). Not part of the plugin ZIP.
 *
 * The blueprint runs several runPHP steps (every one is a fresh PHP request):
 *   setup  -> tables, post, settings
 *   render -> bin/smoke-test-render.php simulates a front-end request, saves /out/page-<name>.html
 *   check1 -> strings collected, REST GET routes, manual translation, unsafe href rejection
 *   check2 -> translation rendered, %i repository queries, ru_RU, uninstall, debug.log
 * State and results accumulate in /out/smoke.json.
 *
 * Playground runs WordPress on SQLite (not MySQL): the SQL goes through the SQLite
 * integration layer, so a DB error here can also come from that layer.
 */

use WpMlp\Plugin;
use WpMlp\Storage\GettextRepository;
use WpMlp\Storage\OccurrenceObjectTypeMigration;
use WpMlp\Storage\Schema;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\StaleStringsCleanup;

const MLP_SMOKE_FILE = '/out/smoke.json';

$GLOBALS['mlp_smoke'] = is_readable( MLP_SMOKE_FILE )
	? json_decode( (string) file_get_contents( MLP_SMOKE_FILE ), true )
	: array(
		'checks'    => array(),
		'details'   => array(),
		'log_lines' => array(),
	);

/**
 * Records a check.
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
			if ( preg_match( '/lingocanvas|wp-mlp|mlp_|WordPress database error|Doing it wrong|Fatal|Warning|Deprecated|Notice/i', $line ) ) {
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
 * @return array{status:int, location:string, body:string}|null
 */
function mlp_smoke_page( string $name ): ?array {
	$meta = '/out/page-' . $name . '.json';
	$html = '/out/page-' . $name . '.html';

	if ( ! is_readable( $meta ) ) {
		return null;
	}

	$m = json_decode( (string) file_get_contents( $meta ), true );

	return array(
		'status'   => (int) ( $m['status'] ?? 0 ),
		'location' => (string) ( $m['location'] ?? '' ),
		'body'     => is_readable( $html ) ? (string) file_get_contents( $html ) : '',
	);
}

global $wpdb;
wp_set_current_user( 1 );

$phase = $GLOBALS['MLP_SMOKE_PHASE'] ?? '';
$slug  = 'smoke-post';

// ===== setup ===============================================================
if ( 'setup' === $phase ) {
	@unlink( MLP_SMOKE_FILE ); // phpcs:ignore
	$GLOBALS['mlp_smoke'] = array(
		'checks'    => array(),
		'details'   => array(),
		'log_lines' => array(),
	);

	mlp_smoke_step(
		'a_tables',
		static function () use ( $wpdb ): void {
			$missing = array();

			foreach ( Schema::TABLES as $name ) {
				$t = Schema::table( $name );

				if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
					$missing[] = $t;
				}
			}

			mlp_smoke_check( 'a_tables_exist_after_activation', array() === $missing, $missing ? 'missing: ' . implode( ',', $missing ) : implode( ',', Schema::TABLES ) );
			mlp_smoke_check( 'a_db_version_option', (int) get_option( 'mlp_db_version', 0 ) === Schema::VERSION );
		}
	);

	mlp_smoke_step(
		'b_post',
		static function () use ( $slug ): void {
			$about = wp_insert_post(
				array(
					'post_title'   => 'Smoke about',
					'post_name'    => 'about-smoke',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '<p>About page body.</p>',
				)
			);

			$post_id = (int) wp_insert_post(
				array(
					'post_title'   => 'Smoke post',
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_content' => '<!-- wp:paragraph --><p>Smoke paragraph hello world. <a href="/about-smoke/">About link text</a></p><!-- /wp:paragraph -->',
				)
			);

			mlp_smoke_check( 'b_post_created', $post_id > 0 && $about > 0, 'post_id=' . $post_id );
			$GLOBALS['mlp_smoke']['details']['post_id'] = $post_id;

			// Collect strings from anonymous visitors too, so a front-end render fills the dictionary.
			$s                            = (array) get_option( 'mlp_settings', array() );
			$s['discover_from_visitors']  = true;
			$s['untranslated_posts_mode'] = 'all';
			update_option( 'mlp_settings', $s );
			flush_rewrite_rules();

			// The render steps need the site host, or WordPress canonical-redirects to it.
			file_put_contents( '/out/site-host.txt', (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
		}
	);
}

// ===== check1 ==============================================================
if ( 'check1' === $phase ) {
	$post_id = (int) ( $GLOBALS['mlp_smoke']['details']['post_id'] ?? 0 );

	// (c) front-end renders.
	mlp_smoke_step(
		'c_frontend',
		static function () use ( $slug ): void {
			$expect = array(
				'default_front' => 'hreflang tags',
				'default_post'  => 'hreflang tags',
				'en_front'      => 'hreflang tags',
				'en_post'       => 'hreflang tags',
			);

			foreach ( $expect as $name => $_ ) {
				$r = mlp_smoke_page( $name );

				if ( null === $r ) {
					mlp_smoke_check( 'c_' . $name, false, 'render produced no result' );
					continue;
				}

				$hreflang = (int) preg_match_all( '/<link[^>]+hreflang=/i', $r['body'] );
				$has_html = str_contains( $r['body'], '</html>' );
				$ok       = 200 === $r['status'] && $has_html && $hreflang >= 2;
				mlp_smoke_check( 'c_' . $name, $ok, 'status=' . $r['status'] . ' bytes=' . strlen( $r['body'] ) . ' hreflang_tags=' . $hreflang . ( $r['location'] ? ' location=' . $r['location'] : '' ) );
			}
		}
	);

	// Collected strings.
	mlp_smoke_step(
		'c_collected',
		static function () use ( $wpdb ): void {
			$t = Schema::table( 'sources' );
			// phpcs:ignore
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, kind, source_text FROM %i', $t ), ARRAY_A );
			$text = 0;
			$href = 0;
			$kinds = array();

			foreach ( $rows as $row ) {
				$kinds[ $row['kind'] ] = ( $kinds[ $row['kind'] ] ?? 0 ) + 1;

				if ( false !== stripos( $row['source_text'], 'smoke' ) ) {
					$GLOBALS['mlp_smoke']['details']['smoke_sources'][] = $row;
				}

				if ( str_contains( $row['source_text'], 'Smoke paragraph hello world' ) ) {
					$text = (int) $row['id'];
				}
				if ( 'attribute' === $row['kind'] && str_contains( $row['source_text'], '/about-smoke/' ) ) {
					$href = (int) $row['id'];
				}
			}

			$GLOBALS['mlp_smoke']['details']['src_text'] = $text;
			$GLOBALS['mlp_smoke']['details']['src_href'] = $href;
			$GLOBALS['mlp_smoke']['details']['kinds']    = $kinds;
			mlp_smoke_check( 'c_strings_collected', $text > 0 && $href > 0, 'sources=' . count( $rows ) . ' text_id=' . $text . ' href_id=' . $href . ' kinds=' . wp_json_encode( $kinds ) );
		}
	);

	$src_text = (int) ( $GLOBALS['mlp_smoke']['details']['src_text'] ?? 0 );
	$src_href = (int) ( $GLOBALS['mlp_smoke']['details']['src_href'] ?? 0 );

	// (d) REST GET routes.
	mlp_smoke_step(
		'd_rest',
		static function () use ( $post_id, $src_text ): void {
			$routes  = rest_get_server()->get_routes();
			$results = array();

			foreach ( $routes as $route => $handlers ) {
				if ( 0 !== strpos( $route, '/mlp/v1' ) ) {
					continue;
				}

				$has_get = false;

				foreach ( $handlers as $h ) {
					if ( ! empty( $h['methods']['GET'] ) ) {
						$has_get = true;
					}
				}

				if ( ! $has_get ) {
					continue;
				}

				$url = preg_replace_callback(
					'/\(\?P<(\w+)>[^)]+\)/',
					static function ( array $m ) use ( $post_id, $src_text ): string {
						switch ( $m[1] ) {
							case 'post_id':
								return (string) $post_id;
							case 'source_id':
								return (string) max( 1, $src_text );
							case 'locale':
								return 'en';
							default:
								return '1';
						}
					},
					$route
				);

				$res = rest_do_request( new WP_REST_Request( 'GET', $url ) );
				$err = $res->is_error() ? $res->as_error()->get_error_code() : null;

				$results[ $route ] = array(
					'url'    => $url,
					'status' => $res->get_status(),
					'error'  => $err,
				);
				mlp_smoke_check( 'd_rest_GET ' . $url, $res->get_status() < 500, 'status=' . $res->get_status() . ( $err ? ' code=' . $err : '' ) );
			}

			$GLOBALS['mlp_smoke']['details']['rest_get'] = $results;
			mlp_smoke_check( 'd_rest_routes_registered', count( $results ) > 0, count( $results ) . ' GET routes' );
		}
	);

	// (e) manual translation.
	mlp_smoke_step(
		'e_translation',
		static function () use ( $src_text, $src_href ): void {
			if ( $src_text <= 0 ) {
				mlp_smoke_check( 'e_save_translation', false, 'no collected text source to translate' );

				return;
			}

			$req = new WP_REST_Request( 'PUT', '/mlp/v1/translations/' . $src_text . '/en' );
			$req->set_param( 'translated_text', 'Smoke paragraph translated XYZ.' );
			$res = rest_do_request( $req );
			mlp_smoke_check( 'e_save_translation', 200 === $res->get_status(), 'status=' . $res->get_status() );

			if ( $src_href <= 0 ) {
				mlp_smoke_check( 'e_unsafe_href_rejected_400', false, 'no collected href source found' );

				return;
			}

			$req = new WP_REST_Request( 'PUT', '/mlp/v1/translations/' . $src_href . '/en' );
			$req->set_param( 'translated_text', 'javascript:alert(1)' );
			$res = rest_do_request( $req );
			mlp_smoke_check( 'e_unsafe_href_rejected_400', 400 === $res->get_status(), 'status=' . $res->get_status() );
			// phpcs:ignore
			$GLOBALS['mlp_smoke']['details']['translations_rows'] = $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT * FROM %i', Schema::table( 'translations' ) ), ARRAY_A );
			$GLOBALS['mlp_smoke']['details']['cache_version']      = get_option( 'mlp_cache_version' );

			$req = new WP_REST_Request( 'PUT', '/mlp/v1/translations/' . $src_href . '/en' );
			$req->set_param( 'translated_text', '/en/about-smoke/' );
			$res = rest_do_request( $req );
			mlp_smoke_check( 'e_safe_href_accepted', 200 === $res->get_status(), 'status=' . $res->get_status() );
		}
	);
}

// ===== check2 ==============================================================
if ( 'check2' === $phase ) {
	// Lookup of the translated source and its rendering. Playground runs WordPress on SQLite,
	// whose compatibility layer cannot compare a BINARY column with UNHEX(): the plugin's
	// lookup then finds nothing although the HEX() form works. That is a limitation of the
	// environment (MySQL/MariaDB is fine), so the render check is reported as SKIP in that case.
	mlp_smoke_step(
		'e_lookup_and_render',
		static function () use ( $wpdb ): void {
			$id = (int) ( $GLOBALS['mlp_smoke']['details']['src_text'] ?? 0 );
			$t  = Schema::table( 'sources' );
			// phpcs:disable
			$hash      = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT LOWER(HEX(uniq_hash)) FROM %i WHERE id = %d', $t, $id ) );
			$unhex_ok  = (string) $id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE uniq_hash = UNHEX(%s)', $t, $hash ) );
			$hex_ok    = (string) $id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE HEX(uniq_hash) = %s', $t, strtoupper( $hash ) ) );
			// phpcs:enable
			$rows = Plugin::container()->get( SourceRepository::class )->lookup( array( $hash ), 'en' );
			$text = $rows[ $hash ]['text'] ?? null;
			$skip = ! $unhex_ok && $hex_ok;

			mlp_smoke_check(
				'e_lookup_returns_translation',
				'Smoke paragraph translated XYZ.' === $text || $skip,
				( 'Smoke paragraph translated XYZ.' === $text ? 'ok' : ( $skip ? 'SKIP: SQLite layer cannot match BINARY = UNHEX() (environment limitation)' : 'got ' . wp_json_encode( $rows ) ) )
			);

			$r  = mlp_smoke_page( 'en_post_after' );
			$ok = null !== $r && str_contains( $r['body'], 'Smoke paragraph translated XYZ.' );
			mlp_smoke_check(
				'e_translation_rendered_on_en',
				$ok || $skip,
				$ok ? 'status=' . $r['status'] : ( $skip ? 'SKIP: same SQLite UNHEX() limitation, see e_lookup_returns_translation' : 'translation missing in /en/ HTML' )
			);
			mlp_smoke_check( 'e_internal_link_prefixed_on_en', null !== $r && str_contains( $r['body'], 'href="/en/about-smoke/"' ), '' );
		}
	);

	// (f) repositories / cleanup (%i queries).
	mlp_smoke_step(
		'f_repositories',
		static function () use ( $wpdb ): void {
			$c   = Plugin::container();
			$src = $c->get( SourceRepository::class );
			$get = $c->get( GettextRepository::class );

			foreach ( array( '', 'content', 'seo', 'attribute', 'link', 'text' ) as $type ) {
				$wpdb->last_error = '';
				$r                = $src->paginate(
					array(
						'locale'   => 'en',
						'type'     => $type,
						'search'   => 'smoke',
						'per_page' => 10,
					)
				);
				mlp_smoke_check( 'f_source_paginate type=' . ( '' === $type ? 'all' : $type ), '' === $wpdb->last_error, 'total=' . ( $r['total'] ?? '?' ) . ' err=' . $wpdb->last_error );
			}

			foreach ( array( 'global', 'object' ) as $scope ) {
				$wpdb->last_error = '';
				$r                = $src->paginate(
					array(
						'locale'    => 'en',
						'scope'     => $scope,
						'object_id' => (int) ( $GLOBALS['mlp_smoke']['details']['post_id'] ?? 1 ),
						'status'    => 'missing',
					)
				);
				mlp_smoke_check( 'f_source_paginate scope=' . $scope, '' === $wpdb->last_error, 'total=' . ( $r['total'] ?? '?' ) . ' err=' . $wpdb->last_error );
			}

			$wpdb->last_error = '';
			$r                = $get->paginate( array( 'locale' => 'en', 'search' => 'a' ) );
			$get->domains();
			mlp_smoke_check( 'f_gettext_paginate_domains', '' === $wpdb->last_error, 'total=' . ( $r['total'] ?? '?' ) . ' err=' . $wpdb->last_error );

			$wpdb->last_error = '';
			$get->countUntranslated();
			$src->countUntranslated( array( 'text', 'attribute' ) );
			$get->overridesFor( 'en', 1 );
			mlp_smoke_check( 'f_count_untranslated_overrides', '' === $wpdb->last_error, $wpdb->last_error );

			$wpdb->last_error = '';
			$res              = array( 'error' => false );

			for ( $i = 0; $i < 6; $i++ ) {
				$res = ( new StaleStringsCleanup() )->run();

				if ( $res['error'] || $res['done'] ) {
					break;
				}
			}
			mlp_smoke_check( 'f_stale_cleanup_run', empty( $res['error'] ) && '' === $wpdb->last_error, wp_json_encode( $res ) . ' err=' . $wpdb->last_error );

			$wpdb->last_error = '';
			$res              = ( new OccurrenceObjectTypeMigration() )->run();
			mlp_smoke_check( 'f_objtype_migration_run', empty( $res['error'] ) && '' === $wpdb->last_error, wp_json_encode( $res ) . ' err=' . $wpdb->last_error );

			$wpdb->last_error = '';
			$src->deleteUntranslated( array( 'attribute' ) );
			$get->deleteUntranslated();
			mlp_smoke_check( 'f_delete_untranslated', '' === $wpdb->last_error, $wpdb->last_error );
		}
	);

	// (g) bundled ru_RU.
	mlp_smoke_step(
		'g_locale',
		static function (): void {
			$force = static fn(): string => 'ru_RU';
			add_filter( 'locale', $force );
			add_filter( 'plugin_locale', $force );
			unload_textdomain( 'lingocanvas' );
			Plugin::loadTextdomain();
			$languages = __( 'Languages', 'lingocanvas' );
			mlp_smoke_check( 'g_bundled_ru_RU_loads', 'Языки' === $languages, 'got=' . $languages );
			$ctx = _x( 'OpenAI key', 'mid-sentence', 'lingocanvas' );
			mlp_smoke_check( 'g_context_translation', 'ключ OpenAI' === $ctx, 'got=' . $ctx );
			remove_filter( 'locale', $force );
			remove_filter( 'plugin_locale', $force );
		}
	);

	// Uninstall path.
	mlp_smoke_step(
		'u_uninstall',
		static function () use ( $wpdb ): void {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$s                             = (array) get_option( 'mlp_settings', array() );
			$s['delete_data_on_uninstall'] = true;
			update_option( 'mlp_settings', $s );

			deactivate_plugins( 'lingocanvas/lingocanvas.php' );
			$res = delete_plugins( array( 'lingocanvas/lingocanvas.php' ) );

			// delete_plugins() runs uninstall.php first; the mounted plugin folder itself may then
			// refuse to be removed in Playground. If uninstall.php is still there, run it directly.
			$uninstall = WP_PLUGIN_DIR . '/lingocanvas/uninstall.php';

			if ( true !== $res && is_readable( $uninstall ) ) {
				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'lingocanvas/lingocanvas.php' );
				}
				include $uninstall;
			}

			$res = is_wp_error( $res ) ? $res->get_error_code() : $res;

			$left = array();

			foreach ( array( 'sources', 'translations', 'occurrences', 'routes' ) as $n ) {
				$t = $wpdb->prefix . 'mlp_' . $n;

				if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
					$left[] = $t;
				}
			}

			// phpcs:ignore
			$opts = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mlp\\_%' OR option_name LIKE '\\_transient\\_mlp\\_%'" );

			mlp_smoke_check( 'u_tables_dropped', array() === $left, $left ? 'left: ' . implode( ',', $left ) : 'delete_plugins=' . var_export( $res, true ) );
			mlp_smoke_check( 'u_options_removed', array() === $opts, $opts ? 'left: ' . implode( ',', $opts ) : '' );
		}
	);

	// (h) debug.log.
	mlp_smoke_flush();
	$lines = $GLOBALS['mlp_smoke']['log_lines'];
	mlp_smoke_check( 'h_debug_log_clean', array() === $lines, count( $lines ) . ' lines of interest' );
}

mlp_smoke_flush();
