<?php
/**
 * Доступ к таблице исходных строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\Support\Hash;
use WpMlp\Support\Locale;

/**
 * Чтение и запись `wp_mlp_sources`.
 *
 * Все методы работают пачками: на странице бывают сотни строк, и запрос на
 * каждую нарушил бы требование ТЗ 12.2 (не более 3–5 запросов на страницу).
 */
final class SourceRepository {

	/**
	 * Сколько значений максимум уходит в один IN(...).
	 *
	 * Ограничение не техническое, а гигиеническое: очень длинный запрос хуже
	 * читается в Query Monitor и упирается в max_allowed_packet на слабых ВПС.
	 */
	private const CHUNK = 200;

	/**
	 * Опция со списком хешей translation blocks.
	 *
	 * Именно опция, а не транзиент: транзиент с временем жизни не попадает в
	 * автозагрузку и стоил бы двух лишних запросов на каждой странице, даже
	 * когда блоков нет вовсе.
	 */
	public const BLOCKS_OPTION = 'mlp_block_hashes';

	/**
	 * Ищет строки и их переводы одним запросом.
	 *
	 * @param list<string> $uniqHashes   Hex-хеши строк, найденных на странице.
	 * @param string       $targetLocale Язык, на который переводим.
	 * @return array<string, array{id: int, text: ?string, status: ?string}> Ключ — hex uniq_hash.
	 */
	public function lookup( array $uniqHashes, string $targetLocale ): array {
		global $wpdb;

		$uniqHashes = self::filterHashes( $uniqHashes );

		if ( array() === $uniqHashes || ! Locale::isValid( $targetLocale ) ) {
			return array();
		}

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );
		$result       = array();

		foreach ( array_chunk( $uniqHashes, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), 'UNHEX(%s)' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имена таблиц из allowlist, значения через prepare().
			$sql = $wpdb->prepare(
				"SELECT LOWER(HEX(s.uniq_hash)) AS uniq_hash, s.id, t.translated_text, t.status
				 FROM {$sources} s
				 LEFT JOIN {$translations} t ON t.source_id = s.id AND t.target_locale = %s
				 WHERE s.uniq_hash IN ({$placeholders})",
				array_merge( array( $targetLocale ), $chunk )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- собственная таблица плагина.
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			foreach ( (array) $rows as $row ) {
				$result[ (string) $row['uniq_hash'] ] = array(
					'id'     => (int) $row['id'],
					'text'   => null !== $row['translated_text'] ? (string) $row['translated_text'] : null,
					'status' => null !== $row['status'] ? (string) $row['status'] : null,
				);
			}
		}

		return $result;
	}

	/**
	 * Добавляет строки, которых ещё нет.
	 *
	 * INSERT IGNORE, а не «проверить и вставить»: две одновременные страницы
	 * могут найти одну и ту же новую строку, и уникальный индекс должен
	 * разрешить гонку сам.
	 *
	 * @param list<array{locale: string, kind: string, text: string, source_hash: string, context_hash: string, uniq_hash: string}> $rows Новые строки.
	 * @return int Сколько строк реально добавлено.
	 */
	public function insertMissing( array $rows ): int {
		global $wpdb;

		if ( array() === $rows ) {
			return 0;
		}

		$table    = Schema::table( 'sources' );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$inserted = 0;

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$values = array();
			$args   = array();

			foreach ( $chunk as $row ) {
				if ( ! Hash::isValid( $row['uniq_hash'] ) || ! Hash::isValid( $row['source_hash'] ) || ! Hash::isValid( $row['context_hash'] ) ) {
					continue;
				}

				$values[] = '(%s, %s, %s, UNHEX(%s), UNHEX(%s), UNHEX(%s), %s, %s)';
				$args[]   = $row['locale'];
				$args[]   = $row['kind'];
				$args[]   = $row['text'];
				$args[]   = $row['source_hash'];
				$args[]   = $row['context_hash'];
				$args[]   = $row['uniq_hash'];
				$args[]   = $now;
				$args[]   = $now;
			}

			if ( array() === $values ) {
				continue;
			}

			$valuesSql = implode( ', ', $values );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$sql = $wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(source_locale, kind, source_text, source_hash, context_hash, uniq_hash, created_at, last_seen_at)
				 VALUES {$valuesSql}",
				$args
			);

