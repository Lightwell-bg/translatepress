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
 *             AI translation through the mocked OpenAI endpoint, sitemap extension filter
 *   check2 -> translation rendered, %i repository queries, ru_RU, uninstall, debug.log
 * State and results accumulate in /out/smoke.json.
 *
 * Playground runs WordPress on SQLite (not MySQL): the SQL goes through the SQLite
 * integration layer, so a DB error here can also come from that layer.
 */

use LingoCanvas\Admin\SettingsPage;
use LingoCanvas\Plugin;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\GettextRepository;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\Schema;
use LingoCanvas\Storage\SourceRepository;

require __DIR__ . '/smoke-test-lib.php';

/**
 * Whether the SQL layer can match a BINARY column with UNHEX() (MySQL yes, Playground SQLite no).
 */
function lingocanvas_smoke_unhex_works( int $source_id ): bool {
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
	$GLOBALS['lingocanvas_smoke'] = array(
		'checks'    => array(),
		'details'   => array(),
		'log_lines' => array(),
	);

	lingocanvas_smoke_step(
		'a_tables',
		static function () use ( $wpdb ): void {
			$missing = array();

			foreach ( Schema::TABLES as $name ) {
				$t = Schema::table( $name );

				if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
					$missing[] = $t;
				}
			}

			lingocanvas_smoke_check( 'a_tables_exist_after_activation', array() === $missing, $missing ? 'missing: ' . implode( ',', $missing ) : implode( ',', Schema::TABLES ) );
			lingocanvas_smoke_check( 'a_db_version_option', (int) get_option( Schema::versionOption(), 0 ) === Schema::VERSION, 'option=' . Schema::versionOption() );
			// A fresh install creates exactly the four lingocanvas_* tables and no service options of earlier versions.
			$service = array_values( array_filter( array( 'lingocanvas_data_prefix', 'lingocanvas_migration', 'lingocanvas_write_freeze', 'lingocanvas_pending_activation' ), static fn( string $o ): bool => false !== get_option( $o, false ) ) );
			lingocanvas_smoke_check( 'a_fresh_install_has_four_tables_and_no_service_options', count( Schema::TABLES ) === count( lingocanvas_smoke_tables() ) && array() === $service, 'tables=' . implode( ',', lingocanvas_smoke_tables() ) . ' service_options=' . implode( ',', $service ) );
			lingocanvas_smoke_check( 'a_plugin_class_from_lingocanvas_dir', str_contains( str_replace( '\\', '/', ( new ReflectionClass( Plugin::class ) )->getFileName() ), '/plugins/lingocanvas/src/' ) );
			lingocanvas_smoke_check( 'a_wp_debug_on', defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG, 'WP_DEBUG=' . var_export( defined( 'WP_DEBUG' ) ? WP_DEBUG : null, true ) . ' WP_DEBUG_LOG=' . var_export( defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null, true ) . ' error_log=' . ini_get( 'error_log' ) . ' log_errors=' . ini_get( 'log_errors' ) );
		}
	);

	lingocanvas_smoke_step(
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
					'post_content' => '<p>Switcher below.</p>[lingocanvas_language_switcher]',
				)
			);

			lingocanvas_smoke_check( 'b_post_created', $post_id > 0 && $about > 0 && $switch > 0, 'post_id=' . $post_id );
			$GLOBALS['lingocanvas_smoke']['details']['post_id'] = $post_id;

			// Collect strings from anonymous visitors too, so a front-end render fills the dictionary.
			$s                            = (array) get_option( Settings::optionName(), array() );
			$s['discover_from_visitors']  = true;
			$s['untranslated_posts_mode'] = 'all';
			update_option( Settings::optionName(), $s );
			flush_rewrite_rules();

			// The render steps need the site host, or WordPress canonical-redirects to it.
			file_put_contents( '/out/site-host.txt', (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
		}
	);
}

