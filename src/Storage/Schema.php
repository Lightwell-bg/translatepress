<?php
/**
 * Таблицы плагина и их миграции.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Storage;

defined( 'ABSPATH' ) || exit;

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
	public const VERSION = 2;

	/**
	 * Суффиксы таблиц — единственный допустимый источник имён.
	 *
	 * Имя таблицы нельзя подставить через $wpdb->prepare(), поэтому оно
	 * собирается только из префикса $wpdb и константы из этого списка
	 * (раздел 13 ТЗ: allowlist имён таблиц).
	 */
	public const TABLES = array( 'sources', 'translations', 'occurrences', 'routes' );

	/**
	 * Префикс имён, под которыми плагин хранит данные (опции, таблицы, мета).
	 */
	public const PREFIX = 'lingocanvas_';

	/**
	 * Имя опции с версией схемы: `lingocanvas_db_version`.
	 */
	public static function versionOption(): string {
		return self::PREFIX . 'db_version';
	}

	/**
	 * Полное имя таблицы: `{prefix}lingocanvas_<name>`.
	 *
	 * Имя таблицы нельзя подставить через prepare() как значение, поэтому оно
	 * собирается только из префикса $wpdb, константы PREFIX и имени из allowlist
	 * (раздел 13 ТЗ).
	 *
	 * @param string $name Один из self::TABLES.
	 *
	 * @throws \InvalidArgumentException Если имя вне allowlist.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown table "%s".', $name ) ) );
		}

		return $wpdb->prefix . self::PREFIX . $name;
	}

	/**
	 * Создаёт или обновляет таблицы, если версия схемы изменилась.
	 *
	 * Вызывается на admin_init: схема могла отстать, если плагин обновили
	 * копированием файлов. Будущие изменения схемы — увеличить VERSION и
	 * поправить CREATE TABLE в statements(): dbDelta приведёт таблицы к новому виду.
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::versionOption(), 0 ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Создаёт или дополняет таблицы через dbDelta и запоминает версию схемы.
	 *
	 * Версия сохраняется только если все таблицы реально существуют: иначе
	 * (например, нет привилегии CREATE) maybeUpgrade() повторит попытку
	 * на следующем запросе в админке.
	 *
	 * @return bool true, если все таблицы на месте и версия сохранена.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$statements = self::statements( $charset );

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		/*
		 * Версию пишем, только если все таблицы на месте: иначе (например, без
		 * права CREATE) maybeUpgrade() повторит установку при следующем заходе.
		 * maybe_create_table() — функция ядра: проверяет наличие таблицы и при
		 * отсутствии ещё раз пробует её создать. Порядок statements() совпадает
		 * с self::TABLES.
		 */
		foreach ( self::TABLES as $index => $name ) {
			if ( ! maybe_create_table( self::table( $name ), $statements[ $index ] ) ) {
				return false;
			}
		}

		update_option( self::versionOption(), self::VERSION );

		return true;
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
				KEY object_id (object_id),
				KEY source_object (source_id,object_id),
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
