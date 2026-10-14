<?php
/**
 * Разовая починка типа и идентификатора объекта в местах использования строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Прошлые версии писали в `object_id` результат `get_queried_object_id()` для
 * любой страницы, а `object_type` ставили всегда `url`. На архивах рубрик,
 * меток и авторов это id рубрики или пользователя, и он мог совпасть с id
 * какой-нибудь записи: чужие строки считались «строками записи», а
 * «Включить все полностью переведённые» могло включить посторонний материал.
 *
 * Теперь `object_id` пишется только на странице одиночной записи, и тип у
 * таких вхождений `post`. Для старых данных эта миграция делает так:
 *
 * - вхождение `url` с непустым `object_id`, где этот id — опубликованная
 *   запись подходящего типа, получает тип `post`;
 * - у остальных вхождений `url` с непустым `object_id` идентификатор
 *   обнуляется (это были рубрики, авторы, удалённые или неопубликованные записи).
 *
 * Восстановить адрес записи и сверить с `url_hash` надёжно нельзя: хеш считан
 * от пути, как он пришёл в запросе (с языковым префиксом, пагинацией,
 * произвольными правилами переписывания), и пересчёт по `get_permalink()` часть
 * настоящих вхождений потерял бы, а часть чужих всё равно пропустил. Поэтому
 * остаточный риск остаётся: если id рубрики или автора совпал с id
 * опубликованной записи, такие старые вхождения будут считаться записью.
 * Новые данные этой ошибки не содержат, а при следующем заходе на ту же
 * страницу вхождение само исправляется (OccurrenceRepository::insertMany()
 * обновляет тип и идентификатор по `uniq_hash`).
 *
 * Работа устроена как у StaleStringsCleanup: версия лежит в своей опции,
 * обрабатывается одна пачка за вызов (по диапазону первичного ключа),
 * позиция хранится в опции-курсоре, любая ошибка БД прерывает работу без записи
 * версии, а от одновременного запуска защищает опция-замок. Повторный запуск
 * ничего не меняет.
 */
final class OccurrenceObjectTypeMigration {

	/**
	 * Опция с номером выполненной миграции.
	 */
	public const OPTION = 'mlp_objtype_version';

	/**
	 * Опция-курсор: последний обработанный id вхождения.
	 */
	public const CURSOR_OPTION = 'mlp_objtype_cursor';

	/**
	 * Опция-замок от одновременного запуска в двух запросах.
	 */
	public const LOCK_OPTION = 'mlp_objtype_lock';

	/**
	 * Через сколько секунд замок считается забытым.
	 */
	public const LOCK_TTL = 300;

	/**
	 * Текущая версия миграции. Увеличить, чтобы прогнать её ещё раз.
	 */
	public const VERSION = 1;

	/**
	 * Сколько вхождений (по первичному ключу) обрабатывается за один проход.
	 */
	private const BATCH = 1000;

	/**
	 * Выполняет одну пачку, если миграция ещё не завершена.
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

			if ( $result['done'] && ! $result['error'] ) {
				update_option( self::OPTION, self::VERSION, true );
				delete_option( self::CURSOR_OPTION );
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
	 * @return array{done: bool, error: bool} Закончена ли вся миграция (вхождений дальше нет); была ли ошибка БД.
	 */
	public function run(): array {
		global $wpdb;

		$occurrences = Schema::table( 'occurrences' );
		$posts       = $wpdb->posts;
		$cursor      = max( 0, (int) get_option( self::CURSOR_OPTION, 0 ) );

		// Верхняя граница пачки: BATCH-й id после курсора (диапазон по первичному ключу).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$upper = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(b.id) FROM (SELECT id FROM {$occurrences} WHERE id > %d ORDER BY id LIMIT " . self::BATCH . ') b',
				$cursor
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $this->failed( $upper ) ) {
			return array( 'done' => false, 'error' => true );
		}

		$upper = (int) $upper;

		if ( $upper <= $cursor ) {
			return array( 'done' => true, 'error' => false );
		}

		$types = PostLanguageVisibility::eligibleTypes();

		if ( array() !== $types ) {
			$typeMarks = implode( ',', array_fill( 0, count( $types ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$retag = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$occurrences} o SET o.object_type = %s
					 WHERE o.id > %d AND o.id <= %d AND o.object_type = 'url' AND o.object_id IS NOT NULL
						AND EXISTS (SELECT 1 FROM {$posts} p WHERE p.ID = o.object_id AND p.post_status = 'publish' AND p.post_type IN ({$typeMarks}))",
					array_merge( array( OccurrenceRepository::OBJECT_TYPE_POST, $cursor, $upper ), $types )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

			if ( $this->failed( $retag ) ) {
				return array( 'done' => false, 'error' => true );
			}
		}

		// Всё, что осталось типом `url` с идентификатором, к записи не относится.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$clear = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$occurrences} SET object_id = NULL
				 WHERE id > %d AND id <= %d AND object_type = 'url' AND object_id IS NOT NULL",
				$cursor,
				$upper
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( $this->failed( $clear ) ) {
			return array( 'done' => false, 'error' => true );
		}

		update_option( self::CURSOR_OPTION, $upper, false );

		return array( 'done' => false, 'error' => false );
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
			error_log( '[wp-mlp] OccurrenceObjectTypeMigration: ' . ( '' !== $error ? $error : 'query returned false' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return true;
	}
}
