<?php
/**
 * Smoke-test body of scenarios S2-S5 (migration from the old "WP Multilang Press" plugin,
 * folder wp-mlp/, to LingoCanvas, folder lingocanvas/), executed inside WordPress Playground
 * by blueprints generated with bin/smoke-test-blueprint.cjs (see bin/smoke-test.sh).
 * Not part of the plugin ZIP.
 *
 * Every phase runs in its own PHP request (blueprint runPHP step); phases are chosen with
 * $GLOBALS['MLP_SMOKE_PHASE']. State accumulates in /out/smoke.json.
 *
 *   old_activate      activate the OLD plugin (and add a second language to its settings)
 *   old_data          posts, rewrite flush, _mlp_visible_* meta (old plugin is booted)
 *   old_translate     two manual translations through the OLD REST route, snapshot of the data
 *   migrate     (S2,S4) activate_plugin('lingocanvas/lingocanvas.php') in ONE request
 *   after_migrate (S2,S4) next request: pending option cleared, data intact, routes once
 *   after_render  (S2)  front-end pages after migration + debug.log
 *   both_force    (S3)  active_plugins := both plugins
 *   both_check    (S3)  paused notice, old code only, no duplicates
 *   delete_old    (S4)  delete the old plugin with delete_data_on_uninstall = true
 *   after_delete_old (S4) data still there, LingoCanvas works
 *   delete_new    (S5)  delete LingoCanvas while the old plugin is active
 *   after_delete_new (S5) data still there, old plugin works
 */

use WpMlp\Plugin;

require __DIR__ . '/smoke-test-lib.php';

const MLP_SMOKE_OLD  = 'wp-mlp/wp-mlp.php';
const MLP_SMOKE_NEW  = 'lingocanvas/lingocanvas.php';
const MLP_SMOKE_BODY = 'Smoke paragraph hello world';

global $wpdb;

$phase = (string) ( $GLOBALS['MLP_SMOKE_PHASE'] ?? '' );

if ( 'old_activate' === $phase ) {
	@unlink( MLP_SMOKE_FILE ); // phpcs:ignore
	@unlink( WP_CONTENT_DIR . '/debug.log' ); // phpcs:ignore
	$GLOBALS['mlp_smoke'] = array(
		'checks'    => array(),
		'details'   => array(),
		'log_lines' => array(),
	);
}

wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

/**
 * Normalised path of the file that declares WpMlp\Plugin (empty if not loaded).
 */
function mlp_smoke_plugin_file(): string {
	return class_exists( Plugin::class, false ) ? str_replace( '\\', '/', ( new ReflectionClass( Plugin::class ) )->getFileName() ) : '';
}

/**
 * Handler count of every mlp/v1 REST route (a plugin booted twice would double it).
 *
 * @return array<string,int>
 */
function mlp_smoke_route_signature(): array {
	$sig = array();

	foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
		if ( 0 === strpos( $route, '/mlp/v1' ) ) {
			$sig[ $route ] = count( $handlers );
		}
	}

	ksort( $sig );

	return $sig;
}

/**
 * Id of the collected source string of the smoke post.
 */
function mlp_smoke_source_id(): int {
	global $wpdb;

	// phpcs:ignore
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_text LIKE %s ORDER BY id LIMIT 1', $wpdb->prefix . 'mlp_sources', $wpdb->esc_like( MLP_SMOKE_BODY ) . '%' ) );
}

/**
 * GET /mlp/v1/sources/<id> and the saved text for a locale.
 *
 * @return array{status:int, text:string, body:string}
 */
function mlp_smoke_rest_translation( int $source_id, string $locale ): array {
	$res  = rest_do_request( new WP_REST_Request( 'GET', '/mlp/v1/sources/' . $source_id ) );
	$data = $res->get_data();

	return array(
		'status' => $res->get_status(),
		'text'   => is_array( $data ) ? (string) ( $data['translations'][ $locale ]['translated_text'] ?? '' ) : '',
		'body'   => (string) wp_json_encode( $data ),
	);
}

/**
 * Compares the current data with the snapshot recorded by old_translate.
 */
