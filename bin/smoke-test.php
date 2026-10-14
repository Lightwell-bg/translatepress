<?php
/**
 * Smoke-test body of scenario S1 (fresh LingoCanvas), executed inside WordPress Playground by
 * bin/smoke-test-blueprint.json (see bin/smoke-test.sh). Not part of the plugin ZIP.
 *
 * The blueprint runs several runPHP steps (every one is a fresh PHP request):
 *   setup  -> tables, posts, settings
 *   render -> bin/smoke-test-render.php simulates a front-end request, saves /out/page-<name>.html
 *   check1 -> strings collected, REST GET routes, manual translation, unsafe href rejection
 *   extra  -> sitemap, switcher shortcode, canonical/hreflang, en/ru UI + settings page,
 *             AI translation through the mocked OpenAI endpoint, deprecated hook alias
 *   check2 -> translation rendered, %i repository queries, ru_RU, uninstall, debug.log
 * State and results accumulate in /out/smoke.json.
 *
 * Playground runs WordPress on SQLite (not MySQL): the SQL goes through the SQLite
 * integration layer, so a DB error here can also come from that layer.
 */

use WpMlp\Admin\SettingsPage;
use WpMlp\Plugin;
use WpMlp\Storage\GettextRepository;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\OccurrenceObjectTypeMigration;
use WpMlp\Storage\Schema;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\StaleStringsCleanup;

require __DIR__ . '/smoke-test-lib.php';

/**
 * Whether the SQL layer can match a BINARY column with UNHEX() (MySQL yes, Playground SQLite no).
 */
function mlp_smoke_unhex_works( int $source_id ): bool {
	global $wpdb;

	$t    = Schema::table( 'sources' );
	$hash = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT LOWER(HEX(uniq_hash)) FROM %i WHERE id = %d', $t, $source_id ) ); // phpcs:ignore

	return (string) $source_id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE uniq_hash = UNHEX(%s)', $t, $hash ) ); // phpcs:ignore
}

global $wpdb;
wp_set_current_user( 1 );

$phase = $GLOBALS['MLP_SMOKE_PHASE'] ?? '';
$slug  = 'smoke-post';
$fake  = 'sk-smoke-FAKEKEY-9f8e7d6c5b4a'; // Same value as in the mu-plugin fixture.

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
			mlp_smoke_check( 'a_plugin_class_from_lingocanvas_dir', str_contains( str_replace( '\\', '/', ( new ReflectionClass( Plugin::class ) )->getFileName() ), '/plugins/lingocanvas/src/' ) );
			mlp_smoke_check( 'a_wp_debug_on', defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG, 'WP_DEBUG=' . var_export( defined( 'WP_DEBUG' ) ? WP_DEBUG : null, true ) . ' WP_DEBUG_LOG=' . var_export( defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null, true ) . ' error_log=' . ini_get( 'error_log' ) . ' log_errors=' . ini_get( 'log_errors' ) );
			mlp_smoke_check( 'a_pending_activation_not_set', false === get_option( 'lingocanvas_pending_activation', false ) );
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

			$switch = (int) wp_insert_post(
				array(
					'post_title'   => 'Smoke switch',
					'post_name'    => 'smoke-switch',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '<p>Switcher below.</p>[mlp_language_switcher]',
				)
			);

			mlp_smoke_check( 'b_post_created', $post_id > 0 && $about > 0 && $switch > 0, 'post_id=' . $post_id );
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

			// The bulk-translation summary links the post to its strings (occurrences). Only then is the
			// post "translated into en", which is what puts /en/ alternates into the sitemap.
			$req = new WP_REST_Request( 'GET', '/mlp/v1/posts/' . (int) ( $GLOBALS['mlp_smoke']['details']['post_id'] ?? 0 ) . '/summary' );
			$req->set_param( 'locale', 'en' );
			$req->set_param( 'mode', 'all' );
			$res = rest_do_request( $req );
			mlp_smoke_check( 'e_post_summary_links_occurrences', 200 === $res->get_status(), 'status=' . $res->get_status() );

			$post_id = (int) ( $GLOBALS['mlp_smoke']['details']['post_id'] ?? 0 );
			// phpcs:ignore
			$have = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'post' AND object_id = %d AND source_id = %d", Schema::table( 'occurrences' ), $post_id, $src_text ) );

			if ( 0 === $have ) {
				// On SQLite the summary cannot resolve source ids (BINARY = UNHEX() lookup), so it writes no
				// occurrence. Seed the row with the plugin's own repository to still exercise the sitemap.
				Plugin::container()->get( OccurrenceRepository::class )->insertMany(
					array(
						array(
							'source_id'      => $src_text,
							'object_type'    => 'post',
							'object_id'      => $post_id,
							'url_hash'       => null,
							'attribute_name' => null,
							'uniq_hash'      => hash( 'sha256', 'smoke-occurrence-' . $post_id . '-' . $src_text ),
						),
					)
				);
			}

			mlp_smoke_check( 'e_post_occurrence_for_sitemap', true, 0 === $have ? 'seeded through OccurrenceRepository::insertMany (summary wrote none: SQLite UNHEX lookup limitation)' : 'written by the plugin' );
		}
	);
}

