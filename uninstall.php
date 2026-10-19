<?php
/**
 * Удаление данных плагина.
 *
 * По умолчанию данные пользователя не трогаются: переводы — результат ручной
 * работы, и потерять их из-за случайного удаления плагина нельзя (критерий
 * приёмки 16). Таблицы, опции, мета и папка флагов удаляются, только если
 * владелец сайта явно включил галочку «Удалить все переводы» на странице
 * настроек. Служебные опции (без данных пользователя) убираются всегда.
 *
 * Схема БД привязана к префиксу сайта ($wpdb->prefix), а настройки и флаг
 * согласия хранятся в опциях сайта, поэтому в мультисайте проход делается
 * по каждому сайту отдельно: удаляются данные только тех сайтов, где
 * владелец дал согласие.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Классы плагина в uninstall.php не загружены, поэтому все имена здесь
 * перечислены явно. Список таблиц должен совпадать с
 * \LingoCanvas\Storage\Schema::TABLES (это проверяет тест).
 */

/**
 * Удаляет служебные опции прошлых версий. Данных пользователя в них нет,
 * поэтому они убираются независимо от согласия на удаление данных.
 */
if ( ! function_exists( 'lingocanvas_uninstall_service_options' ) ) {
	function lingocanvas_uninstall_service_options(): void {
		global $wpdb;

		foreach ( array(
			'lingocanvas_data_prefix',
			'lingocanvas_migration',
			'lingocanvas_migration_lock',
			'lingocanvas_write_freeze',
			'lingocanvas_prefix_conflicts',
			'lingocanvas_pending_activation',
			'lingocanvas_legacy_dir',
		) as $option ) {
			delete_option( $option );
		}

		// Версии, курсоры и замки разовых чисток версии 0.4.x.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- служебные опции собственных разовых чисток плагина.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'lingocanvas_cleanup_' ) . '%',
				$wpdb->esc_like( 'lingocanvas_objtype_' ) . '%'
			)
		);
	}
}

/**
 * Удаляет таблицы плагина текущего сайта: четыре рабочие и резервные
 * `{prefix}lingocanvas_bak_*` (если остались от прошлых версий).
 */
if ( ! function_exists( 'lingocanvas_uninstall_tables' ) ) {
	function lingocanvas_uninstall_tables(): void {
		global $wpdb;

		$tables = array();

		foreach ( array( 'sources', 'translations', 'occurrences', 'routes' ) as $name ) {
			$tables[] = $wpdb->prefix . 'lingocanvas_' . $name;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- поиск резервных таблиц собственного плагина при деинсталляции.
		$backups = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lingocanvas_bak_' ) . '%' ) );

		foreach ( is_array( $backups ) ? $backups : array() as $backup ) {
			// Имя пришло из БД, но в запрос идёт через %i; чужие имена отсекаем.
			if ( is_string( $backup ) && 1 === preg_match( '/^[A-Za-z0-9_$]+$/D', $backup ) && str_starts_with( $backup, $wpdb->prefix . 'lingocanvas_bak_' ) ) {
				$tables[] = $backup;
			}
		}

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственных таблиц плагина при деинсталляции.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}

/**
 * Удаляет опции, мета записей и транзиенты плагина текущего сайта.
 */
if ( ! function_exists( 'lingocanvas_uninstall_options_and_meta' ) ) {
	function lingocanvas_uninstall_options_and_meta(): void {
		global $wpdb;

		foreach ( array( 'settings', 'db_version', 'cache_version', 'block_hashes' ) as $suffix ) {
			delete_option( 'lingocanvas_' . $suffix );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery

		// Счётчики расхода OpenAI по дням (UsageTracker::optionPrefix()), копии опций
		// `lingocanvas_bak_<метка>_options` от прошлых версий (рядом с резервными таблицами) и служебные транзиенты.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'lingocanvas_usage_' ) . '%',
				$wpdb->esc_like( 'lingocanvas_bak_' ) . '%',
				$wpdb->esc_like( '_transient_lingocanvas_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_lingocanvas_' ) . '%'
			)
		);

		// Метаполя записей: флаги «запись показывается на языке»
		// (PostLanguageVisibility::metaPrefix()) и снимки переведённых сегментов
		// (PostTranslationSnapshot::metaPrefix()).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( '_lingocanvas_visible_' ) . '%',
				$wpdb->esc_like( '_lingocanvas_translated_segments_' ) . '%'
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Удаляет данные плагина текущего сайта.
 *
 * Служебные опции убираются всегда. Остальное (таблицы, опции, мета,
 * транзиенты, папка флагов) — только если владелец включил «Удалить все
 * переводы». Чужие таблицы, опции и папки (не `lingocanvas_*`) не затрагиваются никогда.
 */
if ( ! function_exists( 'lingocanvas_uninstall_current_site' ) ) {
	function lingocanvas_uninstall_current_site(): void {
		lingocanvas_uninstall_service_options();

		$settings = get_option( 'lingocanvas_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		lingocanvas_uninstall_tables();
		lingocanvas_uninstall_options_and_meta();
		lingocanvas_uninstall_flags_directory( 'lingocanvas-flags' );
	}
}

/**
 * Удаляет каталог загруженных флагов из uploads текущего сайта.
 *
 * @param string $name Имя каталога (Flags::DIRECTORY).
 */
if ( ! function_exists( 'lingocanvas_uninstall_flags_directory' ) ) {
	function lingocanvas_uninstall_flags_directory( string $name ): void {
		$uploads = wp_upload_dir( null, false );
		$base    = (string) ( $uploads['basedir'] ?? '' );

		if ( '' === $base || 'lingocanvas-flags' !== $name ) {
			return;
		}

		$directory = trailingslashit( $base ) . $name;

		// Симлинк или не каталог: ничего не трогаем (защита от выхода за пределы uploads).
		if ( is_link( $directory ) || ! is_dir( $directory ) ) {
			return;
		}

		$real_directory = realpath( $directory );
		$real_base      = realpath( $base );

		if ( false === $real_directory || false === $real_base ) {
			return;
		}

		$real_base = rtrim( $real_base, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( 0 !== strpos( $real_directory . DIRECTORY_SEPARATOR, $real_base ) || $real_directory . DIRECTORY_SEPARATOR === $real_base ) {
			return;
		}

		// Только обычные *.svg прямо в каталоге: без рекурсии, симлинки и подкаталоги пропускаем.
		$files = glob( trailingslashit( $real_directory ) . '*.svg' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_link( $file ) || is_dir( $file ) || ! is_file( $file ) ) {
				continue;
			}

			wp_delete_file( $file );
		}

		// Каталог удаляем только если он стал пустым (нерекурсивно).
		$left = scandir( $real_directory );

		if ( is_array( $left ) && array() === array_diff( $left, array( '.', '..' ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- uninstall, direct fs, plugin-owned empty dir.
			@rmdir( $real_directory );
		}
	}
}

if ( is_multisite() ) {
	$lingocanvas_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $lingocanvas_site_ids as $lingocanvas_site_id ) {
		switch_to_blog( (int) $lingocanvas_site_id );
		lingocanvas_uninstall_current_site();
		restore_current_blog();
	}
} else {
	lingocanvas_uninstall_current_site();
}