function mlp_smoke_check_data_intact( string $prefix, string $note = '' ): void {
	$before = $GLOBALS['mlp_smoke']['details']['snap_before'] ?? array();
	$now    = mlp_smoke_snapshot();
	$same_c = ( $before['counts'] ?? null ) === $now['counts'] && array() !== $now['counts'];

	mlp_smoke_check( $prefix . '_mlp_table_row_counts_unchanged', $same_c, wp_json_encode( $now['counts'] ) . ( $same_c ? '' : ' expected ' . wp_json_encode( $before['counts'] ?? null ) ) . $note );
	mlp_smoke_check( $prefix . '_mlp_settings_hash_unchanged', ( $before['settings_hash'] ?? '' ) === $now['settings_hash'], 'md5=' . $now['settings_hash'] );
}

/**
 * Stores the pre-existing log position so later checks can look at new lines only is not needed:
 * the log is truncated in old_activate, so any bad line in it belongs to this scenario.
 */
function mlp_smoke_check_log( string $name ): void {
	$bad = mlp_smoke_log_bad();
	mlp_smoke_check( $name, array() === $bad, array() === $bad ? 'debug.log has no fatal/warning/notice/deprecated/redeclare lines' : count( $bad ) . ' lines, first: ' . substr( $bad[0], 0, 300 ) );
}

// ===== old plugin: activate ================================================
if ( 'old_activate' === $phase ) {
	mlp_smoke_step(
		'old_activate',
		static function (): void {
			$r = activate_plugin( MLP_SMOKE_OLD );
			mlp_smoke_check( 'o_old_plugin_activated', ! is_wp_error( $r ) && is_plugin_active( MLP_SMOKE_OLD ), is_wp_error( $r ) ? $r->get_error_message() : 'active_plugins=' . implode( ',', (array) get_option( 'active_plugins' ) ) );

			$s                            = (array) get_option( 'mlp_settings', array() );
			$s['discover_from_visitors']  = true;
			$s['untranslated_posts_mode'] = 'all';
			$s['languages']['de']         = array(
				'locale'    => 'de',
				'slug'      => 'de',
				'label'     => 'Deutsch',
				'wp_locale' => 'de_DE',
				'status'    => 'published',
			);
			update_option( 'mlp_settings', $s );
			mlp_smoke_check( 'o_old_second_language_saved', isset( ( (array) get_option( 'mlp_settings' ) )['languages']['de'] ), '' );
		}
	);
}

// ===== old plugin: data ====================================================
if ( 'old_data' === $phase ) {
	mlp_smoke_step(
		'old_data',
		static function () use ( $wpdb ): void {
			mlp_smoke_check( 'o_old_plugin_class_from_wp_mlp_dir', str_contains( mlp_smoke_plugin_file(), '/plugins/wp-mlp/src/' ), mlp_smoke_plugin_file() );
			mlp_smoke_check( 'o_old_constants', defined( 'WP_MLP_FILE' ) && ! defined( 'LINGOCANVAS_FILE' ), '' );

			$about   = wp_insert_post(
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
					'post_name'    => 'smoke-post',
					'post_status'  => 'publish',
					'post_type'    => 'post',
					'post_content' => '<!-- wp:paragraph --><p>' . MLP_SMOKE_BODY . '. <a href="/about-smoke/">About link text</a></p><!-- /wp:paragraph -->',
				)
			);
			update_post_meta( $post_id, '_mlp_visible_en', '1' );
			flush_rewrite_rules();
			mlp_smoke_check( 'o_old_post_created', $post_id > 0 && $about > 0, 'post_id=' . $post_id );
			$GLOBALS['mlp_smoke']['details']['post_id'] = $post_id;

			$rules = (array) get_option( 'rewrite_rules', array() );
			mlp_smoke_check( 'o_old_rewrite_rules_have_en_de', isset( $rules['en/?$'], $rules['de/?$'] ), 'rules=' . count( $rules ) );

			// The render steps need the site host, or WordPress canonical-redirects to it.
			file_put_contents( '/out/site-host.txt', (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) );
		}
	);
}

