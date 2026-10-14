<?php
/**
 * Заглушка wp-admin/includes/upgrade.php для юнит-тестов.
 *
 * Schema::install() подключает этот файл по пути ABSPATH; ABSPATH в тестах
 * указывает на каталог tests/. Заодно здесь живут заглушки dbDelta() и
 * flush_rewrite_rules() со счётчиками вызовов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mlp_test_install_log' ) ) {
	/**
	 * Журнал шагов установки: 'dbDelta', 'flush'.
	 *
	 * @param string|null $step  Шаг для записи.
	 * @param bool        $reset Очистить журнал.
	 * @return list<string>
	 */
	function wp_mlp_test_install_log( ?string $step = null, bool $reset = false ): array {
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
		unset( $sql );

		wp_mlp_test_install_log( 'dbDelta' );

		return array();
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	/**
	 * @param bool $hard Полный сброс.
	 */
	function flush_rewrite_rules( bool $hard = true ): void {
		unset( $hard );

		wp_mlp_test_install_log( 'flush' );
	}
}