			$inserted += (int) $wpdb->query( $sql );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		return $inserted;
	}

	/**
	 * Возвращает id строк по их хешам.
	 *
	 * @param list<string> $uniqHashes Hex-хеши.
	 * @return array<string, int> Ключ — hex uniq_hash.
	 */
	public function idsByHashes( array $uniqHashes ): array {
		global $wpdb;

		$uniqHashes = self::filterHashes( $uniqHashes );

		if ( array() === $uniqHashes ) {
			return array();
		}

		$table  = Schema::table( 'sources' );
		$result = array();

		foreach ( array_chunk( $uniqHashes, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), 'UNHEX(%s)' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, LOWER(HEX(uniq_hash)) AS uniq_hash FROM {$table} WHERE uniq_hash IN ({$placeholders})",
					$chunk
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

			foreach ( (array) $rows as $row ) {
				$result[ (string) $row['uniq_hash'] ] = (int) $row['id'];
			}
		}

		return $result;
	}

	/**
	 * Обновляет отметку «строка ещё встречается на сайте».
	 *
	 * @param list<int> $ids Идентификаторы строк.
	 */
	public function touch( array $ids ): void {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return;
		}

		$table = Schema::table( 'sources' );

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET last_seen_at = %s WHERE id IN ({$placeholders})",
					array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $chunk )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Хеши всех блоков — множество вида `hex => true`.
	 *
	 * Список нужен на каждой странице, чтобы понять, какие элементы переводятся
	 * целиком. Блоков обычно единицы, поэтому набор кэшируется, а пока их нет,
	 * разбор страницы вообще не тратит на них время.
	 *
	 * @return array<string, true>
	 */
	public function blockHashes(): array {
		$cached = get_option( self::BLOCKS_OPTION, null );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = Schema::table( 'sources' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT LOWER(HEX(source_hash)) FROM {$table} WHERE kind = %s", 'html_block' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$hashes = array_fill_keys( is_array( $rows ) ? $rows : array(), true );

		update_option( self::BLOCKS_OPTION, $hashes, true );

		return $hashes;
	}

	/**
	 * Сбрасывает список блоков — он пересоберётся при следующем показе страницы.
	 */
	public function flushBlockHashes(): void {
		delete_option( self::BLOCKS_OPTION );
	}

	/**
	 * Удаляет строку вместе с переводами и местами использования.
	 *
	 * Применяется только к блокам: обычные строки живут, пока живёт текст
	 * на сайте, и удалять их вручную не требуется.
	 *
	 * @param int $id Идентификатор строки.
	 */
	public function deleteWithTranslations( int $id ): void {
		global $wpdb;

		if ( $id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'translations' ), array( 'source_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'occurrences' ), array( 'source_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'sources' ), array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Одна строка по идентификатору.
	 *
	 * @param int $id Идентификатор.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( 'sources' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Постраничный список строк с переводом на выбранный язык — для админки.
	 *
	 * @param array{locale: string, status?: string, search?: string, page?: int, per_page?: int} $args Фильтры.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function paginate( array $args ): array {
		global $wpdb;

		$locale = (string) ( $args['locale'] ?? '' );

		if ( ! Locale::isValid( $locale ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$perPage = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset  = ( $page - 1 ) * $perPage;
		$status  = (string) ( $args['status'] ?? '' );
		$search  = trim( (string) ( $args['search'] ?? '' ) );

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		$where = array( '1=1' );
		$params = array( $locale );

		if ( TranslationStatus::MISSING === $status ) {
			// Перевода нет вовсе либо он пустой.
			$where[] = "(t.id IS NULL OR t.translated_text IS NULL OR t.translated_text = '')";
		} elseif ( TranslationStatus::isValid( $status ) ) {
			$where[]  = 't.status = %s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.source_text LIKE %s OR t.translated_text LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$whereSql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$sources} s
				 LEFT JOIN {$translations} t ON t.source_id = s.id AND t.target_locale = %s
				 WHERE {$whereSql}",
				$params
			)
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.kind, s.source_text, s.last_seen_at,
						t.translated_text, t.status, t.updated_at
				 FROM {$sources} s
				 LEFT JOIN {$translations} t ON t.source_id = s.id AND t.target_locale = %s
				 WHERE {$whereSql}
				 ORDER BY s.id DESC
				 LIMIT %d OFFSET %d",
				array_merge( $params, array( $perPage, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Отсеивает всё, что не является hex-хешем SHA-256.
	 *
	 * @param list<string> $hashes Хеши.
	 * @return list<string>
	 */
	private static function filterHashes( array $hashes ): array {
		return array_values( array_unique( array_filter( $hashes, array( Hash::class, 'isValid' ) ) ) );
	}
}