// ===== old plugin: translations + snapshot =================================
if ( 'old_translate' === $phase ) {
	mlp_smoke_step(
		'old_translate',
		static function (): void {
			$id = mlp_smoke_source_id();
			mlp_smoke_check( 'o_old_string_collected_by_render', $id > 0, 'source_id=' . $id );

			if ( $id <= 0 ) {
				return;
			}

			$GLOBALS['mlp_smoke']['details']['source_id'] = $id;

			foreach ( array(
				'en' => 'Migrated translation ABC.',
				'de' => 'Migrierte Uebersetzung ABC.',
			) as $locale => $text ) {
				$req = new WP_REST_Request( 'PUT', '/mlp/v1/translations/' . $id . '/' . $locale );
				$req->set_param( 'translated_text', $text );
				$res = rest_do_request( $req );
				mlp_smoke_check( 'o_old_save_translation_' . $locale, 200 === $res->get_status(), 'status=' . $res->get_status() );
			}

			$GLOBALS['mlp_smoke']['details']['texts']      = array(
				'en' => 'Migrated translation ABC.',
				'de' => 'Migrierte Uebersetzung ABC.',
			);
			$GLOBALS['mlp_smoke']['details']['routes_old'] = mlp_smoke_route_signature();
			$GLOBALS['mlp_smoke']['details']['snap_before'] = mlp_smoke_snapshot();
			$GLOBALS['mlp_smoke']['details']['visible_en']  = get_post_meta( (int) $GLOBALS['mlp_smoke']['details']['post_id'], '_mlp_visible_en', true );

			$counts = $GLOBALS['mlp_smoke']['details']['snap_before']['counts'];
			mlp_smoke_check( 'o_old_data_recorded', ( $counts[ $GLOBALS['wpdb']->prefix . 'mlp_translations' ] ?? 0 ) >= 2 && ( $counts[ $GLOBALS['wpdb']->prefix . 'mlp_sources' ] ?? 0 ) >= 1 && array() !== $GLOBALS['mlp_smoke']['details']['routes_old'], wp_json_encode( $counts ) . ' routes=' . count( $GLOBALS['mlp_smoke']['details']['routes_old'] ) );
			mlp_smoke_check( 'o_old_pending_option_absent', false === get_option( 'lingocanvas_pending_activation', false ), '' );
		}
	);
}

// ===== migrate: activate LingoCanvas, old plugin still loaded ==============
if ( 'migrate' === $phase ) {
	mlp_smoke_step(
		'migrate',
		static function (): void {
			mlp_smoke_check( 'm_precondition_old_active_new_inactive', is_plugin_active( MLP_SMOKE_OLD ) && ! is_plugin_active( MLP_SMOKE_NEW ), '' );

			$r = activate_plugin( MLP_SMOKE_NEW );
			mlp_smoke_check( 'm_activate_plugin_no_error', ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : 'activate_plugin returned null' );
			mlp_smoke_check( 'm_old_plugin_now_inactive', ! is_plugin_active( MLP_SMOKE_OLD ), 'active_plugins=' . implode( ',', (array) get_option( 'active_plugins' ) ) );
			mlp_smoke_check( 'm_new_plugin_active', is_plugin_active( MLP_SMOKE_NEW ), '' );
			mlp_smoke_check( 'm_pending_option_set', (bool) get_option( 'lingocanvas_pending_activation', false ), 'value=' . var_export( get_option( 'lingocanvas_pending_activation', false ), true ) );
			mlp_smoke_check( 'm_no_new_boot_in_this_request', ! defined( 'LINGOCANVAS_FILE' ) && str_contains( mlp_smoke_plugin_file(), '/plugins/wp-mlp/src/' ), 'plugin_class=' . mlp_smoke_plugin_file() );
			mlp_smoke_check_log( 'm_debug_log_clean_after_activation' );
		}
	);
}

