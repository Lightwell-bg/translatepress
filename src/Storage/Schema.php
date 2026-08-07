<?php
/**
 * Таблицы плагина и их миграции.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Создание и обновление схемы БД через dbDelta (ТЗ 6.1–6.4).
 *
 * Таблицы раздела 6.5 (jobs, usage, logs, glossary, redirects) на этом этапе
 * не создаются: они нужны автопереводу.
 */
final class Schema {

	/**
	 * Версия схемы. Увеличивать при любом изменении CREATE TABLE.
	 */
	public const VERSION = 1;

	public const OPTION_VERSION = 'mlp_db_version';

	/**
	 * Суффиксы таблиц — единственный допустимый источник имён.
	 *
	 * Имя таблицы нельзя подставить через $wpdb->prepare(), поэтому оно
	 * собирается только из префикса $wpdb и константы из этого списка
	 * (раздел 13 ТЗ: allowlist имён таблиц).
	 */
	public const TABLES = array( 'sources', 'translations', 'occurrences', 'routes' );

	/**
	 * Полное имя таблицы.
	 *
	 * @param string $name Один из self::TABLES.
	 *
	 * @throws \InvalidArgumentException Если имя вне allowlist.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Неизвестная таблица "%s".', $name ) );
		}

		return $wpdb->prefix . 'mlp_' . $name;
	}

	/**
	 * Создаёт или обновляет таблицы, если версия схемы изменилась.
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::OPTION_VERSION, 0 ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Применяет схему.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		foreach ( self::statements( $charset ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_VERSION, self::VERSION );
	}

	/**
	 * Удаляет все таблицы плагина. Вызывается только из uninstall.php.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имя таблицы из allowlist.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		delete_option( self::OPTION_VERSION );
	}

	/**
	 * CREATE TABLE в формате, который понимает dbDelta.
	 *
	 * Требования dbDelta: одно поле на строку, два пробела после PRIMARY KEY,
	 * ключевые слова в верхнем регистре, типы — в нижнем.
	 *
	 * @param string $charset Результат $wpdb->get_charset_collate().
	 * @return list<string>
	 */
	private static function statements( string $charset ): array {
		$sources      = self::table( 'sources' );
		$translations = self::table( 'translations' );
		$occurrences  = self::table( 'occurrences' );
		$routes       = self::table( 'routes' );

		return array(
			/*
			 * uniq_hash — отступление от ТЗ 6.1, согласованное с заказчиком:
			 * составной UNIQUE из семи колонок с nullable-полями в MySQL не
			 * запрещает дубли (NULL != NULL), поэтому уникальность вынесена
			 * в один хеш от всех частей ключа. Сами колонки сохранены.
			 */
			"CREATE TABLE {$sources} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_locale varchar(20) NOT NULL,
				kind varchar(32) NOT NULL,
				source_text longtext NOT NULL,
				source_hash binary(32) NOT NULL,
				context_hash binary(32) NOT NULL,
				domain varchar(191) DEFAULT NULL,
				gettext_context varchar(191) DEFAULT NULL,
				plural_key tinyint(4) DEFAULT NULL,
				uniq_hash binary(32) NOT NULL,
				created_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_hash (uniq_hash),
				KEY source_hash (source_hash),
				KEY kind_locale (kind,source_locale),
				KEY last_seen_at (last_seen_at)
			) {$charset};",

			"CREATE TABLE {$translations} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				target_locale varchar(20) NOT NULL,
				translated_text longtext DEFAULT NULL,
				status varchar(20) NOT NULL,
				provider varchar(50) DEFAULT NULL,
				model varchar(100) DEFAULT NULL,
				source_revision binary(32) DEFAULT NULL,
				created_by bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_target (source_id,target_locale),
				KEY locale_status (target_locale,status)
			) {$charset};",

			"CREATE TABLE {$occurrences} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				object_type varchar(32) NOT NULL,
				object_id bigint(20) unsigned DEFAULT NULL,
				url_hash binary(32) DEFAULT NULL,
				selector_hint varchar(255) DEFAULT NULL,
				attribute_name varchar(64) DEFAULT NULL,
				uniq_hash binary(32) NOT NULL,
				first_seen_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_hash (uniq_hash),
				KEY source_id (source_id),
				KEY url_hash (url_hash)
			) {$charset};",

			"CREATE TABLE {$routes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(32) NOT NULL,
				object_id bigint(20) unsigned DEFAULT NULL,
				locale varchar(20) NOT NULL,
				source_slug varchar(200) NOT NULL,
				translated_slug varchar(200) NOT NULL,
				path_hash binary(32) NOT NULL,
				status varchar(20) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY locale_path (locale,path_hash),
				KEY object (object_type,object_id,locale)
			) {$charset};",
		);
	}
}
