<?php
/**
 * Удаление данных плагина.
 *
 * По умолчанию не делает ничего: переводы — результат ручной работы, и
 * потерять их из-за случайного удаления плагина нельзя (критерий приёмки 16).
 * Таблицы удаляются, только если владелец сайта явно включил галочку
 * «Удалить все переводы» на странице настроек.
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mlp_settings = get_option( 'mlp_settings', array() );

if ( ! is_array( $mlp_settings ) || empty( $mlp_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/src/Storage/Schema.php';

\WpMlp\Storage\Schema::drop();

delete_option( 'mlp_settings' );
delete_option( 'mlp_cache_version' );
delete_option( 'mlp_block_hashes' );

global $wpdb;

// Служебные транзиенты «страницу уже отметили» отдельного индекса не имеют.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_mlp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mlp_' ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery
