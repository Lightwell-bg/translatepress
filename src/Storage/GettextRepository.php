<?php
/**
 * Доступ к gettext-части словаря.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\I18n\GettextKey;
use WpMlp\Support\Hash;
use WpMlp\Support\Locale;

/**
 * Чтение и запись строк с `kind = 'gettext'`.
 *
 * Отдельно от {@see SourceRepository} по двум причинам, и обе — про
 * колонки `domain`/`gettext_context`/`plural_key`. Во-первых, `insertMissing()`
 * там их вообще не заполняет (и не должен: у строк из DOM их нет), а без
 * них gettext-строка теряет свою идентичность — «Ответить» из ядра и
 * «Ответить» из плагина слились бы в одну. Во-вторых, читается gettext
 * иначе: не пачкой хешей по мере обхода DOM, а ЦЕЛИКОМ и заранее — см.
 * {@see overridesFor()}.
 */
final class GettextRepository implements GettextStore {

	/**
	 * Группа объектного кэша.
	 */
	private const CACHE_GROUP = 'mlp_gettext';

	/**
	 * Сколько строк максимум уходит в один INSERT.
	 */
	private const CHUNK = 200;

	/**
	 * Все ручные переопределения gettext-строк для языка, одним запросом.
	 *
	 * Именно ЦЕЛИКОМ, а не по одной строке: фильтр `gettext` срабатывает
	 * сотни, а на тяжёлых страницах тысячи раз за запрос, и запрос к БД на
	 * каждый вызов превратил бы страницу в тысячу запросов. Переопределений
	 * при этом мало по своей природе — это только то, что владелец сайта
	 * поправил руками поверх официального языкового пакета, — так что
	 * карта целиком спокойно живёт в памяти.
	 *
	 * @param string $locale Целевой язык (короткий код, как везде в плагине).
	 * @param int    $cacheVersion Номер версии кэша переводов — часть ключа.
	 * @return array<string, string> Дешёвый ключ GettextKey::lookup() => перевод.
	 */
	public function overridesFor( string $locale, int $cacheVersion ): array {
		if ( ! Locale::isValid( $locale ) ) {
			return array();
		}

		$cacheKey = $cacheVersion . ':' . $locale;
		$cached   = wp_cache_get( $cacheKey, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.source_text, s.domain, s.gettext_context, s.plural_key, t.translated_text
				 FROM {$sources} s
				 INNER JOIN {$translations} t
					ON t.source_id = s.id AND t.target_locale = %s
				 WHERE s.kind = %s
					AND t.translated_text IS NOT NULL
					AND t.translated_text <> ''",
				$locale,
				GettextKey::KIND
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$overrides = array();

		foreach ( (array) $rows as $row ) {
			$key = GettextKey::lookup(
				(string) $row['source_text'],
				(string) ( $row['domain'] ?? '' ),
				(string) ( $row['gettext_context'] ?? '' ),
				null !== $row['plural_key'] ? (int) $row['plural_key'] : null
			);

			$overrides[ $key ] = (string) $row['translated_text'];
		}

		wp_cache_set( $cacheKey, $overrides, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $overrides;
	}

	/**
	 * Домены, которые реально встречались, — для выпадающего фильтра.
	 *
	 * Список строится из самого словаря, а не из перечня установленных
	 * плагинов: в фильтре должно быть только то, по чему действительно
	 * есть что показать.
	 *
	 * @return list<string>
	 */
	public function domains(): array {
		global $wpdb;

		$table = Schema::table( 'sources' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT domain FROM {$table}
				 WHERE kind = %s AND domain IS NOT NULL AND domain <> ''
				 ORDER BY domain ASC",
				GettextKey::KIND
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return array_map( 'strval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Постраничный список gettext-строк с переопределениями — для админки.
	 *
	 * Отдельно от SourceRepository::paginate() ради колонок
	 * `domain`/`gettext_context`/`plural_key`: без них строку не показать
	 * («откуда она» и «какой контекст») и не отличить от одноимённой из
	 * другого плагина.
	 *
	 * @param array{locale: string, domain?: string, status?: string, search?: string, page?: int, per_page?: int} $args Фильтры.
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
		$search  = trim( (string) ( $args['search'] ?? '' ) );
		$domain  = trim( (string) ( $args['domain'] ?? '' ) );
		$status  = (string) ( $args['status'] ?? '' );

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );

		$where  = array( 's.kind = %s' );
		$params = array( $locale, GettextKey::KIND );

		if ( '' !== $domain ) {
			$where[]  = 's.domain = %s';
			$params[] = $domain;
		}

		/*
		 * Фильтровать можно только по тому, что есть в SQL: наше
		 * переопределение либо есть, либо нет. «Перевод из языкового
		 * пакета» в базе не лежит вовсе (он в файлах .mo), поэтому он
		 * остаётся признаком для показа, а не для фильтра, — иначе
		 * счётчик найденных строк разошёлся бы с самой выборкой.
		 */
		if ( self::STATUS_OVERRIDDEN === $status ) {
			$where[] = "(t.id IS NOT NULL AND t.translated_text IS NOT NULL AND t.translated_text <> '')";
		} elseif ( self::STATUS_MISSING === $status ) {
			$where[] = "(t.id IS NULL OR t.translated_text IS NULL OR t.translated_text = '')";
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
				"SELECT s.id, s.source_text, s.domain, s.gettext_context, s.plural_key,
						t.translated_text, t.status, t.updated_at
				 FROM {$sources} s
				 LEFT JOIN {$translations} t ON t.source_id = s.id AND t.target_locale = %s
				 WHERE {$whereSql}
				 ORDER BY s.domain ASC, s.source_text ASC, s.plural_key ASC
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
	 * Фильтр «есть наше переопределение».
	 */
	public const STATUS_OVERRIDDEN = 'overridden';

	/**
	 * Фильтр «нашего переопределения нет».
	 */
	public const STATUS_MISSING = 'missing';

	/**
	 * Заводит gettext-строки, которых ещё нет в словаре.
	 *
	 * Своя реализация вместо SourceRepository::insertMissing() именно ради
	 * трёх колонок: без `domain`/`gettext_context`/`plural_key` строка
	 * теряет то, чем она отличается от одноимённой строки другого плагина.
	 *
	 * @param list<array{msgid: string, domain: string, context: string, plural_key: ?int}> $rows Новые строки.
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
				$msgid     = (string) $row['msgid'];
				$domain    = (string) $row['domain'];
				$context   = (string) $row['context'];
				$pluralKey = $row['plural_key'] ?? null;

				$uniqHash   = GettextKey::uniqHash( $msgid, $domain, $context, $pluralKey );
				$sourceHash = Hash::of( $msgid );

				if ( ! Hash::isValid( $uniqHash ) || ! Hash::isValid( $sourceHash ) ) {
					continue;
				}

				/*
				 * NULLIF по контексту и NULL по plural_key: в колонках должно
				 * лежать именно NULL, когда контекста и множественного числа
				 * нет, — так же, как у строк из DOM. На uniq_hash это не
				 * влияет: он посчитан выше из пустых строк, а Hash::ofParts()
				 * приводит NULL и '' к одному значению (см. HashCompatibilityTest).
				 */
				$values[] = '(%s, %s, %s, UNHEX(%s), UNHEX(%s), %s, NULLIF(%s, \'\'), '
					. ( null === $pluralKey ? 'NULL' : '%d' )
					. ', UNHEX(%s), %s, %s)';

				$args[] = GettextKey::SOURCE_LOCALE;
				$args[] = GettextKey::KIND;
				$args[] = $msgid;
				$args[] = $sourceHash;
				$args[] = Hash::of( '' );
				$args[] = $domain;
				$args[] = $context;

				if ( null !== $pluralKey ) {
					$args[] = (int) $pluralKey;
				}

				$args[] = $uniqHash;
				$args[] = $now;
				$args[] = $now;
			}

			if ( array() === $values ) {
				continue;
			}

			$valuesSql = implode( ', ', $values );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$inserted += (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
						(source_locale, kind, source_text, source_hash, context_hash, domain, gettext_context, plural_key, uniq_hash, created_at, last_seen_at)
					 VALUES {$valuesSql}",
					$args
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		return $inserted;
	}
}