// ===== after migrate: the next request =====================================
if ( 'after_migrate' === $phase ) {
	mlp_smoke_step(
		'after_migrate',
		static function (): void {
			$d = $GLOBALS['mlp_smoke']['details'];

			mlp_smoke_check( 'a_pending_option_cleared', false === get_option( 'lingocanvas_pending_activation', false ), 'value=' . var_export( get_option( 'lingocanvas_pending_activation', false ), true ) );
			mlp_smoke_check( 'a_plugins_active_state', is_plugin_active( MLP_SMOKE_NEW ) && ! is_plugin_active( MLP_SMOKE_OLD ), 'active_plugins=' . implode( ',', (array) get_option( 'active_plugins' ) ) );
			mlp_smoke_check( 'a_lingocanvas_booted', defined( 'LINGOCANVAS_FILE' ) && ! defined( 'WP_MLP_FILE' ), '' );
			mlp_smoke_check( 'a_plugin_class_from_lingocanvas_dir', str_contains( mlp_smoke_plugin_file(), '/plugins/lingocanvas/src/' ), mlp_smoke_plugin_file() );

			mlp_smoke_check_data_intact( 'a' );

			foreach ( array(
				'en' => 'Migrated translation ABC.',
				'de' => 'Migrierte Uebersetzung ABC.',
			) as $locale => $text ) {
				$r = mlp_smoke_rest_translation( (int) $d['source_id'], $locale );
				mlp_smoke_check( 'a_saved_translation_readable_via_rest_' . $locale, 200 === $r['status'] && $text === $r['text'], 'status=' . $r['status'] . ' text=' . $r['text'] );
			}

			$sig = mlp_smoke_route_signature();
			mlp_smoke_check( 'a_rest_routes_registered_once', array() !== $sig && $sig === ( $d['routes_old'] ?? null ), count( $sig ) . ' routes; ' . ( $sig === ( $d['routes_old'] ?? null ) ? 'handler counts equal the old plugin' : 'now ' . wp_json_encode( $sig ) . ' old ' . wp_json_encode( $d['routes_old'] ?? null ) ) );

			$rules = (array) get_option( 'rewrite_rules', array() );
			mlp_smoke_check( 'a_rewrite_rules_have_language_prefixes', isset( $rules['en/?$'], $rules['de/?$'], $rules['en/(.?.+?)(?:/([0-9]+))?/?$'] ), 'en=' . ( isset( $rules['en/?$'] ) ? 'y' : 'n' ) . ' de=' . ( isset( $rules['de/?$'] ) ? 'y' : 'n' ) . ' rules=' . count( $rules ) );
			mlp_smoke_check( 'a_visibility_meta_kept', (string) $d['visible_en'] === (string) get_post_meta( (int) $d['post_id'], '_mlp_visible_en', true ) && '1' === (string) get_post_meta( (int) $d['post_id'], '_mlp_visible_en', true ), '' );
			mlp_smoke_check( 'a_schema_version_option', (int) get_option( 'mlp_db_version', 0 ) > 0, 'mlp_db_version=' . get_option( 'mlp_db_version' ) );
			mlp_smoke_check_log( 'a_debug_log_clean' );
		}
	);
}

// ===== after migrate: rendered pages =======================================
if ( 'after_render' === $phase ) {
	mlp_smoke_step(
		'after_render',
		static function (): void {
			foreach ( array(
				'new_default_front' => 3,
				'new_en_front'      => 3,
			) as $name => $min ) {
				$r = mlp_smoke_page( $name );

				if ( null === $r ) {
					mlp_smoke_check( 'r_' . $name, false, 'render produced no result' );
					continue;
				}

				$hreflang = (int) preg_match_all( '/<link[^>]+hreflang=/i', $r['body'] );
				mlp_smoke_check( 'r_' . $name . '_renders_with_hreflang', 200 === $r['status'] && str_contains( $r['body'], '</html>' ) && $hreflang >= $min, 'status=' . $r['status'] . ' bytes=' . strlen( $r['body'] ) . ' hreflang_tags=' . $hreflang . ( $r['location'] ? ' location=' . $r['location'] : '' ) );
			}

			$r = mlp_smoke_page( 'new_en_front' );
			mlp_smoke_check( 'r_en_front_html_lang_en', null !== $r && (bool) preg_match( '/<html[^>]+lang="en/i', $r['body'] ), '' );

			$r = mlp_smoke_page( 'new_en_post' );
			$ok = null !== $r && 200 === $r['status'] && str_contains( $r['body'], 'Migrated translation ABC.' );
			global $wpdb;
			$t        = $wpdb->prefix . 'mlp_sources';
			$id       = (int) ( $GLOBALS['mlp_smoke']['details']['source_id'] ?? 0 );
			$hash     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT LOWER(HEX(uniq_hash)) FROM %i WHERE id = %d', $t, $id ) ); // phpcs:ignore
			$unhex_ok = (string) $id === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE uniq_hash = UNHEX(%s)', $t, $hash ) ); // phpcs:ignore
			mlp_smoke_check(
				'r_stored_translation_rendered_on_en',
				$ok || ! $unhex_ok,
				$ok ? 'status=' . $r['status'] : 'SKIP: SQLite layer cannot match BINARY = UNHEX() (environment limitation); persistence verified via row counts and REST GET'
			);
			mlp_smoke_check_log( 'r_debug_log_clean' );
		}
	);
}

