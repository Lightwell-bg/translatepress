<?php
/**
 * Разовая чистка мусорных строк словаря, накопленных прошлыми версиями.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

use WpMlp\Rendering\EditorContext;
use WpMlp\Rendering\LinkTarget;
use WpMlp\Rendering\Segment;
use WpMlp\Support\Hash;

defined( 'ABSPATH' ) || exit;

/**
 * Удаляет из словаря строки, которые сборщик больше не создаёт.
 *
 * Две причины, обе найдены на живом сайте:
 *
 * - адреса ссылок с параметрами предпросмотра (`mlp_editor`, `mlp_nonce`,
 *   `mlp_channel`): тема переносила их в ссылки пагинации, и каждый сеанс
 *   редактора заводил «новые» адреса с чужим nonce;
 * - `title` вида «Заголовок записи 10»: тема дописывает к заголовку номер
 *   карточки в списке, и такая строка дублирует заголовок записи с цифрой на
 *   конце. Заголовок «без цифры» ищется по `uniq_hash` (как текст страницы), а
 *   не по колонке `kind`: строка часто заведена из `og:title` и хранится с
 *   `kind = attribute`, хотя хешируется как текст.
 *
 * Чистка разовая: версия лежит в своей опции, поэтому повторный вызов (и
 * любой следующий заход в админку) ничего не делает. Трогаются только строки
 * БЕЗ сохранённого перевода — работа владельца сайта не теряется никогда.
 * Если строка всё же была переведена, она остаётся: перевод стоит дороже
 * чистоты списка.
 *
 * За один вызов обрабатывается не больше одной пачки строк; место, где
 * остановились, хранится в опции-курсоре. Версия записывается только когда
 * очередная выборка кандидатов оказалась пустой. Любая ошибка БД прерывает
 * работу без записи версии: чистка повторится при следующем заходе.
 *
 * Имя атрибута в таблице источников не хранится (там только `kind =
 * attribute`), оно есть в местах использования, поэтому «это именно href» и
 * «это именно title» проверяется по ним: подходит строка, у которой все
 * места использования — с нужным атрибутом.
 */
final class StaleStringsCleanup {

	/**
	 * Опция с номером выполненной чистки.
	 */
	public const OPTION = 'mlp_cleanup_version';

	/**
	 * Опция-курсор: `этап:последний обработанный id`.
	 */
	public const CURSOR_OPTION = 'mlp_cleanup_cursor_v2';

	/**
	 * Курсор прошлой версии чистки: игнорируется и удаляется по завершении.
	 */
	private const LEGACY_CURSOR_OPTION = 'mlp_cleanup_cursor';

	/**
	 * Опция-замок от одновременного запуска в двух запросах.
	 */
	public const LOCK_OPTION = 'mlp_cleanup_lock';

	/**
	 * Через сколько секунд замок считается забытым.
	 */
	public const LOCK_TTL = 300;

	/**
	 * Текущая версия чистки. Увеличить, чтобы прогнать её ещё раз.
	 */
	public const VERSION = 2;

	/**
	 * Сколько строк обрабатывается за один проход.
	 */
	private const BATCH = 500;