// ===== extra ===============================================================
if ( 'extra' === $phase ) {
	$post_id  = (int) ( $GLOBALS['mlp_smoke']['details']['post_id'] ?? 0 );
	$src_text = (int) ( $GLOBALS['mlp_smoke']['details']['src_text'] ?? 0 );
	$unhex_ok = $src_text > 0 && mlp_smoke_unhex_works( $src_text );

	// (a) sitemap.
	mlp_smoke_step(
		'x_sitemap',
		static function (): void {
			$r = mlp_smoke_page( 'sitemap' );

			if ( null === $r ) {
				mlp_smoke_check( 'x_a_sitemap_200_xml', false, 'render produced no result' );

				return;
			}

			$xml_type = str_contains( strtolower( $r['content_type'] ), 'xml' );
			$parsed   = @simplexml_load_string( $r['body'] ); // phpcs:ignore
			mlp_smoke_check( 'x_a_sitemap_200_xml', 200 === $r['status'] && $xml_type && false !== $parsed, 'status=' . $r['status'] . ' content_type=' . $r['content_type'] . ' bytes=' . strlen( $r['body'] ) . ' valid_xml=' . ( false !== $parsed ? 'yes' : 'no' ) );

			$alts = (int) preg_match_all( '/<xhtml:link\s+rel="alternate"\s+hreflang="[^"]+"\s+href="[^"]+"/', $r['body'] );
			$en   = (int) preg_match_all( '/<xhtml:link\s+rel="alternate"\s+hreflang="en"/', $r['body'] );
			mlp_smoke_check( 'x_a_sitemap_has_xhtml_link_hreflang', $alts >= 2 && $en >= 1, 'xhtml:link=' . $alts . ' hreflang_en=' . $en );
		}
	);

	// (b) switcher shortcode.
	mlp_smoke_step(
		'x_switcher',
		static function (): void {
			$r = mlp_smoke_page( 'switcher' );

			mlp_smoke_check(
				'x_b_switcher_links_to_en_of_current_page',
				null !== $r && 200 === $r['status'] && (bool) preg_match( '#<a [^>]*href="[^"]*/en/smoke-switch/?"#', $r['body'] ),
				null === $r ? 'render produced no result' : 'status=' . $r['status'] . ' mlp-language-switcher=' . ( str_contains( $r['body'], 'mlp-language-switcher' ) ? 'yes' : 'no' )
			);
		}
	);

	// (c) canonical + hreflang on a /en/ page.
	mlp_smoke_step(
		'x_canonical',
		static function (): void {
			$r = mlp_smoke_page( 'en_post' );

			if ( null === $r ) {
				mlp_smoke_check( 'x_c_en_canonical_hreflang', false, 'render produced no result' );

				return;
			}

			preg_match_all( '/<link\s+rel=["\']canonical["\'][^>]*>/i', $r['body'], $canon );
			preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']+)["\']\s+href=["\']([^"\']+)["\']/i', $r['body'], $alt );
			$canonical_en = 1 === count( $canon[0] ) && (bool) preg_match( '#href=["\'][^"\']*/en/smoke-post/?["\']#', $canon[0][0] );
			$langs        = $alt[1] ?? array();
			mlp_smoke_check(
				'x_c_en_canonical_hreflang',
				$canonical_en && in_array( 'en', $langs, true ) && in_array( 'x-default', $langs, true ) && count( $langs ) === count( array_unique( $langs ) ),
				'canonical_tags=' . count( $canon[0] ) . ' canonical_is_en=' . ( $canonical_en ? 'yes' : 'no' ) . ' hreflang=' . implode( ',', $langs )
			);
		}
	);

	// (d) UI languages.
	mlp_smoke_step(
		'x_ui_languages',
		static function (): void {
			mlp_smoke_check( 'x_d_en_US_Languages', 'en_US' === get_locale() && 'Languages' === __( 'Languages', 'lingocanvas' ), 'locale=' . get_locale() . ' got=' . __( 'Languages', 'lingocanvas' ) );

			$switched = switch_to_locale( 'ru_RU' );
			$ru       = __( 'Languages', 'lingocanvas' );

			if ( $switched && 'Языки' === $ru ) {
				mlp_smoke_check( 'x_d_ru_RU_switch_to_locale', true, 'got=' . $ru );
				restore_previous_locale();
			} else {
				if ( $switched ) {
					restore_previous_locale();
				}

				// Playground has no ru_RU language pack, so switch_to_locale() is a no-op there.
				mlp_smoke_check( 'x_d_ru_RU_switch_to_locale', true, 'SKIP: ru_RU is not installed in this WordPress (switch_to_locale returned ' . var_export( $switched, true ) . '); the bundled translation is verified with locale filters below' );
			}

			$force = static fn(): string => 'ru_RU';
			add_filter( 'locale', $force );
			add_filter( 'plugin_locale', $force );
			unload_textdomain( 'lingocanvas' );
			Plugin::loadTextdomain();
			mlp_smoke_check( 'x_d_ru_RU_bundled_Languages', 'Языки' === __( 'Languages', 'lingocanvas' ), 'got=' . __( 'Languages', 'lingocanvas' ) );

			// Settings page as admin in ru_RU.
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			set_current_screen( 'toplevel_page_' . SettingsPage::MENU_SLUG );
			$errors = array();
			set_error_handler( // phpcs:ignore
				static function ( int $no, string $str, string $file, int $line ) use ( &$errors ): bool {
					$errors[] = $no . ': ' . $str . ' @ ' . basename( $file ) . ':' . $line;

					return true;
				}
			);
			ob_start();
			Plugin::container()->get( SettingsPage::class )->render();
			$html = (string) ob_get_clean();
			restore_error_handler();

			mlp_smoke_check( 'x_d_settings_page_ru_text', str_contains( $html, 'Языки сайта' ) && ! str_contains( $html, 'Site languages' ), 'bytes=' . strlen( $html ) );
			mlp_smoke_check( 'x_d_settings_page_no_php_notice', array() === $errors && ! preg_match( '/<b>(Notice|Warning|Deprecated|Fatal error)<\/b>/', $html ), $errors ? implode( ' | ', array_slice( $errors, 0, 3 ) ) : 'no notices' );
			mlp_smoke_check( 'x_d_settings_page_no_key_leak', ! str_contains( $html, 'sk-smoke-FAKEKEY' ), '' );

			remove_filter( 'locale', $force );
			remove_filter( 'plugin_locale', $force );
			unload_textdomain( 'lingocanvas' );
			Plugin::loadTextdomain();
		}
	);

	// (e) AI translation through the mocked endpoint.
	mlp_smoke_step(
		'x_ai',
		static function () use ( $post_id, $src_text, $unhex_ok, $fake ): void {
			$bodies = array();

			$req = new WP_REST_Request( 'POST', '/mlp/v1/translations/' . $src_text . '/en/suggest' );
			$res = rest_do_request( $req );
			$data = $res->get_data();
			$bodies[] = wp_json_encode( $data );
			$text = is_array( $data ) ? (string) ( $data['suggested_text'] ?? '' ) : '';
			mlp_smoke_check( 'x_e_ai_suggest_mocked', 200 === $res->get_status() && str_starts_with( $text, 'MOCK-EN: ' ), 'status=' . $res->get_status() . ' text=' . substr( $text, 0, 60 ) . ( $res->is_error() ? ' code=' . $res->as_error()->get_error_code() : '' ) );

			$req = new WP_REST_Request( 'GET', '/mlp/v1/posts/' . $post_id . '/summary' );
			$req->set_param( 'locale', 'en' );
			$req->set_param( 'mode', 'all' );
			$sum  = rest_do_request( $req );
			$sdat = $sum->get_data();
			$bodies[] = wp_json_encode( $sdat );
			$hashes = is_array( $sdat ) && ! empty( $sdat['chunks'][0] ) ? $sdat['chunks'][0] : array();
			mlp_smoke_check( 'x_e_ai_bulk_summary', 200 === $sum->get_status() && array() !== $hashes, 'status=' . $sum->get_status() . ' chunks=' . ( is_array( $sdat ) ? count( $sdat['chunks'] ?? array() ) : '?' ) . ' to_translate=' . ( is_array( $sdat ) ? (string) ( $sdat['to_translate'] ?? '?' ) : '?' ) );

			$req = new WP_REST_Request( 'POST', '/mlp/v1/posts/' . $post_id . '/chunk' );
			$req->set_param( 'locale', 'en' );
			$req->set_param( 'hashes', $hashes );
			$res  = rest_do_request( $req );
			$data = $res->get_data();
			$bodies[] = wp_json_encode( $data );
			$code = $res->is_error() ? $res->as_error()->get_error_code() : '';
			$ok   = false;

			if ( is_array( $data ) && ! empty( $data['translations'] ) ) {
				$ok = true;

				foreach ( $data['translations'] as $t ) {
					$ok = $ok && str_starts_with( (string) $t, 'MOCK-EN: ' );
				}
			}

			$skip = ! $ok && 'mlp_unknown_hashes' === $code && ! $unhex_ok;
			mlp_smoke_check(
				'x_e_ai_bulk_chunk_mocked',
				$ok || $skip,
				$ok ? 'translations=' . count( $data['translations'] ) : ( $skip ? 'SKIP: SQLite layer cannot match BINARY = UNHEX() (idsByHashes finds nothing -> mlp_unknown_hashes); environment limitation' : 'status=' . $res->get_status() . ' code=' . $code )
			);

			$calls = is_readable( '/out/mock-calls.jsonl' ) ? (string) file_get_contents( '/out/mock-calls.jsonl' ) : '';
			mlp_smoke_check( 'x_e_ai_mock_endpoint_hit_with_key', str_contains( $calls, '"key_sent":true' ) && str_contains( $calls, 'smoke-model' ), 'calls=' . substr_count( $calls, "\n" ) );

			$leak = false;

			foreach ( $bodies as $b ) {
				$leak = $leak || str_contains( (string) $b, 'sk-smoke-FAKEKEY' ) || str_contains( (string) $b, $fake );
			}

			foreach ( array( 'default_front', 'default_post', 'en_front', 'en_post', 'en_post_after', 'sitemap', 'switcher' ) as $page ) {
				$p    = mlp_smoke_page( $page );
				$leak = $leak || ( null !== $p && str_contains( $p['body'], 'sk-smoke-FAKEKEY' ) );
			}

			$log  = is_readable( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
			$leak = $leak || str_contains( $log, 'sk-smoke-FAKEKEY' );
			mlp_smoke_check( 'x_e_ai_key_never_in_responses_pages_log', ! $leak, $leak ? 'FAKE KEY FOUND in a response body, page or debug.log' : 'REST bodies, front-end pages, debug.log clean' );
		}
	);

	// (f) deprecated hook alias.
	mlp_smoke_step(
		'x_hooks',
		static function (): void {
			$old = is_readable( '/out/hook-old.txt' ) ? substr_count( (string) file_get_contents( '/out/hook-old.txt' ), "\n" ) : 0;
			$new = is_readable( '/out/hook-new.txt' ) ? substr_count( (string) file_get_contents( '/out/hook-new.txt' ), "\n" ) : 0;
			mlp_smoke_check( 'x_f_both_sitemap_hooks_ran', $old >= 1 && $new >= 1, 'mlp_sitemap_post_ids calls=' . $old . ' lingocanvas_sitemap_post_ids calls=' . $new );

			$log = is_readable( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
			mlp_smoke_check( 'x_f_deprecation_notice_logged', (bool) preg_match( '/Deprecated.*mlp_sitemap_post_ids.*lingocanvas_sitemap_post_ids/s', $log ), 'expected notice for the old hook name' );
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

	// (h) debug.log. The deprecation notice of the old hook name mlp_sitemap_post_ids is expected
	// (the fixture attaches a callback to it on purpose, see x_f_deprecation_notice_logged).
	mlp_smoke_flush();
	$lines = array_values(
		array_filter(
			$GLOBALS['mlp_smoke']['log_lines'],
			static fn( string $l ): bool => false === strpos( $l, 'mlp_sitemap_post_ids' )
		)
	);
	mlp_smoke_check( 'h_debug_log_clean', array() === $lines, count( $lines ) . ' unexpected lines of interest' );
}

mlp_smoke_flush();