// ===== S3: both plugins active =============================================
if ( 'both_force' === $phase ) {
	mlp_smoke_step(
		'both_force',
		static function (): void {
			update_option( 'active_plugins', array( MLP_SMOKE_NEW, MLP_SMOKE_OLD ) );
			mlp_smoke_check( 'b_both_forced_active', is_plugin_active( MLP_SMOKE_NEW ) && is_plugin_active( MLP_SMOKE_OLD ), 'active_plugins=' . implode( ',', (array) get_option( 'active_plugins' ) ) );
		}
	);
}

if ( 'both_check' === $phase ) {
	mlp_smoke_step(
		'both_check',
		static function (): void {
			$d = $GLOBALS['mlp_smoke']['details'];

			mlp_smoke_check( 'b_both_still_active_in_db', is_plugin_active( MLP_SMOKE_NEW ) && is_plugin_active( MLP_SMOKE_OLD ), '' );
			mlp_smoke_check( 'b_plugin_class_loaded_from_old_dir', str_contains( mlp_smoke_plugin_file(), '/plugins/wp-mlp/src/' ), mlp_smoke_plugin_file() );
			mlp_smoke_check( 'b_LINGOCANVAS_FILE_not_defined', ! defined( 'LINGOCANVAS_FILE' ) && defined( 'WP_MLP_FILE' ), '' );
			mlp_smoke_check( 'b_lingocanvas_main_file_was_executed', function_exists( 'lingocanvas_active_legacy_plugins' ) && array( MLP_SMOKE_OLD ) === lingocanvas_active_legacy_plugins(), '' );

			require_once ABSPATH . 'wp-admin/includes/admin.php';
			set_current_screen( 'dashboard' );
			ob_start();
			do_action( 'admin_notices' );
			$notices = (string) ob_get_clean();
			mlp_smoke_check( 'b_paused_notice_registered_and_rendered', str_contains( $notices, 'LingoCanvas is paused' ), 'notice html bytes=' . strlen( $notices ) );

			$sig = mlp_smoke_route_signature();
			mlp_smoke_check( 'b_rest_routes_registered_once', array() !== $sig && $sig === ( $d['routes_old'] ?? null ), count( $sig ) . ' routes' );

			foreach ( array( 'both_en_front' => '/en/', 'both_default_front' => '/', 'both_en_post' => '/en/smoke-post/' ) as $name => $uri ) {
				$r = mlp_smoke_page( $name );

				if ( null === $r ) {
					mlp_smoke_check( 'b_' . $name . '_hreflang_once', false, 'render produced no result' );
					continue;
				}

				preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']+)["\']/i', $r['body'], $m );
				$langs = $m[1] ?? array();
				preg_match_all( '/<link\s+rel=["\']canonical["\']/i', $r['body'], $canon );
				$ok = 200 === $r['status'] && count( $langs ) >= 3 && count( $langs ) === count( array_unique( $langs ) ) && 1 === substr_count( $r['body'], '</html>' ) && count( $canon[0] ) <= 1;
				mlp_smoke_check( 'b_' . $name . '_hreflang_once', $ok, 'status=' . $r['status'] . ' hreflang=' . implode( ',', $langs ) . ' canonical_tags=' . count( $canon[0] ) . ' html_close=' . substr_count( $r['body'], '</html>' ) );
			}

			mlp_smoke_check( 'b_pending_option_info', true, 'lingocanvas_pending_activation=' . var_export( get_option( 'lingocanvas_pending_activation', false ), true ) . ' (set while paused so the activation is finished once the old plugin is gone)' );
			// The rendered visitor pages may collect new strings (sources), so only the saved translations
			// and the settings must be unchanged here.
			$before = $d['snap_before'] ?? array();
			$now    = mlp_smoke_snapshot();
			$tkey   = $GLOBALS['wpdb']->prefix . 'mlp_translations';
			mlp_smoke_check( 'b_translations_and_settings_unchanged', ( $before['counts'][ $tkey ] ?? -1 ) === ( $now['counts'][ $tkey ] ?? -2 ) && ( $before['settings_hash'] ?? '' ) === $now['settings_hash'], 'translations=' . ( $now['counts'][ $tkey ] ?? '?' ) . ' sources ' . ( $before['counts'][ $GLOBALS['wpdb']->prefix . 'mlp_sources' ] ?? '?' ) . '->' . ( $now['counts'][ $GLOBALS['wpdb']->prefix . 'mlp_sources' ] ?? '?' ) . ' (new strings from visitor renders are expected)' );
			mlp_smoke_check_log( 'b_debug_log_clean' );
		}
	);
}