	/**
	 * Выполняет одну пачку чистки, если она ещё не завершена.
	 */
	public static function maybeRun(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		if ( ! self::acquireLock() ) {
			return;
		}

		try {
			$result = ( new self() )->run();

			if ( $result['deleted'] > 0 ) {
				// Тот же сброс, что и после ручных удалений: обесценивает кэш
				// переводов и кэш страниц сторонних плагинов.
				( new TranslationCache() )->flush();
			}

			if ( $result['done'] && ! $result['error'] ) {
				update_option( self::OPTION, self::VERSION, true );
				delete_option( self::CURSOR_OPTION );
				delete_option( self::LEGACY_CURSOR_OPTION );
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Берёт замок; забытый (старше LOCK_TTL) перехватывает.
	 */
	private static function acquireLock(): bool {
		// add_option атомарно возвращает false, если опция уже есть.
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}

		$taken = (int) get_option( self::LOCK_OPTION, 0 );

		if ( time() - $taken <= self::LOCK_TTL ) {
			return false;
		}

		update_option( self::LOCK_OPTION, time(), false );

		return true;
	}

	/**
	 * Обрабатывает одну пачку.
	 *
	 * @return array{deleted: int, done: bool, error: bool} Сколько удалено;
	 *         закончена ли вся чистка (кандидатов не осталось); была ли ошибка БД.
	 */
	public function run(): array {
		$cursor = (string) get_option( self::CURSOR_OPTION, 'href:0' );
		$parts  = explode( ':', $cursor );
		$phase  = 'title' === $parts[0] ? 'title' : 'href';
		$lastId = isset( $parts[1] ) ? max( 0, (int) $parts[1] ) : 0;

		$batch = 'href' === $phase ? $this->hrefBatch( $lastId ) : $this->titleBatch( $lastId );

		if ( null === $batch ) {
			return array( 'deleted' => 0, 'done' => false, 'error' => true );
		}

		if ( array() === $batch['rows'] ) {
			// Этап исчерпан: после адресов идут заголовки, после них — конец.
			if ( 'href' === $phase ) {
				update_option( self::CURSOR_OPTION, 'title:0', false );

				return array( 'deleted' => 0, 'done' => false, 'error' => false );
			}

			return array( 'deleted' => 0, 'done' => true, 'error' => false );
		}

		$deleted = 0;

		if ( array() !== $batch['ids'] ) {
			$count = $this->deleteByIds( $batch['ids'] );

			if ( null === $count ) {
				return array( 'deleted' => 0, 'done' => false, 'error' => true );
			}

			$deleted = $count;
		}

		update_option( self::CURSOR_OPTION, $phase . ':' . $batch['last'], false );

		return array( 'deleted' => $deleted, 'done' => false, 'error' => false );
	}

	/**
	 * Пачка адресов с параметрами предпросмотра.
	 *
	 * Выборка по LIKE — только грубый фильтр; решение принимает
	 * `LinkTarget::normalize()`: адрес, который он не меняет (например,
	 * `/docs/#mlp_editor=1` — это якорь), не трогается.
	 *
	 * @param int $lastId Курсор.
	 * @return array{rows: list<array<string, mixed>>, ids: list<int>, last: int}|null Null при ошибке БД.
	 */
	private function hrefBatch( int $lastId ): ?array {
		global $wpdb;

		$sources = Schema::table( 'sources' );
		$likes   = array();
		$args    = array();

		foreach ( array( EditorContext::QUERY_FLAG, EditorContext::QUERY_NONCE, EditorContext::QUERY_CHANNEL ) as $name ) {
			$likes[] = 's.source_text LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $name . '=' ) . '%';
		}

		$likeSql = implode( ' OR ', $likes );

		list( $onlySql, $onlyArgs )                 = $this->onlyAttributeSql( 'href' );
		list( $untranslatedSql, $untranslatedArgs ) = $this->untranslatedSql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- фрагменты WHERE собраны из литералов с плейсхолдерами %i/%s, значения идут параметрами; анализатор не видит плейсхолдеры внутри переменных и не считает параметры в array_merge().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.source_text FROM %i s
				 WHERE s.kind = %s AND s.id > %d AND ({$likeSql})
					AND {$onlySql}
					AND {$untranslatedSql}
				 ORDER BY s.id LIMIT %d",
				array_merge( array( $sources, 'attribute', $lastId ), $args, $onlyArgs, $untranslatedArgs, array( self::BATCH ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery

		if ( $this->failed( $rows ) ) {
			return null;
		}

		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$ids  = array();
		$last = $lastId;

		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$text = (string) $row['source_text'];
			$last = max( $last, $id );

			if ( LinkTarget::normalize( $text ) !== $text ) {
				$ids[] = $id;
			}
		}

		return array(
			'rows' => $rows,
			'ids'  => $ids,
			'last' => $last,
		);
	}

	/**
	 * Пачка `title` вида «Текст 10», у которых «Текст» есть в словаре текстом.
	 *
	 * @param int $lastId Курсор.
	 * @return array{rows: list<array<string, mixed>>, ids: list<int>, last: int}|null Null при ошибке БД.
	 */
	private function titleBatch( int $lastId ): ?array {
		global $wpdb;

		$sources = Schema::table( 'sources' );

		list( $onlySql, $onlyArgs )                 = $this->onlyAttributeSql( 'title', false );
		list( $untranslatedSql, $untranslatedArgs ) = $this->untranslatedSql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- фрагменты WHERE собраны из литералов с плейсхолдерами %i/%s, значения идут параметрами; анализатор не видит плейсхолдеры внутри переменных и не считает параметры в array_merge().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.source_locale, s.source_text FROM %i s
				 WHERE s.kind = %s AND s.id > %d AND s.source_text REGEXP %s
					AND {$onlySql}
					AND {$untranslatedSql}
				 ORDER BY s.id LIMIT %d",
				array_merge( array( $sources, 'attribute', $lastId, '[[:space:]][0-9]+$' ), $onlyArgs, $untranslatedArgs, array( self::BATCH ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery

		if ( $this->failed( $rows ) ) {
			return null;
		}

		$rows = is_array( $rows ) ? array_values( $rows ) : array();
		$last = $lastId;
		$cand = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$last = max( $last, $id );

			if ( 1 === preg_match( '/^(.+)\s+\d+$/u', (string) $row['source_text'], $match ) ) {
				$cand[ $id ] = array( (string) $row['source_locale'], $match[1] );
			}
		}

		$existing = $this->existingTextSources( $cand );
		if ( null === $existing ) {
			return null;
		}

		$ids = array();

		foreach ( $cand as $id => $pair ) {
			if ( isset( $existing[ $pair[0] . "\0" . $pair[1] ] ) ) {
				$ids[] = $id;
			}
		}

		return array(
			'rows' => $rows,
			'ids'  => $ids,
			'last' => $last,
		);
	}

	/**
	 * Какие из пар (язык, текст) есть в словаре строкой вида «текст страницы».
	 *
	 * Поиск по уникальному индексу `uniq_hash` (формула сборщика для текста),
	 * одним запросом на пачку; затем точное сравнение языка и текста в PHP —
	 * хеш лишь сужает выборку. Колонка `kind` не важна: важен только хеш.
	 *
	 * @param array<int, array{0: string, 1: string}> $cand Кандидаты: id => [язык, текст без номера].
	 * @return array<string, true>|null Ключи «язык\0текст»; null при ошибке БД.
	 */
	private function existingTextSources( array $cand ): ?array {
		global $wpdb;

		if ( array() === $cand ) {
			return array();
		}

		$sources     = Schema::table( 'sources' );
		$contextHash = Hash::of( '' );
		$hashes      = array();

		foreach ( $cand as $pair ) {
			// Та же формула, что у Extractor::makeSegment() для текста страницы.
			$hashes[ Hash::ofParts( array( $pair[0], Segment::KIND_TEXT, Hash::of( $pair[1] ), $contextHash, '', '', '' ) ) ] = true;
		}

		$hashList = array_keys( $hashes );
		$hashSql  = implode( ',', array_fill( 0, count( $hashList ), 'UNHEX(%s)' ) );

		// Колонка kind не проверяется: строка из og:title хранится как attribute,
		// но хешируется как текст.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- имена таблиц через %i, список плейсхолдеров собран из литералов, значения идут через prepare(); анализатор не считает параметры в array_merge().
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.source_locale, s.source_text, s.id FROM %i s
				 WHERE s.uniq_hash IN ({$hashSql})",
				array_merge( array( $sources ), $hashList )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery

		if ( $this->failed( $found ) ) {
			return null;
		}

		$map = array();

		foreach ( is_array( $found ) ? $found : array() as $row ) {
			// Сама строка-кандидат (с цифрой) за «заголовок без цифры» не считается.
			if ( isset( $row['id'] ) && isset( $cand[ (int) $row['id'] ] ) ) {
				continue;
			}

			$map[ (string) $row['source_locale'] . "\0" . (string) $row['source_text'] ] = true;
		}

		// Точное сравнение: ключ карты совпадёт, только если и язык, и текст идентичны.
		$result = array();

		foreach ( $cand as $pair ) {
			$key = $pair[0] . "\0" . $pair[1];

			if ( isset( $map[ $key ] ) ) {
				$result[ $key ] = true;
			}
		}

		return $result;
	}

	/**
	 * Была ли ошибка БД. Пишет её в журнал.
	 *
	 * @param mixed $result Результат запроса.
	 */
	private function failed( $result ): bool {
		global $wpdb;

		$error = (string) ( $wpdb->last_error ?? '' );

		if ( false !== $result && '' === $error ) {
			return false;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[wp-mlp] StaleStringsCleanup: ' . ( '' !== $error ? $error : 'query returned false' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return true;
	}

	/**
	 * Условие «ни одно место использования строки не относится к другому атрибуту»,
	 * при `$requireOne` — ещё и «есть хотя бы одно место с этим атрибутом».
	 *
	 * Значение атрибута вшито литералом из внутреннего списка (`href`/`title`),
	 * пользовательского ввода тут нет.
	 *
	 * @param string $attribute  `href` или `title`.
	 * @param bool   $requireOne Требовать хотя бы одно место использования.
	 * @return array{0: string, 1: list<string>} SQL с плейсхолдерами `%i`/`%s` и параметры по порядку.
	 */
	private function onlyAttributeSql( string $attribute, bool $requireOne = true ): array {
		$occurrences = Schema::table( 'occurrences' );
		$attribute   = 'href' === $attribute ? 'href' : 'title';

		$none = 'NOT EXISTS (SELECT 1 FROM %i ob WHERE ob.source_id = s.id AND (ob.attribute_name IS NULL OR ob.attribute_name <> %s))';

		if ( ! $requireOne ) {
			// У старых строк мест использования может не быть вовсе.
			return array( '(' . $none . ')', array( $occurrences, $attribute ) );
		}

		return array(
			'(EXISTS (SELECT 1 FROM %i oa WHERE oa.source_id = s.id AND oa.attribute_name = %s)' . ' AND ' . $none . ')',
			array( $occurrences, $attribute, $occurrences, $attribute ),
		);
	}

	/**
	 * Условие «ни одного непустого перевода».
	 *
	 * @return array{0: string, 1: list<string>} SQL с плейсхолдером `%i` и параметры.
	 */
	private function untranslatedSql(): array {
		$translations = Schema::table( 'translations' );

		return array(
			"NOT EXISTS (SELECT 1 FROM %i tr WHERE tr.source_id = s.id AND tr.translated_text IS NOT NULL AND tr.translated_text <> '')",
			array( $translations ),
		);
	}

	/**
	 * Удаляет строки вместе с пустыми записями перевода и местами использования.
	 *
	 * Условие «перевода нет» повторено в самих DELETE: между выборкой и
	 * удалением кто-то мог сохранить перевод, и такая строка не должна пропасть.
	 *
	 * @param list<int> $ids Идентификаторы строк.
	 * @return int|null Сколько строк реально удалено; null при ошибке БД.
	 */
	private function deleteByIds( array $ids ): ?int {
		global $wpdb;

		$sources      = Schema::table( 'sources' );
		$translations = Schema::table( 'translations' );
		$occurrences  = Schema::table( 'occurrences' );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- имена таблиц через %i, список плейсхолдеров собран из литералов, значения идут через prepare(); анализатор не считает параметры в array_merge().
		// Пустые записи перевода (след стёртого текста) переводом не считаются.
		$first = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
				 WHERE source_id IN ({$placeholders}) AND (translated_text IS NULL OR translated_text = '')",
				array_merge( array( $translations ), $ids )
			)
		);

		if ( $this->failed( $first ) ) {
			return null;
		}

		$second = $wpdb->query(
			$wpdb->prepare(
				"DELETE o FROM %i o
				 WHERE o.source_id IN ({$placeholders})
					AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.source_id = o.source_id)",
				array_merge( array( $occurrences ), $ids, array( $translations ) )
			)
		);

		if ( $this->failed( $second ) ) {
			return null;
		}

		$third = $wpdb->query(
			$wpdb->prepare(
				"DELETE s FROM %i s
				 WHERE s.id IN ({$placeholders})
					AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.source_id = s.id)",
				array_merge( array( $sources ), $ids, array( $translations ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery

		if ( $this->failed( $third ) ) {
			return null;
		}

		return max( 0, (int) $third );
	}
}
