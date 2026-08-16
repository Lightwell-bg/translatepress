<?php
/**
 * Доступ к таблице исходных строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\Rendering\Segment;
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
	 * Удаляет исходные строки, у которых нет ни одного перевода.
	 *
	 * Безопасно по той же причине, что и у gettext: строки словаря —
	 * данные производные, они появляются заново при следующем показе
	 * страницы. Строка, которую кто-то перевёл хоть на один язык, не
	 * трогается вовсе — теряется только список «что ещё предстоит», и он
	 * восстанавливается сам.
	 *
	 * Нужно, когда в словарь успел попасть мусор: например, строки
	 * интерфейса, собранные как контент до появления gettext-контура, —
	 * они так и остались бы в «Контенте» навсегда.
	 *
	 * @param list<string> $kinds Виды строк, которые чистим.
	 * @return int Сколько строк удалено.
	 */
	public function deleteUntranslated( array $kinds ): int {
		global $wpdb;

		$kinds = array_values( array_filter( $kinds, 'is_string' ) );

		if ( array() === $kinds ) {
			return 0;
		}

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );
		$occurrences  = Schema::table( 'occurrences' );

		list( $whereSql, $params ) = $this->cleanableWhere( $kinds, $occurrences );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		// Пустые записи переводов мешают проверке «перевода нет вовсе».
		$wpdb->query(
			$wpdb->prepare(
				"DELETE t FROM {$translations} t
				 INNER JOIN {$sources} s ON s.id = t.source_id
				 WHERE ({$whereSql})
					AND (t.translated_text IS NULL OR t.translated_text = '')",
				$params
			)
		);

		// Места использования — следом за строками, иначе останутся сироты.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE o FROM {$occurrences} o
				 INNER JOIN {$sources} s ON s.id = o.source_id
				 WHERE ({$whereSql})
					AND NOT EXISTS (SELECT 1 FROM {$translations} t WHERE t.source_id = s.id)",
				$params
			)
		);

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE s FROM {$sources} s
				 WHERE ({$whereSql})
					AND NOT EXISTS (SELECT 1 FROM {$translations} t WHERE t.source_id = s.id)",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		// Список translation blocks кэшируется в опции — он мог измениться.
		$this->flushBlockHashes();

		return max( 0, (int) $deleted );
	}

	/**
	 * Условие `WHERE`, отличающее ссылки от прочих атрибутов при чистке.
	 *
	 * Ссылки хранятся с тем же `kind = 'attribute'`, что и `alt`/`title` —
	 * различает их только `attribute_name = 'href'` в местах использования
	 * (см. TYPE_LINK). Без этого разделения кнопка на вкладке «Ссылки»
	 * чистила бы «Контент» целиком (обычный `kind IN (...)` совпал бы с
	 * любым атрибутом), а кнопка на «Контенте» — заодно стирала бы адреса,
	 * которые владелец сайта только что задал на вкладке «Ссылки». Это не
	 * гипотетический случай: ровно так это и произошло на живом сайте.
	 *
	 * @param list<string> $kinds       Виды из cleanableKinds().
	 * @param string       $occurrences Полное имя таблицы occurrences.
	 * @return array{0: string, 1: list<string>}
	 */
	private function cleanableWhere( array $kinds, string $occurrences ): array {
		$isLinkCleanup = in_array( self::TYPE_LINK, $kinds, true );
		$plainKinds    = array_values( array_diff( $kinds, array( self::TYPE_LINK ) ) );

		$conditions = array();
		$params     = array();

		if ( $isLinkCleanup ) {
			$conditions[] = '(s.kind = %s AND EXISTS ('
				. "SELECT 1 FROM {$occurrences} o7 WHERE o7.source_id = s.id AND o7.attribute_name = %s"
				. '))';
			$params[] = self::TYPE_ATTRIBUTE;
			$params[] = self::LINK_ATTRIBUTE;
		}

		if ( array() !== $plainKinds ) {
			$placeholders = implode( ',', array_fill( 0, count( $plainKinds ), '%s' ) );
			$clause       = "s.kind IN ({$placeholders})";

			if ( in_array( self::TYPE_ATTRIBUTE, $plainKinds, true ) ) {
				// Ссылки трогает только ветка выше, здесь их вычитаем явно.
				$clause .= ' AND NOT (s.kind = %s AND EXISTS ('
					. "SELECT 1 FROM {$occurrences} o8 WHERE o8.source_id = s.id AND o8.attribute_name = %s"
					. '))';
			}

			$conditions[] = "({$clause})";
			$params       = array_merge( $params, $plainKinds );

			if ( in_array( self::TYPE_ATTRIBUTE, $plainKinds, true ) ) {
				$params[] = self::TYPE_ATTRIBUTE;
				$params[] = self::LINK_ATTRIBUTE;
			}
		}

		return array( implode( ' OR ', $conditions ), $params );
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
	 * Несколько строк по идентификаторам одним запросом.
	 *
	 * Нужен массовому переводу записи: там десятки строк, а не одна, и
	 * запрос на каждую нарушил бы тот же бюджет «3–5 запросов на операцию»,
	 * что и во всём остальном плагине.
	 *
	 * Хеши отдаются в hex, а не сырыми байтами — `SELECT *` тут не годится:
	 * `uniq_hash`/`source_hash` в таблице binary(32), и вызывающему коду
	 * (сопоставление по хешу, а не по id) нужен ровно тот же hex-вид, что и
	 * у lookup()/idsByHashes(), а не сырые байты, которые пришлось бы
	 * переводить в hex отдельно на каждом месте использования.
	 *
	 * @param list<int> $ids Идентификаторы.
	 * @return array<int, array<string, mixed>> Ключ — id.
	 */
	public function findMany( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table  = Schema::table( 'sources' );
		$result = array();

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, source_locale, kind, source_text, last_seen_at,
							LOWER(HEX(source_hash)) AS source_hash,
							LOWER(HEX(context_hash)) AS context_hash,
							LOWER(HEX(uniq_hash)) AS uniq_hash
					 FROM {$table} WHERE id IN ({$placeholders})",
					$chunk
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

			foreach ( (array) $rows as $row ) {
				$result[ (int) $row['id'] ] = $row;
			}
		}

		return $result;
	}

	/**
	 * Область поиска: все строки сайта.
	 */
	public const SCOPE_ALL = '';

	/**
	 * Область поиска: общие элементы интерфейса.
	 *
	 * Меню, шапка, подвал, виджеты — то, что встречается больше чем на одной
	 * записи. Отдельного признака в БД для них нет и не нужно: «общий элемент»
	 * — это то, что найдено на нескольких страницах, и данные об этом уже
	 * лежат в таблице occurrences.
	 */
	public const SCOPE_GLOBAL = 'global';

	/**
	 * Область поиска: строки одной записи.
	 */
	public const SCOPE_OBJECT = 'object';

	/**
	 * Тип строки: любой.
	 */
	public const TYPE_ALL = '';

	/**
	 * Тип строки: обычный текст.
	 */
	public const TYPE_TEXT = Segment::KIND_TEXT;

	/**
	 * Тип строки: значение HTML-атрибута — кроме meta-тегов SEO/GEO, у них
	 * свой тип (см. TYPE_SEO).
	 */
	public const TYPE_ATTRIBUTE = Segment::KIND_ATTRIBUTE;

	/**
	 * Тип строки: translation block (абзац с разметкой внутри).
	 */
	public const TYPE_BLOCK = Segment::KIND_HTML_BLOCK;

	/**
	 * Тип строки: SEO/GEO — то, что не видно на странице напрямую, а нужно
	 * поисковикам и превью в соцсетях: meta description, Open Graph,
	 * Twitter Card и текстовые поля JSON-LD.
	 *
	 * В хранилище это не один `kind`, а два: `seo` (поля JSON-LD, у них
	 * узел вне DOM) и `attribute` с `attribute_name = content` — meta-теги
	 * извлекаются как атрибут `content`, другого признака у них в таблице
	 * `sources` нет. Второе условие ниже отличает их от обычных атрибутов
	 * вроде `alt` или `placeholder`.
	 */
	public const TYPE_SEO = 'seo';

	/**
	 * Тип строки: содержимое сайта — всё, кроме SEO/GEO и строк интерфейса.
	 *
	 * Нужен вкладке «Контент»: без него «все типы» означало бы буквально
	 * всё, и в список содержимого попали бы и meta-теги, и строки темы из
	 * gettext-контура, у которых свои отдельные экраны. Перечисляются
	 * именно виды строк из DOM, а `gettext` в список не входит — так
	 * новый вид, если он когда-нибудь появится, тоже не просочится сюда
	 * молча.
	 */
	public const TYPE_CONTENT = 'content';

	/**
	 * Тип строки: адрес ссылки (`href`).
	 *
	 * Живёт отдельной вкладкой, а не внутри «Контента», по объёму: ссылок
	 * на сайте сотни, и вперемешку с текстом статей они делают список
	 * нечитаемым. Здесь же они выстраиваются в понятный перечень «куда
	 * ведёт эта ссылка на этом языке».
	 */
	public const TYPE_LINK = 'link';

	/**
	 * Имя атрибута, по которому в местах использования опознаётся ссылка.
	 */
	private const LINK_ATTRIBUTE = 'href';

	/**
	 * Постраничный список строк с переводом на выбранный язык — для админки.
	 *
	 * @param array{locale: string, status?: string, search?: string, scope?: string, object_id?: int, type?: string, page?: int, per_page?: int} $args Фильтры.
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

		$occurrences = Schema::table( 'occurrences' );
		$scope       = (string) ( $args['scope'] ?? self::SCOPE_ALL );
		$objectId    = (int) ( $args['object_id'] ?? 0 );

		/*
		 * COUNT(DISTINCT object_id) не считает NULL: строки, найденные там,
		 * где записи нет вовсе (архивы, поиск, 404), дают 0 и попадают
		 * в общие элементы — это и есть верное для них место.
		 */
		$distinctObjects = "(SELECT COUNT(DISTINCT o.object_id) FROM {$occurrences} o WHERE o.source_id = s.id)";

		if ( self::SCOPE_GLOBAL === $scope ) {
			$where[] = $distinctObjects . ' <> 1';
		} elseif ( self::SCOPE_OBJECT === $scope && $objectId > 0 ) {
			// Только то, что принадлежит именно этой записи: общие элементы
			// сайта показываются в своей вкладке и здесь только мешали бы.
			$where[]  = "EXISTS (SELECT 1 FROM {$occurrences} o2 WHERE o2.source_id = s.id AND o2.object_id = %d)";
			$params[] = $objectId;
			$where[]  = $distinctObjects . ' = 1';
		}

		$type = (string) ( $args['type'] ?? self::TYPE_ALL );

		if ( self::TYPE_SEO === $type ) {
			$where[] = "(s.kind = %s OR (s.kind = %s AND EXISTS ("
				. "SELECT 1 FROM {$occurrences} o3 WHERE o3.source_id = s.id AND o3.attribute_name = %s"
				. ')))';
			$params[] = Segment::KIND_SEO;
			$params[] = Segment::KIND_ATTRIBUTE;
			$params[] = 'content';
		} elseif ( self::TYPE_ATTRIBUTE === $type ) {
			// Meta-теги (attribute_name = content) — это TYPE_SEO, адреса
			// (href) — TYPE_LINK. Здесь их не показываем, иначе одна и та же
			// строка встречалась бы в двух фильтрах сразу и запутывала
			// счётчик найденных строк.
			$where[] = '(s.kind = %s AND NOT EXISTS ('
				. "SELECT 1 FROM {$occurrences} o3 WHERE o3.source_id = s.id AND o3.attribute_name IN (%s, %s)"
				. '))';
			$params[] = Segment::KIND_ATTRIBUTE;
			$params[] = 'content';
			$params[] = self::LINK_ATTRIBUTE;
		} elseif ( in_array( $type, array( self::TYPE_TEXT, self::TYPE_BLOCK ), true ) ) {
			$where[]  = 's.kind = %s';
			$params[] = $type;
		} elseif ( self::TYPE_CONTENT === $type ) {
			/*
			 * Содержимое сайта: виды из DOM, минус meta-теги (они SEO/GEO)
			 * и минус адреса ссылок (они TYPE_LINK). Без второго исключения
			 * сотни адресов утопили бы текст статей — ровно ради этого
			 * ссылки и вынесены на свою вкладку.
			 */
			$where[] = '(s.kind IN (%s, %s, %s) AND NOT EXISTS ('
				. "SELECT 1 FROM {$occurrences} o5 WHERE o5.source_id = s.id AND o5.attribute_name IN (%s, %s)"
				. '))';
			$params[] = self::TYPE_TEXT;
			$params[] = self::TYPE_ATTRIBUTE;
			$params[] = self::TYPE_BLOCK;
			$params[] = 'content';
			$params[] = self::LINK_ATTRIBUTE;
		} elseif ( self::TYPE_LINK === $type ) {
			$where[] = '(s.kind = %s AND EXISTS ('
				. "SELECT 1 FROM {$occurrences} o6 WHERE o6.source_id = s.id AND o6.attribute_name = %s"
				. '))';
			$params[] = Segment::KIND_ATTRIBUTE;
			$params[] = self::LINK_ATTRIBUTE;
		}

		$whereSql = implode( ' AND ', $where );
		$select   = "s.id, s.kind, s.source_text, s.last_seen_at,
						t.translated_text, t.status, t.updated_at,
						(SELECT o4.attribute_name FROM {$occurrences} o4
						 WHERE o4.source_id = s.id AND o4.attribute_name IS NOT NULL LIMIT 1) AS attribute_name";
		$from     = "FROM {$sources} s LEFT JOIN {$translations} t ON t.source_id = s.id AND t.target_locale = %s";

		if ( '' !== $search ) {
			return $this->paginateBySearchPhrase( $select, $from, $whereSql, $params, $search, $perPage, $offset );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) {$from} WHERE {$whereSql}",
				$params
			)
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select} {$from} WHERE {$whereSql} ORDER BY s.id DESC LIMIT %d OFFSET %d",
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
	 * Поиск по фразе с границами слова, а не по произвольной подстроке.
	 *
	 * `LIKE '%текст%'` считает совпадением любое вхождение — на сайте про
	 * AI поиск «AI» находил бы её и внутри «det**ai**l», «dom**ai**n»,
	 * «expl**ai**n»: список результатов тонул в строках, где искомой фразы
	 * на самом деле нет, только случайные буквы совпали. Нужна фраза как
	 * таковая, ограниченная не-буквами по краям (пробел, знак препинания,
	 * начало/конец строки) — «AI» находит «AI Act», но не «domain».
	 *
	 * SQL для этого не подходит переносимо: `\b` в MySQL REGEXP по
	 * умолчанию понимает границу слова только для латиницы и цифр
	 * (`[A-Za-z0-9_]`), а сайт в основном на кириллице — «плагин» вообще
	 * не нашёл бы собственных границ. Правильная Unicode-граница есть у
	 * MySQL 8 (движок ICU), но не гарантирована на MariaDB и старых 5.x,
	 * которые всё ещё держат часть хостингов, — а PHP даёт то же самое
	 * через `\p{L}`/`\p{N}` в PCRE везде одинаково, без сюрпризов между
	 * версиями сервера БД.
	 *
	 * Поэтому остальные фильтры (статус, где встречается, тип) по-прежнему
	 * работают в SQL, а фразу проверяет уже PHP — ценой того, что весь
	 * отфильтрованный по остальным условиям набор строк приходится
	 * прочитать целиком, без `LIMIT`. Для масштаба этого экрана (сотни,
	 * не миллионы строк на язык) это дешевле, чем кажется, и надёжнее,
	 * чем гадать, на какой версии MySQL окажется конкретный хостинг.
	 *
	 * @param string               $select   Список колонок `SELECT`.
	 * @param string               $from     `FROM ... LEFT JOIN ...` с одним `%s` под локаль.
	 * @param string               $whereSql Условие `WHERE` без фразы поиска.
	 * @param list<string|int>     $params   Параметры под `$whereSql` (локаль первой).
	 * @param string               $search   Искомая фраза.
	 * @param int                  $perPage  Строк на странице.
	 * @param int                  $offset   Смещение страницы.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private function paginateBySearchPhrase(
		string $select,
		string $from,
		string $whereSql,
		array $params,
		string $search,
		int $perPage,
		int $offset
	): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$candidates = $wpdb->get_results(
			$wpdb->prepare( "SELECT {$select} {$from} WHERE {$whereSql} ORDER BY s.id DESC", $params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$matching = array_values(
			array_filter(
				is_array( $candidates ) ? $candidates : array(),
				static fn( array $row ): bool =>
					self::matchesSearchPhrase( (string) $row['source_text'], $search )
					|| self::matchesSearchPhrase( (string) ( $row['translated_text'] ?? '' ), $search )
			)
		);

		return array(
			'items' => array_slice( $matching, $offset, $perPage ),
			'total' => count( $matching ),
		);
	}

	/**
	 * Содержит ли строка искомую фразу как таковую — не как часть другого
	 * слова. Чистая функция.
	 *
	 * `\p{L}`/`\p{N}` — буква или цифра ЛЮБОГО алфавита в Unicode, не
	 * только латиница: граница проверяется одинаково что для «AI», что
	 * для «плагин».
	 *
	 * Граница нужна только с той стороны, где сама искомая фраза
	 * заканчивается буквой или цифрой. Без этого уточнения поиск адреса
	 * `/blog/en/` не находил ничего: фраза начинается на `/`, и слева от
	 * этого слэша в `centerai.eu/blog/en/…` стоит буква «u» — формально
	 * требование «перед фразой не буква» не выполнялось, хотя сама фраза
	 * никакой буквой не начинается и защищать её край было не от чего.
	 * Граница защищает СЛОВО от слияния с соседними буквами — у фразы,
	 * которая сама начинается или кончается знаком препинания, сливаться
	 * попросту не с чем.
	 *
	 * Публичная — не ради вызова снаружи, а чтобы саму логику границы
	 * можно было проверить тестом напрямую, без `$wpdb`.
	 *
	 * @param string $haystack Текст, в котором ищем.
	 * @param string $search   Искомая фраза.
	 */
	public static function matchesSearchPhrase( string $haystack, string $search ): bool {
		if ( '' === $haystack || '' === $search ) {
			return false;
		}

		$quoted = preg_quote( $search, '/' );
		$before = 1 === preg_match( '/^[\p{L}\p{N}]/u', $search ) ? '(?<![\p{L}\p{N}])' : '';
		$after  = 1 === preg_match( '/[\p{L}\p{N}]$/u', $search ) ? '(?![\p{L}\p{N}])' : '';

		return 1 === preg_match( "/{$before}{$quoted}{$after}/iu", $haystack );
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
