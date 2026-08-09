<?php
/**
 * Доступ к таблице мест использования строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\Support\Hash;
use WpMlp\Support\Locale;

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
	 * Записи, у которых есть хотя бы один перевод на указанный язык.
	 *
	 * Отдельного признака «запись переведена» в модели нет и быть не может:
	 * перевод хранится по строкам, а не по записям. Признак выводится из
	 * данных — запись считается переведённой, если хоть одна её строка имеет
	 * непустой перевод на нужный язык.
	 *
	 * @param string $locale Целевой язык.
	 * @return list<int>
	 */
	public function translatedObjectIds( string $locale ): array {
		global $wpdb;

		if ( ! Locale::isValid( $locale ) ) {
			return array();
		}

		$occurrences  = Schema::table( 'occurrences' );
		$translations = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT o.object_id
				 FROM {$occurrences} o
				 INNER JOIN {$translations} t
					ON t.source_id = o.source_id AND t.target_locale = %s
				 WHERE o.object_id IS NOT NULL
					AND o.object_id > 0
					AND t.translated_text IS NOT NULL
					AND t.translated_text <> ''",
				$locale
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Дата последнего изменения перевода записи на этот язык — самая
	 * свежая среди всех переведённых строк, которые встречались в этой
	 * записи.
	 *
	 * Нужна карте сайта (см. Frontend\Sitemap): `wp_posts.post_modified`
	 * относится только к исходному языку — перевод живёт в отдельной
	 * таблице и его не меняет. Без этого метода `lastmod` английской
	 * версии страницы навсегда оставался бы датой последней правки
	 * русского текста, даже если сам перевод появился только сегодня.
	 *
	 * @param string $locale Целевой язык.
	 * @return array<int, string> Идентификатор записи => `updated_at` (UTC, `Y-m-d H:i:s`) самого свежего перевода среди её строк.
	 */
	public function lastTranslatedAt( string $locale ): array {
		global $wpdb;

		if ( ! Locale::isValid( $locale ) ) {
			return array();
		}

		$occurrences  = Schema::table( 'occurrences' );
		$translations = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.object_id, MAX(t.updated_at) AS last_updated
				 FROM {$occurrences} o
				 INNER JOIN {$translations} t
					ON t.source_id = o.source_id AND t.target_locale = %s
				 WHERE o.object_id IS NOT NULL
					AND o.object_id > 0
					AND t.translated_text IS NOT NULL
					AND t.translated_text <> ''
				 GROUP BY o.object_id",
				$locale
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$result = array();

		foreach ( (array) $rows as $row ) {
			$result[ (int) $row['object_id'] ] = (string) $row['last_updated'];
		}

		return $result;
	}

	/**
	 * Встречалась ли строка у этого объекта (защита commit'а массового
	 * перевода: id строки в запросе обязан быть тем, что действительно
	 * извлечён из ЭТОЙ записи, а не чужим, подставленным вручную в запрос).
	 *
	 * @param int $sourceId Исходная строка.
	 * @param int $objectId Идентификатор объекта (записи).
	 */
	public function belongsToObject( int $sourceId, int $objectId ): bool {
		global $wpdb;

		if ( $sourceId <= 0 || $objectId <= 0 ) {
			return false;
		}

		$table = Schema::table( 'occurrences' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE source_id = %d AND object_id = %d LIMIT 1",
				$sourceId,
				$objectId
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return null !== $found;
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
