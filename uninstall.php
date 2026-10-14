<?php
/**
 * Удаление данных плагина.
 *
 * По умолчанию не делает ничего: переводы — результат ручной работы, и
 * потерять их из-за случайного удаления плагина нельзя (критерий приёмки 16).
 * Таблицы удаляются, только если владелец сайта явно включил галочку
 * «Удалить все переводы» на странице настроек.
 *
 * Схема БД привязана к префиксу сайта ($wpdb->prefix), а настройки и флаг
 * согласия хранятся в опциях сайта, поэтому в мультисайте проход делается
 * по каждому сайту отдельно: удаляются данные только тех сайтов, где
 * владелец дал согласие.
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Удаляет данные плагина текущего сайта, если владелец это разрешил.
 */
function wp_mlp_uninstall_current_site(): void {
	global $wpdb;

	$settings = get_option( 'mlp_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	require_once __DIR__ . '/src/Storage/Schema.php';

	\WpMlp\Storage\Schema::drop();

	// Опции с фиксированными именами (Schema::drop() убирает mlp_db_version сама).
	$options = array(
		'mlp_settings',
		'mlp_db_version',
		'mlp_cache_version',
		'mlp_cleanup_version',
		'mlp_cleanup_cursor',
		'mlp_cleanup_cursor_v2',
		'mlp_cleanup_lock',
		'mlp_objtype_version',
		'mlp_objtype_cursor',
		'mlp_objtype_lock',
		'mlp_block_hashes',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery

	// Семейства опций с динамическим именем: счётчики расхода OpenAI по дням
	// (UsageTracker::OPTION_PREFIX) и служебные транзиенты «страницу уже отметили».
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'mlp_usage_' ) . '%',
			$wpdb->esc_like( '_transient_mlp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mlp_' ) . '%'
		)
	);

	// Метаполя записей: флаги «запись показывается на языке»
	// (PostLanguageVisibility::META_PREFIX) и снимки переведённых сегментов
	// (PostTranslationSnapshot::META_PREFIX).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			$wpdb->esc_like( '_mlp_visible_' ) . '%',
			$wpdb->esc_like( '_mlp_translated_segments_' ) . '%'
		)
	);

	// phpcs:enable WordPress.DB.DirectDatabaseQuery

	wp_mlp_uninstall_flags_directory();
}

/**
 * Удаляет каталог загруженных флагов (Flags::DIRECTORY) из uploads текущего сайта.
 */
function wp_mlp_uninstall_flags_directory(): void {
	$uploads = wp_upload_dir( null, false );
	$base    = (string) ( $uploads['basedir'] ?? '' );

	if ( '' === $base ) {
		return;
	}

	// Имя каталога совпадает с \WpMlp\Frontend\Flags::DIRECTORY.
	$directory = trailingslashit( $base ) . 'wp-mlp-flags';

	if ( ! is_dir( $directory ) ) {
		return;
	}

	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( WP_Filesystem() && is_object( $wp_filesystem ) ) {
		$wp_filesystem->rmdir( $directory, true );

		return;
	}

	// Файловая система недоступна напрямую (запрос учётных данных FTP): удаляем файлы по одному.
	$files = glob( trailingslashit( $directory ) . '*' );

	foreach ( is_array( $files ) ? $files : array() as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- запасной путь, когда WP_Filesystem недоступна.
	rmdir( $directory );
}

if ( is_multisite() ) {
	$wp_mlp_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wp_mlp_site_ids as $wp_mlp_site_id ) {
		switch_to_blog( (int) $wp_mlp_site_id );
		wp_mlp_uninstall_current_site();
		restore_current_blog();
	}
} else {
	wp_mlp_uninstall_current_site();
}