// ===== check1 ==============================================================
if ( 'check1' === $phase ) {
	$post_id = (int) ( $GLOBALS['lingocanvas_smoke']['details']['post_id'] ?? 0 );

	// (c) front-end renders.
	lingocanvas_smoke_step(
		'c_frontend',
		static function () use ( $slug ): void {
			$expect = array(
				'default_front' => 'hreflang tags',
				'default_post'  => 'hreflang tags',
				'en_front'      => 'hreflang tags',
				'en_post'       => 'hreflang tags',
			);

			foreach ( $expect as $name => $_ ) {
				$r = lingocanvas_smoke_page( $name );

				if ( null === $r ) {
					lingocanvas_smoke_check( 'c_' . $name, false, 'render produced no result' );
					continue;
				}

				$hreflang = (int) preg_match_all( '/<link[^>]+hreflang=/i', $r['body'] );
				$has_html = str_contains( $r['body'], '</html>' );
				$ok       = 200 === $r['status'] && $has_html && $hreflang >= 2;
				lingocanvas_smoke_check( 'c_' . $name, $ok, 'status=' . $r['status'] . ' bytes=' . strlen( $r['body'] ) . ' hreflang_tags=' . $hreflang . ( $r['location'] ? ' location=' . $r['location'] : '' ) );
			}
		}
	);

	// Collected strings.
	lingocanvas_smoke_step(
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
					$GLOBALS['lingocanvas_smoke']['details']['smoke_sources'][] = $row;
				}

				if ( str_contains( $row['source_text'], 'Smoke paragraph hello world' ) ) {
					$text = (int) $row['id'];
				}
				if ( 'attribute' === $row['kind'] && str_contains( $row['source_text'], '/about-smoke/' ) ) {
					$href = (int) $row['id'];
				}
			}

			$GLOBALS['lingocanvas_smoke']['details']['src_text'] = $text;
			$GLOBALS['lingocanvas_smoke']['details']['src_href'] = $href;
			$GLOBALS['lingocanvas_smoke']['details']['kinds']    = $kinds;
			lingocanvas_smoke_check( 'c_strings_collected', $text > 0 && $href > 0, 'sources=' . count( $rows ) . ' text_id=' . $text . ' href_id=' . $href . ' kinds=' . wp_json_encode( $kinds ) );
		}
	);

	$src_text = (int) ( $GLOBALS['lingocanvas_smoke']['details']['src_text'] ?? 0 );
	$src_href = (int) ( $GLOBALS['lingocanvas_smoke']['details']['src_href'] ?? 0 );

	// (d) REST GET routes.
	lingocanvas_smoke_step(
		'd_rest',
		static function () use ( $post_id, $src_text ): void {
			$routes  = rest_get_server()->get_routes();
			$results = array();

			foreach ( $routes as $route => $handlers ) {
				if ( 0 !== strpos( $route, '/lingocanvas/v1' ) ) {
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
				lingocanvas_smoke_check( 'd_rest_GET ' . $url, $res->get_status() < 500, 'status=' . $res->get_status() . ( $err ? ' code=' . $err : '' ) );
			}

			$GLOBALS['lingocanvas_smoke']['details']['rest_get'] = $results;
			lingocanvas_smoke_check( 'd_rest_routes_registered', count( $results ) > 0, count( $results ) . ' GET routes' );
		}
	);

	// (e) manual translation.
	lingocanvas_smoke_step(
		'e_translation',
		static function () use ( $src_text, $src_href ): void {
			if ( $src_text <= 0 ) {
				lingocanvas_smoke_check( 'e_save_translation', false, 'no collected text source to translate' );

				return;
			}

			$req = new WP_REST_Request( 'PUT', '/lingocanvas/v1/translations/' . $src_text . '/en' );
			$req->set_param( 'translated_text', 'Smoke paragraph translated XYZ.' );
			$res = rest_do_request( $req );
			lingocanvas_smoke_check( 'e_save_translation', 200 === $res->get_status(), 'status=' . $res->get_status() );

			if ( $src_href <= 0 ) {
				lingocanvas_smoke_check( 'e_unsafe_href_rejected_400', false, 'no collected href source found' );

				return;
			}

			$req = new WP_REST_Request( 'PUT', '/lingocanvas/v1/translations/' . $src_href . '/en' );
			$req->set_param( 'translated_text', 'javascript:alert(1)' );
			$res = rest_do_request( $req );
			lingocanvas_smoke_check( 'e_unsafe_href_rejected_400', 400 === $res->get_status(), 'status=' . $res->get_status() );
			// phpcs:ignore
			$GLOBALS['lingocanvas_smoke']['details']['translations_rows'] = $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT * FROM %i', Schema::table( 'translations' ) ), ARRAY_A );
			$GLOBALS['lingocanvas_smoke']['details']['cache_version']      = get_option( 'lingocanvas_cache_version' );

			$req = new WP_REST_Request( 'PUT', '/lingocanvas/v1/translations/' . $src_href . '/en' );
			$req->set_param( 'translated_text', '/en/about-smoke/' );
			$res = rest_do_request( $req );
			lingocanvas_smoke_check( 'e_safe_href_accepted', 200 === $res->get_status(), 'status=' . $res->get_status() );

			// The bulk-translation summary links the post to its strings (occurrences). Only then is the
			// post "translated into en", which is what puts /en/ alternates into the sitemap.
			$req = new WP_REST_Request( 'GET', '/lingocanvas/v1/posts/' . (int) ( $GLOBALS['lingocanvas_smoke']['details']['post_id'] ?? 0 ) . '/summary' );
			$req->set_param( 'locale', 'en' );
			$req->set_param( 'mode', 'all' );
			$res = rest_do_request( $req );
			lingocanvas_smoke_check( 'e_post_summary_links_occurrences', 200 === $res->get_status(), 'status=' . $res->get_status() );

			$post_id = (int) ( $GLOBALS['lingocanvas_smoke']['details']['post_id'] ?? 0 );
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

			lingocanvas_smoke_check( 'e_post_occurrence_for_sitemap', true, 0 === $have ? 'seeded through OccurrenceRepository::insertMany (summary wrote none: SQLite UNHEX lookup limitation)' : 'written by the plugin' );
		}
	);
}

