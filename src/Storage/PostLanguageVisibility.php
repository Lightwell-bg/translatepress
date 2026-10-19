<?php
/**
 * Ручной выбор: на каких дополнительных языках запись видна посетителям.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Storage;

use LingoCanvas\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Флаг «запись показывается на языке X» в метаполях записи.
 *
 * Один ключ на язык: `_lingocanvas_visible_<язык>` со значением `1`. Выключенный
 * флаг — не `0`, а отсутствие ключа: так выборку «видна на языке» можно
 * строить через `meta_query` с `EXISTS` (индекс по ключу), а не сравнением
 * значений. Код языка в ключе — только `[a-z0-9_]` (`pt-BR` → `pt_br`,
 * `es-419` → `es_419`):
 * имя метаполя не должно зависеть от символов, которые в нём неуместны.
 *
 * Почему так, а не «переведено ли»: признак «запись переведена» из данных не
 * вывести — общие строки (названия рубрик в карточках, подвал) есть на каждой
 * записи, и любая запись выглядела бы переведённой. Решает владелец сайта.
 *
 * Метаполя остаются, когда язык удалён из настроек: они безвредны и вернутся
 * в силу, если язык добавят снова с тем же кодом. Удаляются только при
 * удалении плагина с галочкой «удалить все данные» (uninstall.php).
 *
 * Любое изменение флага сбрасывает кэш карты сайта и кэш страниц сторонних
 * плагинов целиком: запись может быть в меню, виджетах, списках и «похожих»
 * на десятках страниц, точечно их не найти.
 */
final class PostLanguageVisibility {

	/**
	 * Префикс ключа метаполя (`_lingocanvas_visible_`); на нём же строится
	 * удаление при uninstall.
	 */
	public static function metaPrefix(): string {
		return '_lingocanvas_visible_';
	}

	/**
	 * Значение включённого флага.
	 */
	public const VALUE = '1';

	/**
	 * Память в пределах запроса: "ключ метаполя:id записи" => видна ли.
	 *
	 * @var array<string, bool>
	 */
	private array $memo = array();

	/**
	 * Записи, чьи метаполя уже прогреты в этом запросе: id => true.
	 *
	 * @var array<int, true>
	 */
	private array $primed = array();

	/**
	 * @param TranslationCache     $cache       Сброс кэша карты сайта и страниц при изменении.
	 * @param OccurrenceRepository $occurrences Откуда берутся «переведённые полностью» записи.
	 */
	public function __construct(
		private readonly TranslationCache $cache,
		private readonly OccurrenceRepository $occurrences
	) {
	}

	/**
	 * Ключ метаполя для языка; пустая строка, если из кода языка ничего не осталось.
	 *
	 * @param string $locale Код языка.
	 */
	public static function metaKey( string $locale ): string {
		$suffix = (string) preg_replace( '/[^a-z0-9_]/', '', str_replace( '-', '_', strtolower( trim( $locale ) ) ) );

		return '' === $suffix ? '' : self::metaPrefix() . $suffix;
	}

	/**
	 * Видна ли запись на языке.
	 *
	 * @param int    $postId Запись.
	 * @param string $locale Код языка.
	 */
	public function isVisible( int $postId, string $locale ): bool {
		$key = self::metaKey( $locale );

		if ( '' === $key || $postId <= 0 ) {
			return false;
		}

		$memoKey = $key . ':' . $postId;

		if ( ! array_key_exists( $memoKey, $this->memo ) ) {
			$this->memo[ $memoKey ] = self::VALUE === (string) get_post_meta( $postId, $key, true );
		}

		return $this->memo[ $memoKey ];
	}

	/**
	 * Прогревает метаполя пачки записей одним запросом.
	 *
	 * Нужно перед проверкой многих записей подряд (меню, карта сайта):
	 * без этого каждая проверка была бы отдельным запросом к БД.
	 *
	 * @param list<int> $ids Записи.
	 */
	public function prime( array $ids ): void {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					fn( int $id ): bool => $id > 0 && ! isset( $this->primed[ $id ] )
				)
			)
		);

		if ( array() === $ids ) {
			return;
		}

		foreach ( $ids as $id ) {
			$this->primed[ $id ] = true;
		}

		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $ids );
		}
	}

	/**
	 * Какие из записей видны на языке.
	 *
	 * @param list<int> $ids    Записи.
	 * @param string    $locale Код языка.
	 * @return list<int>
	 */
	public function visibleAmong( array $ids, string $locale ): array {
		$this->prime( $ids );

		return array_values( array_filter( array_map( 'intval', $ids ), fn( int $id ): bool => $this->isVisible( $id, $locale ) ) );
	}

	/**
	 * Состояние флага у пачки записей.
	 *
	 * @param list<int> $ids    Записи.
	 * @param string    $locale Код языка.
	 * @return array<int, bool> Идентификатор записи => видна ли.
	 */
	public function states( array $ids, string $locale ): array {
		$this->prime( $ids );

		$result = array();

		foreach ( $ids as $id ) {
			$result[ (int) $id ] = $this->isVisible( (int) $id, $locale );
		}

		return $result;
	}

	/**
	 * Сколько записей включено на языке.
	 *
	 * @param string $locale Код языка.
	 */
	public function count( string $locale ): int {
		global $wpdb;

		$key = self::metaKey( $locale );

		if ( '' === $key ) {
			return 0;
		}

		/*
		 * Считаются только опубликованные записи подходящих типов: флаг мог
		 * остаться у черновика, корзины или вложения, а посетитель их всё равно
		 * не увидит, и счётчик «показано на языке» не должен завышаться.
		 */
		$types = self::eligibleTypes();

		if ( array() === $types ) {
			return 0;
		}

		$typeMarks = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- таблицы через %i, список типов собран из литералов %s; анализатор не считает параметры в array_merge().
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id) FROM %i pm
				 INNER JOIN %i p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status = 'publish' AND p.post_type IN ({$typeMarks})",
				array_merge( array( $wpdb->postmeta, $wpdb->posts, $key, self::VALUE ), $types )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return (int) $count;
	}

	/**
	 * Включает или выключает запись на языке.
	 *
	 * @param int    $postId  Запись.
	 * @param string $locale  Код языка.
	 * @param bool   $visible Показывать ли.
	 * @return bool Изменилось ли состояние.
	 */
	public function set( int $postId, string $locale, bool $visible ): bool {
		return $this->setMany( array( $postId ), $locale, $visible ) > 0;
	}

	/**
	 * Включает или выключает пачку записей на языке; кэши сбрасываются один раз.
	 *
	 * @param list<int> $ids     Записи.
	 * @param string    $locale  Код языка.
	 * @param bool      $visible Показывать ли.
	 * @return int Сколько записей изменили состояние.
	 */
	public function setMany( array $ids, string $locale, bool $visible ): int {
		$key = self::metaKey( $locale );

		if ( '' === $key ) {
			return 0;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );

		$this->prime( $ids );

		$changed = 0;

		foreach ( $ids as $id ) {
			if ( $this->isVisible( $id, $locale ) === $visible ) {
				continue;
			}

			if ( $visible ) {
				update_post_meta( $id, $key, self::VALUE );
			} else {
				delete_post_meta( $id, $key );
			}

			$this->memo[ $key . ':' . $id ] = $visible;
			++$changed;
		}

		if ( $changed > 0 ) {
			$this->cache->flush();
		}

		return $changed;
	}

	/**
	 * Включает на языке все записи, чьи строки переведены полностью.
	 *
	 * Правила подсчёта — как у колонки «Языки» (OccurrenceRepository::fullyTranslatedObjectIds()
	 * на общих правилах PostStringScope); запись без известных строк не
	 * включается. Уже включённые не трогаются, выключенные вручную записи,
	 * которые переведены полностью, включаются снова: кнопка так и называется.
	 *
	 * @param string $locale Код языка.
	 * @return int Сколько записей включено этим вызовом (уже включённые не считаются).
	 */
	public function enableFullyTranslated( string $locale ): int {
		if ( ! Locale::isValid( $locale ) ) {
			return 0;
		}

		/*
		 * Включаются только опубликованные записи подходящих типов — это
		 * проверяется в самой БД, а не по id из таблицы вхождений: так даже
		 * устаревший или чужой id (рубрика, пользователь) не включит постороннюю
		 * запись, а черновик и корзина не получат флаг «показывать».
		 */
		$ids = $this->publishedEligibleAmong( $this->occurrences->fullyTranslatedObjectIds( $locale ) );
		$ids = array_values( array_filter( $ids, array( $this, 'isEligiblePost' ) ) );

		return $this->setMany( $ids, $locale, true );
	}

	/**
	 * Какие из записей опубликованы и относятся к подходящим типам (один запрос на пачку).
	 *
	 * @param list<int> $ids Записи.
	 * @return list<int>
	 */
	private function publishedEligibleAmong( array $ids ): array {
		global $wpdb;

		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$types = self::eligibleTypes();

		if ( array() === $ids || array() === $types ) {
			return array();
		}

		$typeMarks = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$found     = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$idMarks = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM %i
					 WHERE ID IN ({$idMarks}) AND post_status = 'publish' AND post_type IN ({$typeMarks})",
					array_merge( array( $wpdb->posts ), $chunk, $types )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$found[] = (int) $row;
			}
		}

		return $found;
	}

	/**
	 * Можно ли вообще управлять видимостью записи: она есть, тип публичный, не вложение.
	 *
	 * Те же типы, что и в метабоксе «Перевод» и колонке «Языки».
	 *
	 * @param int $postId Запись.
	 */
	public function isEligiblePost( int $postId ): bool {
		$post = get_post( $postId );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return self::isEligibleType( (string) $post->post_type );
	}

	/**
	 * Типы записей, которыми можно управлять: публичные, кроме вложений, пунктов меню и ревизий.
	 *
	 * @return list<string>
	 */
	public static function eligibleTypes(): array {
		$types = get_post_types( array( 'public' => true ) );

		return array_values(
			array_filter(
				array_map( 'strval', is_array( $types ) ? array_values( $types ) : array() ),
				static fn( string $type ): bool => self::isEligibleType( $type )
			)
		);
	}

	/**
	 * Подходит ли тип записи: публичный, не вложение, не пункт меню и не ревизия.
	 *
	 * @param string $postType Тип записи.
	 */
	public static function isEligibleType( string $postType ): bool {
		if ( '' === $postType || in_array( $postType, array( 'attachment', 'nav_menu_item', 'revision' ), true ) ) {
			return false;
		}

		$type = get_post_type_object( $postType );

		return null !== $type && ! empty( $type->public );
	}
}