/**
 * Runs delete_plugins() for one plugin and proves that its uninstall.php was executed.
 *
 * If delete_plugins() does not get as far as uninstall.php in Playground (filesystem credentials),
 * the same sequence is replayed by hand: pre_uninstall_plugin action, then include uninstall.php.
 *
 * @return array{included:bool, via:string, result:string}
 */
function mlp_smoke_run_uninstall( string $plugin ): array {
	$file     = WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/uninstall.php';
	$exists   = is_readable( $file );
	$res      = delete_plugins( array( $plugin ) );
	$included = false;

	foreach ( get_included_files() as $f ) {
		if ( str_replace( '\\', '/', $f ) === str_replace( '\\', '/', $file ) ) {
			$included = true;
		}
	}

	$result = is_wp_error( $res ) ? $res->get_error_code() : var_export( $res, true );
	$via    = 'delete_plugins';

	if ( ! $included && $exists ) {
		$via = 'manual replay (delete_plugins=' . $result . ')';

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', $plugin );
		}

		do_action( 'pre_uninstall_plugin', $plugin, null );
		include $file;
		$included = true;
	}

	return array(
		'included' => $included,
		'via'      => $via,
		'result'   => $result,
	);
}

// ===== S4: delete the old plugin ===========================================
if ( 'delete_old' === $phase ) {
	mlp_smoke_step(
		'delete_old',
		static function () use ( $wpdb ): void {
			mlp_smoke_check( 'd_precondition_new_active_old_inactive', is_plugin_active( MLP_SMOKE_NEW ) && ! is_plugin_active( MLP_SMOKE_OLD ) && defined( 'LINGOCANVAS_FILE' ), '' );
			mlp_smoke_check( 'd_old_uninstall_php_present', is_readable( WP_PLUGIN_DIR . '/wp-mlp/uninstall.php' ), '' );

			$s                             = (array) get_option( 'mlp_settings', array() );
			$s['delete_data_on_uninstall'] = true;
			update_option( 'mlp_settings', $s );
			$GLOBALS['mlp_smoke']['details']['snap_before'] = mlp_smoke_snapshot();

			// Probe at priority 99, i.e. after LingoCanvas's own pre_uninstall_plugin hook (priority 10):
			// what the old uninstall.php will read from get_option().
			$seen = array();
			add_action(
				'pre_uninstall_plugin',
				static function ( $plugin ) use ( &$seen ): void {
					$seen[ (string) $plugin ] = ! empty( ( (array) get_option( 'mlp_settings', array() ) )['delete_data_on_uninstall'] );
				},
				99
			);

			$u = mlp_smoke_run_uninstall( MLP_SMOKE_OLD );
			mlp_smoke_check( 'd_old_uninstall_php_executed', $u['included'], $u['via'] . ' result=' . $u['result'] );
			mlp_smoke_check( 'd_old_uninstall_saw_delete_flag_hidden', array( MLP_SMOKE_OLD => false ) === $seen, 'flag seen by the uninstaller: ' . wp_json_encode( $seen ) );

			// phpcs:ignore
			$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mlp_settings' ) );
			$db  = maybe_unserialize( $raw );
			mlp_smoke_check( 'd_delete_flag_still_true_in_db', is_array( $db ) && ! empty( $db['delete_data_on_uninstall'] ), 'flag=' . var_export( is_array( $db ) ? ( $db['delete_data_on_uninstall'] ?? null ) : null, true ) );
			mlp_smoke_check_data_intact( 'd', ' (tables+rows kept)' );
			mlp_smoke_check( 'd_new_plugin_still_active', is_plugin_active( MLP_SMOKE_NEW ), '' );
		}
	);
}

