<?php
/**
 * Заглушка wp-admin/includes/upgrade.php для юнит-тестов.
 *
 * Schema::install() подключает этот файл по пути ABSPATH; ABSPATH в тестах
 * указывает на каталог tests/. Заодно здесь живут заглушки dbDelta() и
 * flush_rewrite_rules() со счётчиками вызовов.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lingocanvas_test_install_log' ) ) {
	/**
	 * Журнал шагов установки: 'dbDelta', 'flush'.
	 *
	 * @param string|null $step  Шаг для записи.
	 * @param bool        $reset Очистить журнал.
	 * @return list<string>
	 */
	function lingocanvas_test_install_log( ?string $step = null, bool $reset = false ): array {
		static $log = array();

		if ( $reset ) {
			$log = array();
		}

		if ( null !== $step ) {
			$log[] = $step;
		}

		return $log;
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * @param string $sql Запрос CREATE TABLE.
	 * @return array<string, string>
	 */
	function dbDelta( string $sql ): array {
		lingocanvas_test_install_log( 'dbDelta' );

		// Двойник базы может запомнить таблицы по тексту CREATE TABLE.
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'createFromSql' ) ) {
			$GLOBALS['wpdb']->createFromSql( $sql );
		}

		return array();
	}
}

if ( ! function_exists( 'maybe_create_table' ) ) {
	/**
	 * Как в ядре: есть таблица — true; нет — пробуем CREATE и проверяем снова.
	 *
	 * @param string $table_name  Имя таблицы.
	 * @param string $create_ddl  Запрос CREATE TABLE.
	 */
	function maybe_create_table( string $table_name, string $create_ddl ): bool {
		global $wpdb;

		$exists = static function () use ( $wpdb, $table_name ): bool {
			return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		};

		if ( $exists() ) {
			return true;
		}

		if ( method_exists( $wpdb, 'createFromSql' ) ) {
			$wpdb->createFromSql( $create_ddl );
		}

		return $exists();
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	/**
	 * @param bool $hard Полный сброс.
	 */
	function flush_rewrite_rules( bool $hard = true ): void {
		unset( $hard );

		lingocanvas_test_install_log( 'flush' );
	}
}
