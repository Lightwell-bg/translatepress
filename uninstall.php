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

/*
 * Имена функций здесь с префиксом lingocanvas_: при удалении обоих плагинов
 * за один запрос выполняются оба uninstall.php, а прежний объявляет свои
 * функции без защиты от повторного объявления. По той же причине класс
 * WpMlp\Storage\Schema этот файл сам не объявляет (см. ниже): прежний
 * uninstall.php, выполненный после нашего, должен суметь объявить его сам.
 */

/**
 * Активен ли прежний плагин «WP Multilang Press» (главный файл wp-mlp.php)
 * на ТЕКУЩЕМ сайте (в мультисайте вызывается после switch_to_blog()).
 *
 * Он пользуется теми же таблицами и опциями, поэтому, пока он активен,
 * данные сайта удалять нельзя. Сравнивается имя главного файла, а не путь целиком.
 */
if ( ! function_exists( 'lingocanvas_uninstall_legacy_plugin_active' ) ) {
	function lingocanvas_uninstall_legacy_plugin_active(): bool {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active as $basename ) {
			if ( is_string( $basename ) && 'wp-mlp.php' === basename( $basename ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Удаляет таблицы плагина текущего сайта.
 *
 * Если класс Schema уже загружен (прежним плагином в этом же запросе), берём
 * его. Иначе таблицы удаляются напрямую по списку ниже, а класс не объявляется.
 * Список должен совпадать с \WpMlp\Storage\Schema::TABLES (это проверяет тест).
 */
if ( ! function_exists( 'lingocanvas_uninstall_drop_tables' ) ) {
	function lingocanvas_uninstall_drop_tables(): void {
		global $wpdb;

		if ( class_exists( 'WpMlp\Storage\Schema', false ) ) {
			\WpMlp\Storage\Schema::drop();

			return;
		}

		foreach ( array( 'sources', 'translations', 'occurrences', 'routes' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- удаление собственных таблиц плагина при деинсталляции.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'mlp_' . $name ) );
		}

		delete_option( 'mlp_db_version' );
	}
}

/**
 * Удаляет данные плагина текущего сайта, если владелец это разрешил.
 */
if ( ! function_exists( 'lingocanvas_uninstall_current_site' ) ) {
	function lingocanvas_uninstall_current_site(): void {
		global $wpdb;

		// Служебные опции перехода не содержат данных пользователя: убираем всегда.
		delete_option( 'lingocanvas_pending_activation' );
		delete_option( 'lingocanvas_legacy_dir' );

		// Прежний плагин активен на этом сайте и использует те же данные.
		if ( lingocanvas_uninstall_legacy_plugin_active() ) {
			return;
		}

		$settings = get_option( 'mlp_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		lingocanvas_uninstall_drop_tables();

		// Опции с фиксированными именами (mlp_db_version при прямом удалении таблиц убирается там же).
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

		lingocanvas_uninstall_flags_directory();
	}
}

/**
 * Удаляет каталог загруженных флагов (Flags::DIRECTORY) из uploads текущего сайта.
 */
if ( ! function_exists( 'lingocanvas_uninstall_flags_directory' ) ) {
	function lingocanvas_uninstall_flags_directory(): void {
		$uploads = wp_upload_dir( null, false );
		$base    = (string) ( $uploads['basedir'] ?? '' );

		if ( '' === $base ) {
			return;
		}

		// Имя каталога совпадает с \WpMlp\Frontend\Flags::DIRECTORY.
		$directory = trailingslashit( $base ) . 'wp-mlp-flags';

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
	$wp_mlp_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $wp_mlp_site_ids as $wp_mlp_site_id ) {
		switch_to_blog( (int) $wp_mlp_site_id );
		lingocanvas_uninstall_current_site();
		restore_current_blog();
	}
} else {
	lingocanvas_uninstall_current_site();
}