if ( 'after_delete_old' === $phase ) {
	mlp_smoke_step(
		'after_delete_old',
		static function (): void {
			$d = $GLOBALS['mlp_smoke']['details'];

			mlp_smoke_check( 'd2_lingocanvas_boots', defined( 'LINGOCANVAS_FILE' ) && str_contains( mlp_smoke_plugin_file(), '/plugins/lingocanvas/src/' ), mlp_smoke_plugin_file() );
			mlp_smoke_check_data_intact( 'd2' );
			$r = mlp_smoke_rest_translation( (int) $d['source_id'], 'en' );
			mlp_smoke_check( 'd2_saved_translation_readable_via_rest', 200 === $r['status'] && 'Migrated translation ABC.' === $r['text'], 'status=' . $r['status'] . ' text=' . $r['text'] );
			mlp_smoke_check_log( 'd2_debug_log_clean' );
		}
	);
}

// ===== S5: delete LingoCanvas while the old plugin is active ===============
if ( 'delete_new' === $phase ) {
	mlp_smoke_step(
		'delete_new',
		static function () use ( $wpdb ): void {
			mlp_smoke_check( 'n_precondition_old_active_new_inactive', is_plugin_active( MLP_SMOKE_OLD ) && ! is_plugin_active( MLP_SMOKE_NEW ) && ! defined( 'LINGOCANVAS_FILE' ), '' );
			mlp_smoke_check( 'n_new_uninstall_php_present', is_readable( WP_PLUGIN_DIR . '/lingocanvas/uninstall.php' ), '' );

			$s                             = (array) get_option( 'mlp_settings', array() );
			$s['delete_data_on_uninstall'] = true;
			update_option( 'mlp_settings', $s );
			$GLOBALS['mlp_smoke']['details']['snap_before'] = mlp_smoke_snapshot();

			$u = mlp_smoke_run_uninstall( MLP_SMOKE_NEW );
			mlp_smoke_check( 'n_new_uninstall_php_executed', $u['included'], $u['via'] . ' result=' . $u['result'] );

			// phpcs:ignore
			$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mlp_settings' ) );
			$db  = maybe_unserialize( $raw );
			mlp_smoke_check( 'n_delete_flag_still_true_in_db', is_array( $db ) && ! empty( $db['delete_data_on_uninstall'] ), 'flag=' . var_export( is_array( $db ) ? ( $db['delete_data_on_uninstall'] ?? null ) : null, true ) );
			mlp_smoke_check_data_intact( 'n', ' (tables+rows kept)' );
			mlp_smoke_check( 'n_old_plugin_still_active', is_plugin_active( MLP_SMOKE_OLD ), '' );
		}
	);
}

if ( 'after_delete_new' === $phase ) {
	mlp_smoke_step(
		'after_delete_new',
		static function (): void {
			$d = $GLOBALS['mlp_smoke']['details'];

			mlp_smoke_check( 'n2_old_plugin_boots', defined( 'WP_MLP_FILE' ) && str_contains( mlp_smoke_plugin_file(), '/plugins/wp-mlp/src/' ), mlp_smoke_plugin_file() );
			mlp_smoke_check_data_intact( 'n2' );
			$r = mlp_smoke_rest_translation( (int) $d['source_id'], 'en' );
			mlp_smoke_check( 'n2_saved_translation_readable_via_rest', 200 === $r['status'] && 'Migrated translation ABC.' === $r['text'], 'status=' . $r['status'] . ' text=' . $r['text'] );
			mlp_smoke_check_log( 'n2_debug_log_clean' );
		}
	);
}

mlp_smoke_flush();
