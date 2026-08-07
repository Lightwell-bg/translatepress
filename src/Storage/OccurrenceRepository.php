<?php
/**
 * Доступ к таблице мест использования строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\Support\Hash;

/**
 * Чтение и запись `wp_mlp_occurrences` (ТЗ 6.3).
 *
 * На Этапе 1 таблица нужна для ответа на вопрос «где встретилась строка»:
 * это помогает переводчику и понадобится очистке устаревших данных.
 */
final class OccurrenceRepository {

	private const CHUNK = 200;

	/**
	 * Добавляет места использования, пропуская уже известные.
	 *
	 * @param list<array{source_id: int, object_type: string, object_id: ?int, url_hash: ?string, attribute_name: ?string, uniq_hash: string}> $rows Записи.
	 */
	public function insertMany( array $rows ): void {
		global $wpdb;

		if ( array() === $rows ) {
			return;
		}

		$table = Schema::table( 'occurrences' );
		$now   = gmdate( 'Y-m-d H:i:s' );

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$values = array();
			$args   = array();

			foreach ( $chunk as $row ) {
				if ( ! Hash::isValid( $row['uniq_hash'] ) || (int) $row['source_id'] <= 0 ) {
					continue;
				}

				$urlHashSql = null !== $row['url_hash'] && Hash::isValid( $row['url_hash'] ) ? 'UNHEX(%s)' : 'NULL';

				$values[] = "(%d, %s, NULLIF(%d, 0), {$urlHashSql}, NULLIF(%s, ''), UNHEX(%s), %s, %s)";
				$args[]   = (int) $row['source_id'];
				$args[]   = $row['object_type'];
				$args[]   = (int) ( $row['object_id'] ?? 0 );

				if ( 'NULL' !== $urlHashSql ) {
					$args[] = $row['url_hash'];
				}

				$args[] = (string) ( $row['attribute_name'] ?? '' );
				$args[] = $row['uniq_hash'];
				$args[] = $now;
				$args[] = $now;
			}

			if ( array() === $values ) {
				continue;
			}

			$valuesSql = implode( ', ', $values );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
						(source_id, object_type, object_id, url_hash, attribute_name, uniq_hash, first_seen_at, last_seen_at)
					 VALUES {$valuesSql}
					 ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)",
					$args
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Записи и страницы, на которых вообще находились строки.
	 *
	 * Нужен фильтру в админке: показывать в выпадающем списке только то,
	 * что плагин реально видел, а не весь каталог записей сайта.
	 *
	 * @param int $limit Сколько объектов вернуть.
	 * @return list<int>
	 */
	public function objectIds( int $limit = 300 ): array {
		global $wpdb;

		$table = Schema::table( 'occurrences' );
		$limit = max( 1, min( 1000, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_id FROM {$table}
				 WHERE object_id IS NOT NULL AND object_id > 0
				 ORDER BY object_id DESC
				 LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Где встречалась строка — для подсказки в админке.
	 *
	 * @param int $sourceId Исходная строка.
	 * @param int $limit    Сколько записей вернуть.
	 * @return list<array<string, mixed>>
	 */
	public function forSource( int $sourceId, int $limit = 5 ): array {
		global $wpdb;

		$table = Schema::table( 'occurrences' );
		$limit = max( 1, min( 50, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_type, object_id, attribute_name, last_seen_at
				 FROM {$table} WHERE source_id = %d ORDER BY last_seen_at DESC LIMIT %d",
				$sourceId,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $rows ) ? $rows : array();
	}
}