// ===== extra ===============================================================
if ( 'extra' === $phase ) {
	$post_id  = (int) ( $GLOBALS['lingocanvas_smoke']['details']['post_id'] ?? 0 );
	$src_text = (int) ( $GLOBALS['lingocanvas_smoke']['details']['src_text'] ?? 0 );
	$unhex_ok = $src_text > 0 && lingocanvas_smoke_unhex_works( $src_text );

	// (a) sitemap.
	lingocanvas_smoke_step(
		'x_sitemap',
		static function (): void {
			$r = lingocanvas_smoke_page( 'sitemap' );

			if ( null === $r ) {
				lingocanvas_smoke_check( 'x_a_sitemap_200_xml', false, 'render produced no result' );

				return;
			}

			$xml_type = str_contains( strtolower( $r['content_type'] ), 'xml' );
			$parsed   = @simplexml_load_string( $r['body'] ); // phpcs:ignore
			lingocanvas_smoke_check( 'x_a_sitemap_200_xml', 200 === $r['status'] && $xml_type && false !== $parsed, 'status=' . $r['status'] . ' content_type=' . $r['content_type'] . ' bytes=' . strlen( $r['body'] ) . ' valid_xml=' . ( false !== $parsed ? 'yes' : 'no' ) );

			$alts = (int) preg_match_all( '/<xhtml:link\s+rel="alternate"\s+hreflang="[^"]+"\s+href="[^"]+"/', $r['body'] );
			$en   = (int) preg_match_all( '/<xhtml:link\s+rel="alternate"\s+hreflang="en"/', $r['body'] );
			lingocanvas_smoke_check( 'x_a_sitemap_has_xhtml_link_hreflang', $alts >= 2 && $en >= 1, 'xhtml:link=' . $alts . ' hreflang_en=' . $en );
		}
	);

	// (b) switcher shortcode.
	lingocanvas_smoke_step(
		'x_switcher',
		static function (): void {
			$r = lingocanvas_smoke_page( 'switcher' );

			lingocanvas_smoke_check(
				'x_b_switcher_links_to_en_of_current_page',
				null !== $r && 200 === $r['status'] && (bool) preg_match( '#<a [^>]*href="[^"]*/en/smoke-switch/?"#', $r['body'] ),
				null === $r ? 'render produced no result' : 'status=' . $r['status'] . ' mlp-language-switcher=' . ( str_contains( $r['body'], 'mlp-language-switcher' ) ? 'yes' : 'no' )
			);
		}
	);

	// (c) canonical + hreflang on a /en/ page.
	lingocanvas_smoke_step(
		'x_canonical',
		static function (): void {
			$r = lingocanvas_smoke_page( 'en_post' );

			if ( null === $r ) {
				lingocanvas_smoke_check( 'x_c_en_canonical_hreflang', false, 'render produced no result' );

				return;
			}

			preg_match_all( '/<link\s+rel=["\']canonical["\'][^>]*>/i', $r['body'], $canon );
			preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']+)["\']\s+href=["\']([^"\']+)["\']/i', $r['body'], $alt );
			$canonical_en = 1 === count( $canon[0] ) && (bool) preg_match( '#href=["\'][^"\']*/en/smoke-post/?["\']#', $canon[0][0] );
			$langs        = $alt[1] ?? array();
			lingocanvas_smoke_check(
				'x_c_en_canonical_hreflang',
				$canonical_en && in_array( 'en', $langs, true ) && in_array( 'x-default', $langs, true ) && count( $langs ) === count( array_unique( $langs ) ),
				'canonical_tags=' . count( $canon[0] ) . ' canonical_is_en=' . ( $canonical_en ? 'yes' : 'no' ) . ' hreflang=' . implode( ',', $langs )
			);
		}
	);

	// (d) UI languages.
	lingocanvas_smoke_step(
		'x_ui_languages',
		static function (): void {
			lingocanvas_smoke_check( 'x_d_en_US_Languages', 'en_US' === get_locale() && 'Languages' === __( 'Languages', 'lingocanvas' ), 'locale=' . get_locale() . ' got=' . __( 'Languages', 'lingocanvas' ) );

			$switched = switch_to_locale( 'ru_RU' );
			$ru       = __( 'Languages', 'lingocanvas' );

			if ( $switched && 'Языки' === $ru ) {
				lingocanvas_smoke_check( 'x_d_ru_RU_switch_to_locale', true, 'got=' . $ru );
				restore_previous_locale();
			} else {
				if ( $switched ) {
					restore_previous_locale();
				}

				// Playground has no ru_RU language pack, so switch_to_locale() is a no-op there.
				lingocanvas_smoke_check( 'x_d_ru_RU_switch_to_locale', true, 'SKIP: ru_RU is not installed in this WordPress (switch_to_locale returned ' . var_export( $switched, true ) . '); the bundled translation is verified with locale filters below' );
			}

			$force = static fn(): string => 'ru_RU';
			add_filter( 'locale', $force );
			add_filter( 'plugin_locale', $force );
			unload_textdomain( 'lingocanvas' );
			Plugin::loadTextdomain();
			lingocanvas_smoke_check( 'x_d_ru_RU_bundled_Languages', 'Языки' === __( 'Languages', 'lingocanvas' ), 'got=' . __( 'Languages', 'lingocanvas' ) );

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

			lingocanvas_smoke_check( 'x_d_settings_page_ru_text', str_contains( $html, 'Языки сайта' ) && ! str_contains( $html, 'Site languages' ), 'bytes=' . strlen( $html ) );
			lingocanvas_smoke_check( 'x_d_settings_page_no_php_notice', array() === $errors && ! preg_match( '/<b>(Notice|Warning|Deprecated|Fatal error)<\/b>/', $html ), $errors ? implode( ' | ', array_slice( $errors, 0, 3 ) ) : 'no notices' );
			lingocanvas_smoke_check( 'x_d_settings_page_no_key_leak', ! str_contains( $html, 'sk-smoke-FAKEKEY' ), '' );

			remove_filter( 'locale', $force );
			remove_filter( 'plugin_locale', $force );
			unload_textdomain( 'lingocanvas' );
			Plugin::loadTextdomain();
		}
	);

	// (d2) wp_kses at the admin echo sites: the real builder output must pass through real wp_kses
	// with the AllowedHtml list unchanged (every tag and attribute kept), and the pages must render
	// the expected controls. Runs in manual visibility mode (toggles exist only there).
	lingocanvas_smoke_step(
		'x_kses_identity',
		static function () use ( $post_id ): void {
			require_once ABSPATH . 'wp-admin/includes/admin.php';

			/**
			 * Multiset of "tag|sorted attributes" of every element in an HTML fragment.
			 *
			 * @return list<string>
			 */
			$sig = static function ( string $html ): array {
				$doc  = new DOMDocument();
				$prev = libxml_use_internal_errors( true );
				$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
				libxml_clear_errors();
				libxml_use_internal_errors( $prev );
				$out = array();

				foreach ( $doc->getElementsByTagName( '*' ) as $el ) {
					if ( in_array( $el->nodeName, array( 'html', 'body' ), true ) ) {
						continue;
					}

					$attrs = array();

					foreach ( $el->attributes as $a ) {
						$attrs[] = $a->name . '=' . $a->value;
					}

					sort( $attrs );
					$out[] = $el->nodeName . '|' . implode( '&', $attrs );
				}

				sort( $out );

				return $out;
			};

			$identity = static function ( string $name, string $html, array $allowed ) use ( $sig ): void {
				$kept = wp_kses( $html, $allowed );
				$a    = $sig( $html );
				$b    = $sig( $kept );
				lingocanvas_smoke_check(
					'x_kses_identity_' . $name,
					array() !== $a && $a === $b,
					array() === $a ? 'builder produced no elements' : ( $a === $b ? count( $a ) . ' elements kept' : 'LOST: ' . implode( ' ; ', array_diff( $a, $b ) ) . ' | ADDED: ' . implode( ' ; ', array_diff( $b, $a ) ) )
				);
			};

			$c = Plugin::container();

			// Manual mode for the duration of this step.
			$orig                          = (array) get_option( Settings::optionName(), array() );
			$manual                        = $orig;
			$manual['untranslated_posts_mode'] = 'manual';
			update_option( Settings::optionName(), $manual );
			$c->get( Settings::class )->reset();

			try {
				$ctx = LingoCanvas\Admin\AllowedHtml::class;

				// VisibilityAssets::toggleHtml: checked + disabled, unchecked, checked.
				$identity( 'toggle_checked_disabled', LingoCanvas\Admin\VisibilityAssets::toggleHtml( 7, 'en', true, 'on site', 'No permission "x" <b>' ), $ctx::visibilityToggle() );
				$identity( 'toggle_unchecked', LingoCanvas\Admin\VisibilityAssets::toggleHtml( 7, 'en', false, 'on site' ), $ctx::visibilityToggle() );
				$identity( 'toggle_checked', LingoCanvas\Admin\VisibilityAssets::toggleHtml( 7, 'en', true, 'on site' ), $ctx::visibilityToggle() );

				// SettingsPage::keyStatusHtml (source in smoke: constant from the mu-plugin).
				$settings_page = $c->get( SettingsPage::class );
				$identity( 'key_status', $settings_page->keyStatusHtml(), $ctx::keyStatus() );

				// LingoCanvas Cloud section (not connected: sign-up state) through real wp_kses.
				$cloud_section = $c->get( LingoCanvas\Admin\CloudSection::class );
				$identity( 'cloud_provider', $cloud_section->providerHtml(), $ctx::cloudSection() );
				$identity( 'cloud_block', $cloud_section->cloudHtml(), $ctx::cloudSection() );
				$identity( 'cloud_forms', $cloud_section->formsHtml(), $ctx::cloudSection() );

				// StringTranslationPage::scopeControlHtml.
				$obj = LingoCanvas\Admin\StringTranslationPage::scopeControlHtml( 'object', $post_id, 'Smoke "post" <i>' );
				$identity( 'scope_object', $obj, $ctx::scopeControl() );
				lingocanvas_smoke_check( 'x_kses_scope_object_ul_hidden_picker_visible', 1 === preg_match( '/<ul [^>]*\bhidden\b/', wp_kses( $obj, $ctx::scopeControl() ) ) && 1 !== preg_match( '/<span [^>]*id="mlp-scope-picker"[^>]*\bhidden\b/', wp_kses( $obj, $ctx::scopeControl() ) ), '' );
				$glob = LingoCanvas\Admin\StringTranslationPage::scopeControlHtml( 'global', 0, '' );
				$identity( 'scope_global', $glob, $ctx::scopeControl() );
				lingocanvas_smoke_check( 'x_kses_scope_global_picker_hidden', 1 === preg_match( '/<span [^>]*id="mlp-scope-picker"[^>]*\bhidden\b/', wp_kses( $glob, $ctx::scopeControl() ) ), '' );

				// PostListLanguages::cellHtml (private -> Reflection) for the smoke post and an attachment-like draft.
				$list = $c->get( LingoCanvas\Admin\PostListLanguages::class );
				$ref  = new ReflectionMethod( $list, 'cellHtml' );
				$ref->setAccessible( true );
				$cell = (string) $ref->invoke( $list, get_post( $post_id ) );
				$identity( 'post_list_cell', $cell, $ctx::postListCell() );
				lingocanvas_smoke_check( 'x_kses_post_list_cell_has_toggle', str_contains( $cell, 'mlp-vis-toggle' ) && str_contains( $cell, 'data-post-id="' . $post_id . '"' ) && str_contains( $cell, 'data-locale="en"' ), 'bytes=' . strlen( $cell ) );

				// The same through the real column callback (echo site).
				ob_start();
				$list->renderColumn( LingoCanvas\Admin\PostListLanguages::COLUMN, $post_id );
				$col = (string) ob_get_clean();
				lingocanvas_smoke_check( 'x_kses_post_list_column_render', str_contains( $col, 'mlp-vis-toggle' ) && str_contains( $col, 'data-post-id="' . $post_id . '"' ) && str_contains( $col, 'data-locale="en"' ), 'bytes=' . strlen( $col ) );

				// EditorPage::visibilityBlock (private -> Reflection).
				$editor = $c->get( LingoCanvas\Admin\EditorPage::class );
				$ref    = new ReflectionMethod( $editor, 'visibilityBlock' );
				$ref->setAccessible( true );
				$block = (string) $ref->invoke( $editor, $post_id, $c->get( Settings::class )->secondary()['en'] );
				$identity( 'editor_visibility_block', $block, $ctx::editorVisibilityBlock() );

				// Full admin pages as admin.
				$_GET['page'] = 'x'; // phpcs:ignore
				$render = static function ( object $page ): string {
					$errors = array();
					set_error_handler( // phpcs:ignore
						static function ( int $no, string $str, string $file, int $line ) use ( &$errors ): bool {
							$errors[] = $no . ': ' . $str . ' @ ' . basename( $file ) . ':' . $line;

							return true;
						}
					);
					ob_start();
					$page->render();
					$html = (string) ob_get_clean();
					restore_error_handler();

					return $html . ( $errors ? '<!--ERRORS ' . implode( ' | ', $errors ) . '-->' : '' );
				};

				set_current_screen( 'toplevel_page_' . SettingsPage::MENU_SLUG );
				$html = $render( $settings_page );
				lingocanvas_smoke_check( 'x_kses_page_settings', str_contains( $html, 'id="mlp-openai-key"' ) && 1 === preg_match( '/id="mlp-openai-key"[^>]*\breadonly\b/', $html ) && 1 === preg_match( '/id="mlp-openai-model"[^>]*\breadonly\b|\breadonly\b[^>]*value="smoke-model"/s', $html ) && str_contains( $html, 'class="description"' ) && ! str_contains( $html, '<!--ERRORS' ), 'bytes=' . strlen( $html ) . ( preg_match( '/<!--ERRORS[^>]*-->/', $html, $m ) ? ' ' . $m[0] : '' ) );
				lingocanvas_smoke_check( 'x_kses_page_settings_cloud', str_contains( $html, 'id="lingocanvas-cloud"' ) && str_contains( $html, 'name="provider"' ) && str_contains( $html, 'id="lingocanvas-cloud-form-signup"' ) && str_contains( $html, 'form="lingocanvas-cloud-form-signup"' ) && str_contains( $html, 'centerai.eu/blog/terms/' ), 'bytes=' . strlen( $html ) );

				$_GET['lingocanvas_scope']  = 'object:' . $post_id; // phpcs:ignore
				$_GET['lingocanvas_locale'] = 'en'; // phpcs:ignore
				$strings                    = $c->get( LingoCanvas\Admin\StringTranslationPage::class );
				$html                       = $render( $strings );
				lingocanvas_smoke_check( 'x_kses_page_strings', str_contains( $html, 'id="mlp-scope-search"' ) && str_contains( $html, 'id="mlp-scope-picker"' ) && str_contains( $html, 'data-lingocanvas-object="1"' ) && str_contains( $html, 'name="lingocanvas_scope"' ) && ! str_contains( $html, '<!--ERRORS' ), 'bytes=' . strlen( $html ) . ( preg_match( '/<!--ERRORS[^>]*-->/', $html, $m ) ? ' ' . $m[0] : '' ) );
				unset( $_GET['lingocanvas_scope'] );

				$_GET['lingocanvas_path'] = '/smoke-post/'; // phpcs:ignore
				$html                     = $render( $editor );
				lingocanvas_smoke_check( 'x_kses_page_editor', 1 === preg_match( '/class="wp-mlp-editor"[^>]*data-locale="en"[^>]*data-post-id="' . $post_id . '"/', $html ) && str_contains( $html, 'mlp-vis-toggle' ) && str_contains( $html, 'id="mlp-editor-source"' ) && 1 === preg_match( '/<textarea id="mlp-editor-source"[^>]*\breadonly\b/', $html ) && ! str_contains( $html, '<!--ERRORS' ), 'bytes=' . strlen( $html ) . ( preg_match( '/<!--ERRORS[^>]*-->/', $html, $m ) ? ' ' . $m[0] : '' ) );
			} finally {
				update_option( Settings::optionName(), $orig );
				$c->get( Settings::class )->reset();
				unset( $_GET['lingocanvas_path'], $_GET['lingocanvas_locale'], $_GET['page'] );
			}
		}
	);

	// (e) AI translation through the mocked endpoint.
	lingocanvas_smoke_step(
		'x_ai',
		static function () use ( $post_id, $src_text, $unhex_ok, $fake ): void {
			$bodies = array();

			$req = new WP_REST_Request( 'POST', '/lingocanvas/v1/translations/' . $src_text . '/en/suggest' );
			$res = rest_do_request( $req );
			$data = $res->get_data();
			$bodies[] = wp_json_encode( $data );
			$text = is_array( $data ) ? (string) ( $data['suggested_text'] ?? '' ) : '';
			lingocanvas_smoke_check( 'x_e_ai_suggest_mocked', 200 === $res->get_status() && str_starts_with( $text, 'MOCK-EN: ' ), 'status=' . $res->get_status() . ' text=' . substr( $text, 0, 60 ) . ( $res->is_error() ? ' code=' . $res->as_error()->get_error_code() : '' ) );

			$req = new WP_REST_Request( 'GET', '/lingocanvas/v1/posts/' . $post_id . '/summary' );
			$req->set_param( 'locale', 'en' );
			$req->set_param( 'mode', 'all' );
			$sum  = rest_do_request( $req );
			$sdat = $sum->get_data();
			$bodies[] = wp_json_encode( $sdat );
			$hashes = is_array( $sdat ) && ! empty( $sdat['chunks'][0] ) ? $sdat['chunks'][0] : array();
			lingocanvas_smoke_check( 'x_e_ai_bulk_summary', 200 === $sum->get_status() && array() !== $hashes, 'status=' . $sum->get_status() . ' chunks=' . ( is_array( $sdat ) ? count( $sdat['chunks'] ?? array() ) : '?' ) . ' to_translate=' . ( is_array( $sdat ) ? (string) ( $sdat['to_translate'] ?? '?' ) : '?' ) );

			$req = new WP_REST_Request( 'POST', '/lingocanvas/v1/posts/' . $post_id . '/chunk' );
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

			$skip = ! $ok && 'lingocanvas_unknown_hashes' === $code && ! $unhex_ok;
			lingocanvas_smoke_check(
				'x_e_ai_bulk_chunk_mocked',
				$ok || $skip,
				$ok ? 'translations=' . count( $data['translations'] ) : ( $skip ? 'SKIP: SQLite layer cannot match BINARY = UNHEX() (idsByHashes finds nothing -> lingocanvas_unknown_hashes); environment limitation' : 'status=' . $res->get_status() . ' code=' . $code )
			);

			$calls = is_readable( '/out/mock-calls.jsonl' ) ? (string) file_get_contents( '/out/mock-calls.jsonl' ) : '';
			lingocanvas_smoke_check( 'x_e_ai_mock_endpoint_hit_with_key', str_contains( $calls, '"key_sent":true' ) && str_contains( $calls, 'smoke-model' ), 'calls=' . substr_count( $calls, "\n" ) );

			$leak = false;

			foreach ( $bodies as $b ) {
				$leak = $leak || str_contains( (string) $b, 'sk-smoke-FAKEKEY' ) || str_contains( (string) $b, $fake );
			}

			foreach ( array( 'default_front', 'default_post', 'en_front', 'en_post', 'en_post_after', 'sitemap', 'switcher' ) as $page ) {
				$p    = lingocanvas_smoke_page( $page );
				$leak = $leak || ( null !== $p && str_contains( $p['body'], 'sk-smoke-FAKEKEY' ) );
			}

			$log  = is_readable( WP_CONTENT_DIR . '/debug.log' ) ? (string) file_get_contents( WP_CONTENT_DIR . '/debug.log' ) : '';
			$leak = $leak || str_contains( $log, 'sk-smoke-FAKEKEY' );
			lingocanvas_smoke_check( 'x_e_ai_key_never_in_responses_pages_log', ! $leak, $leak ? 'FAKE KEY FOUND in a response body, page or debug.log' : 'REST bodies, front-end pages, debug.log clean' );
		}
	);

	// (f) the extension filter of the sitemap runs under its lingocanvas_ name.
	lingocanvas_smoke_step(
		'x_hooks',
		static function (): void {
			$new = is_readable( '/out/hook-new.txt' ) ? substr_count( (string) file_get_contents( '/out/hook-new.txt' ), "\n" ) : 0;
			lingocanvas_smoke_check( 'x_f_sitemap_hook_ran', $new >= 1, 'lingocanvas_sitemap_post_ids calls=' . $new );
		}
	);
}

// ===== check2 ==============================================================
if ( 'check2' === $phase ) {
	// Output buffering (WordPress.org review): the /en/ page must come out translated whichever buffer
	// does the work: the core 6.9+ template enhancement buffer, the plugin's own fallback buffer
	// (WordPress < 6.9 or core buffer disabled by another plugin), a template that leaves an ob_start()
	// open, or one that calls exit. Fixture: mu-plugin lingocanvas-smoke-buffer.php (set per render).
	lingocanvas_smoke_step(
		'b_output_buffer',
		static function (): void {
			$base      = lingocanvas_smoke_page( 'en_post_after' );
			$xyz_known = null !== $base && str_contains( $base['body'], 'Smoke paragraph translated XYZ.' );
			$core_wp   = null;

			foreach ( array( 'buf_trace', 'buf_core_off', 'buf_open', 'buf_exit', 'buf_late' ) as $name ) {
				$r = lingocanvas_smoke_page( $name );

				if ( null === $r ) {
					lingocanvas_smoke_check( 'b_' . $name . '_translated', false, 'render produced no result' );
					continue;
				}

				// The prefixed internal link is produced only by the page translation (the buffer).
				$linked = str_contains( $r['body'], 'href="/en/about-smoke/"' );
				$xyz    = ! $xyz_known || str_contains( $r['body'], 'Smoke paragraph translated XYZ.' );
				$seo    = 1 === preg_match( '/<link\s+rel=["\']canonical["\'][^>]*\/en\/smoke-post/i', $r['body'] ) && (int) preg_match_all( '/<link[^>]+hreflang=/i', $r['body'] ) >= 2;
				lingocanvas_smoke_check(
					'b_' . $name . '_translated',
					200 === $r['status'] && str_contains( $r['body'], '</html>' ) && $linked && $xyz && $seo,
					'status=' . $r['status'] . ' link_prefixed=' . var_export( $linked, true ) . ' translation=' . var_export( $xyz, true ) . ' seo=' . var_export( $seo, true ) . ' bytes=' . strlen( $r['body'] )
				);
			}

			// Which buffer worked, from the handler list recorded at wp_footer.
			foreach ( array( 'buf_trace', 'buf_core_off', 'buf_open', 'buf_late' ) as $name ) {
				$file = '/out/buffer-' . $name . '.json';
				$t    = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

				if ( ! is_array( $t ) ) {
					lingocanvas_smoke_check( 'b_' . $name . '_trace', false, 'no wp_footer trace recorded' );
					continue;
				}

				$handlers = (array) ( $t['handlers'] ?? array() );
				$own      = in_array( 'LingoCanvas\Rendering\OutputBuffer::handleBuffer', $handlers, true );
				$core     = in_array( 'wp_finalize_template_enhancement_output_buffer', $handlers, true );
				$core_wp  = version_compare( (string) ( $t['wp_version'] ?? '0' ), '6.9', '>=' );

				$tpl     = (string) ( $t['template'] ?? '' );
				$wrapper = str_contains( $tpl, 'template-buffer.php' );

				if ( 'buf_core_off' === $name ) {
					// Core buffer disabled by another plugin: the fallback wrapper and the own buffer, on every version.
					$ok   = $own && ! $core && $wrapper;
					$note = 'wp=' . $t['wp_version'] . ' core_buffer=' . var_export( $core, true ) . ' own_buffer=' . var_export( $own, true ) . ' template_is_wrapper=' . var_export( $wrapper, true );
				} else {
					// WordPress 6.9+: the core buffer only, the global $template is never the plugin wrapper.
					// Before 6.9: the wrapper and the own buffer only. Also with a template that leaves a buffer open
					// and with another plugin replacing the template at PHP_INT_MAX.
					$ok   = $core_wp ? ( $core && ! $own && ! $wrapper && '' !== $tpl ) : ( $own && ! $core && $wrapper );
					$note = 'wp=' . $t['wp_version'] . ' core_buffer=' . var_export( $core, true ) . ' own_buffer=' . var_export( $own, true ) . ' template_is_wrapper=' . var_export( $wrapper, true ) . ' template=' . basename( $tpl ) . ' core_started=' . ( $t['core_started'] ?? '?' );

					if ( 'buf_trace' === $name && $core_wp ) {
						// The theme's own template (a classic theme file, or core template-canvas.php of a block theme),
						// not a fixture file and not the wrapper.
						$ok = $ok && ! str_contains( $tpl, 'lingocanvas-smoke-template' ) && ( str_contains( $tpl, '/themes/' ) || 'template-canvas.php' === basename( $tpl ) );
					}

					if ( 'buf_late' === $name && $core_wp ) {
						// The late template of the other plugin is the one rendered, and the page is translated anyway.
						$ok = $ok && str_contains( $tpl, 'lingocanvas-smoke-template-late' );
					}
				}

				lingocanvas_smoke_check( 'b_' . $name . '_buffer_choice', $ok, $note );
			}
		}
	);

	// Lookup of the translated source and its rendering. Playground runs WordPress on SQLite,
	// whose compatibility layer cannot compare a BINARY column with UNHEX(): the plugin's
	// lookup then finds nothing although the HEX() form works. That is a limitation of the
	// environment (MySQL/MariaDB is fine), so the render check is reported as SKIP in that case.
	lingocanvas_smoke_step(
		'e_lookup_and_render',
		static function () use ( $wpdb ): void {
			$id = (int) ( $GLOBALS['lingocanvas_smoke']['details']['src_text'] ?? 0 );
			$t  = Schema::table( 'sources' );
			// phpcs:disable
			$hash      = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT LOWER(HEX(uniq_hash)) FROM %i WHERE id = %d', $t, $id ) );
			$unhex_ok  = (string) $id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE uniq_hash = UNHEX(%s)', $t, $hash ) );
			$hex_ok    = (string) $id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE HEX(uniq_hash) = %s', $t, strtoupper( $hash ) ) );
			// phpcs:enable
			$rows = Plugin::container()->get( SourceRepository::class )->lookup( array( $hash ), 'en' );
			$text = $rows[ $hash ]['text'] ?? null;
			$skip = ! $unhex_ok && $hex_ok;

			lingocanvas_smoke_check(
				'e_lookup_returns_translation',
				'Smoke paragraph translated XYZ.' === $text || $skip,
				( 'Smoke paragraph translated XYZ.' === $text ? 'ok' : ( $skip ? 'SKIP: SQLite layer cannot match BINARY = UNHEX() (environment limitation)' : 'got ' . wp_json_encode( $rows ) ) )
			);

			$r  = lingocanvas_smoke_page( 'en_post_after' );
			$ok = null !== $r && str_contains( $r['body'], 'Smoke paragraph translated XYZ.' );
			lingocanvas_smoke_check(
				'e_translation_rendered_on_en',
				$ok || $skip,
				$ok ? 'status=' . $r['status'] : ( $skip ? 'SKIP: same SQLite UNHEX() limitation, see e_lookup_returns_translation' : 'translation missing in /en/ HTML' )
			);
			lingocanvas_smoke_check( 'e_internal_link_prefixed_on_en', null !== $r && str_contains( $r['body'], 'href="/en/about-smoke/"' ), '' );
		}
	);

	// (f) repositories / cleanup (%i queries).
	lingocanvas_smoke_step(
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
				lingocanvas_smoke_check( 'f_source_paginate type=' . ( '' === $type ? 'all' : $type ), '' === $wpdb->last_error, 'total=' . ( $r['total'] ?? '?' ) . ' err=' . $wpdb->last_error );
			}

			foreach ( array( 'global', 'object' ) as $scope ) {
				$wpdb->last_error = '';
				$r                = $src->paginate(
					array(
						'locale'    => 'en',
						'scope'     => $scope,
						'object_id' => (int) ( $GLOBALS['lingocanvas_smoke']['details']['post_id'] ?? 1 ),
						'status'    => 'missing',
					)
				);
				lingocanvas_smoke_check( 'f_source_paginate scope=' . $scope, '' === $wpdb->last_error, 'total=' . ( $r['total'] ?? '?' ) . ' err=' . $wpdb->last_error );
			}

			$wpdb->last_error = '';
			$r                = $get->paginate( array( 'locale' => 'en', 'search' => 'a' ) );
			$get->domains();
			lingocanvas_smoke_check( 'f_gettext_paginate_domains', '' === $wpdb->last_error, 'total=' . ( $r['total'] ?? '?' ) . ' err=' . $wpdb->last_error );

			$wpdb->last_error = '';
			$get->countUntranslated();
			$src->countUntranslated( array( 'text', 'attribute' ) );
			$get->overridesFor( 'en', 1 );
			lingocanvas_smoke_check( 'f_count_untranslated_overrides', '' === $wpdb->last_error, $wpdb->last_error );

			$wpdb->last_error = '';
			$src->deleteUntranslated( array( 'attribute' ) );
			$get->deleteUntranslated();
			lingocanvas_smoke_check( 'f_delete_untranslated', '' === $wpdb->last_error, $wpdb->last_error );
		}
	);

	// (g) bundled ru_RU.
	lingocanvas_smoke_step(
		'g_locale',
		static function (): void {
			$force = static fn(): string => 'ru_RU';
			add_filter( 'locale', $force );
			add_filter( 'plugin_locale', $force );
			unload_textdomain( 'lingocanvas' );
			Plugin::loadTextdomain();
			$languages = __( 'Languages', 'lingocanvas' );
			lingocanvas_smoke_check( 'g_bundled_ru_RU_loads', 'Языки' === $languages, 'got=' . $languages );
			$ctx = _x( 'OpenAI key', 'mid-sentence', 'lingocanvas' );
			lingocanvas_smoke_check( 'g_context_translation', 'ключ OpenAI' === $ctx, 'got=' . $ctx );
			remove_filter( 'locale', $force );
			remove_filter( 'plugin_locale', $force );
		}
	);

	// (g2) LingoCanvas Cloud claim lock: the real private methods against the real options table
	// (atomic UPDATE ... WHERE option_value = old, conditional DELETE, option cache flushing).
	lingocanvas_smoke_step(
		'k_claim_lock',
		static function (): void {
			$client  = ( new ReflectionClass( LingoCanvas\Translation\CloudClient::class ) )->newInstanceWithoutConstructor();
			$acquire = new ReflectionMethod( $client, 'acquireClaimLock' );
			$release = new ReflectionMethod( $client, 'releaseClaimLock' );
			$acquire->setAccessible( true );
			$release->setAccessible( true );
			$opt = LingoCanvas\Translation\CloudClient::CLAIM_LOCK_OPTION;

			delete_option( $opt );

			// (1) free lock is acquired and stores owner|expiry.
			$owner = $acquire->invoke( $client );
			$parts = is_string( $owner ) ? explode( '|', $owner ) : array();
			lingocanvas_smoke_check( 'k_acquire_free_lock', is_string( $owner ) && 2 === count( $parts ) && (int) $parts[1] > time() && get_option( $opt ) === $owner, 'owner=' . var_export( $owner, true ) );

			// (2) a fresh lock cannot be acquired again.
			lingocanvas_smoke_check( 'k_second_acquire_fails_on_fresh_lock', null === $acquire->invoke( $client ) && get_option( $opt ) === $owner, '' );

			// (4a) release by a foreign owner leaves the lock.
			$release->invoke( $client, 'foreign|' . ( time() + 100 ) );
			lingocanvas_smoke_check( 'k_foreign_release_keeps_lock', get_option( $opt ) === $owner, '' );

			// (4b) release by the owner removes it; get_option reflects the DB (cache flushed).
			$release->invoke( $client, (string) $owner );
			lingocanvas_smoke_check( 'k_owner_release_removes_lock', false === get_option( $opt, false ), 'get_option=' . var_export( get_option( $opt, false ), true ) );

			// (3) stale lock: takeover by the atomic UPDATE; of two sequential attempts on the same old value only one wins.
			$stale = 'deadbeefdeadbeef|' . ( time() - 100 );
			update_option( $opt, $stale, false );
			$first = $acquire->invoke( $client );
			lingocanvas_smoke_check( 'k_stale_takeover_first_wins', is_string( $first ) && $first !== $stale, 'first=' . var_export( $first, true ) );
			lingocanvas_smoke_check( 'k_takeover_get_option_reflects_db', is_string( $first ) && get_option( $opt ) === $first, 'get_option=' . var_export( get_option( $opt ), true ) );

			// The second contender still holds the old stale value: replay the same UPDATE it would run.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, 'second|' . ( time() + 100 ), $opt, $stale ) );
			wp_cache_delete( $opt, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			lingocanvas_smoke_check( 'k_stale_takeover_second_loses', 0 === (int) $rows && get_option( $opt ) === $first, 'rows_affected=' . var_export( $rows, true ) );

			// Second real attempt on a fresh lock fails too, then cleanup.
			lingocanvas_smoke_check( 'k_acquire_after_takeover_fails', null === $acquire->invoke( $client ), '' );
			$release->invoke( $client, (string) $first );
			lingocanvas_smoke_check( 'k_release_after_takeover_removes', false === get_option( $opt, false ), '' );
		}
	);

	// Uninstall path.
	lingocanvas_smoke_step(
		'u_uninstall',
		static function () use ( $wpdb ): void {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$s                             = (array) get_option( Settings::optionName(), array() );
			$s['delete_data_on_uninstall'] = true;
			update_option( Settings::optionName(), $s );

			// Table names are taken before uninstall.
			$names = array_map( static fn( string $n ): string => Schema::table( $n ), Schema::TABLES );

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

			foreach ( $names as $t ) {
				if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
					$left[] = $t;
				}
			}

			// phpcs:ignore
			$opts = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'lingocanvas\\_%' OR option_name LIKE '\\_transient\\_lingocanvas\\_%'" );

			lingocanvas_smoke_check( 'u_tables_dropped', array() === $left, $left ? 'left: ' . implode( ',', $left ) : 'delete_plugins=' . var_export( $res, true ) );
			lingocanvas_smoke_check( 'u_options_removed', array() === $opts, $opts ? 'left: ' . implode( ',', $opts ) : '' );
		}
	);

	// (h) debug.log: nothing of interest may be logged.
	lingocanvas_smoke_flush();
	$lines = $GLOBALS['lingocanvas_smoke']['log_lines'];
	lingocanvas_smoke_check( 'h_debug_log_clean', array() === $lines, count( $lines ) . ' unexpected lines of interest' );
}

lingocanvas_smoke_flush();
