<?php
/**
 * Общие SQL-правила «что считать строками записи».
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Storage;

use LingoCanvas\Rendering\Segment;

defined( 'ABSPATH' ) || exit;

/**
 * Единственное место, где записано, какие строки принадлежат записи.
 *
 * Правила взяты у экрана «Перевод строк» (SourceRepository::paginate()) для
 * области `object:ID`: строка принадлежит записи, если найдена на ней и больше
 * ни на одной записи; адреса ссылок (атрибут `href`) в счёт не входят.
 * Колонка «Языки» и фильтр «Перевод» в списке записей и массовое включение
 * «переведено полностью» (OccurrenceRepository::fullyTranslatedObjectIds())
 * строятся из этих фрагментов, а не из копий: иначе цифры в списке и решение
 * «запись готова к показу» разошлись бы, а владелец сайта перестал бы верить
 * обоим.
 *
 * Учитываются только вхождения с `object_type = 'post'`: у остальных
 * (`url` — архивы, поиск, главная) `object_id` пуст либо, у данных прошлых
 * версий, мог оказаться id рубрики или автора, совпавшим с id записи.
 *
 * Все значения здесь — константы кода, не ввод пользователя, поэтому
 * вставляются в SQL литералами; псевдонимы таблиц задаёт вызывающий.
 */
final class PostStringScope {

	/**
	 * Условие «строка — не адрес ссылки». Чистая функция.
	 *
	 * Ссылка опознаётся так же, как на вкладке «Ссылки» экрана строк: у
	 * строки `kind = attribute` и в местах использования атрибут `href`.
	 * Перевод адресов необязателен (большинство ссылок остаются прежними), и
	 * если бы они входили в счёт, запись почти никогда не стала бы «готовой».
	 *
	 * @param string $sourceIdExpr SQL-выражение с id строки (`a.source_id`).
	 */
	public static function notLinkSql( string $sourceIdExpr ): string {
		$sources     = Schema::table( 'sources' );
		$occurrences = Schema::table( 'occurrences' );

		return "NOT EXISTS (SELECT 1 FROM {$sources} ls WHERE ls.id = {$sourceIdExpr} AND ls.kind = '" . Segment::KIND_ATTRIBUTE . "'"
			. " AND EXISTS (SELECT 1 FROM {$occurrences} lo WHERE lo.source_id = ls.id AND lo.attribute_name = '" . SourceRepository::LINK_ATTRIBUTE . "'))";
	}

	/**
	 * SQL-выражение 1/0: строка относится к SEO/GEO. Чистая функция.
	 *
	 * Правило вкладки SEO/GEO (SourceRepository::TYPE_SEO): `kind = seo` либо
	 * атрибут `content` (meta-теги).
	 *
	 * @param string $sourceIdExpr SQL-выражение с id строки.
	 */
	public static function isSeoSql( string $sourceIdExpr ): string {
		$sources     = Schema::table( 'sources' );
		$occurrences = Schema::table( 'occurrences' );

		return "(CASE WHEN EXISTS (SELECT 1 FROM {$sources} ss WHERE ss.id = {$sourceIdExpr} AND (ss.kind = '" . Segment::KIND_SEO . "'"
			. " OR (ss.kind = '" . Segment::KIND_ATTRIBUTE . "' AND EXISTS (SELECT 1 FROM {$occurrences} sc WHERE sc.source_id = ss.id AND sc.attribute_name = 'content')))) THEN 1 ELSE 0 END)";
	}

	/**
	 * Условие «строка принадлежит только этой записи и не является ссылкой».
	 *
	 * «Нет вхождения на другой записи» равно `COUNT(DISTINCT object_id) = 1`
	 * экрана строк: вхождения без записи (object_id NULL — архивы, поиск)
	 * в сравнении `<>` дают NULL и, как и там, не считаются. Работает по
	 * индексу (source_id, object_id) без подсчёта на каждую строку.
	 *
	 * Строка обязана принадлежать ровно одной записи: общие строки (названия
	 * рубрик в карточках, подвал) есть на каждой записи, и если бы они
	 * входили в счёт, то «переведённой» оказалась бы любая запись сайта.
	 *
	 * @param string $alias Псевдоним таблицы вхождений во внешнем запросе (`a`); внутренний — `{alias}2`.
	 */
	public static function uniqueNotLinkSql( string $alias ): string {
		$occurrences = Schema::table( 'occurrences' );

		return "NOT EXISTS (SELECT 1 FROM {$occurrences} {$alias}2 WHERE {$alias}2.source_id = {$alias}.source_id AND {$alias}2.object_type = '" . OccurrenceRepository::OBJECT_TYPE_POST . "' AND {$alias}2.object_id <> {$alias}.object_id)"
			. ' AND ' . self::notLinkSql( "{$alias}.source_id" );
	}

	/**
	 * Условие «вхождение — строка именно этой записи» (коррелированное).
	 *
	 * @param string $alias      Псевдоним таблицы вхождений (`a`).
	 * @param string $postIdExpr SQL-выражение с id записи (`wp_posts.ID`).
	 */
	public static function ownCondition( string $alias, string $postIdExpr ): string {
		return "{$alias}.object_type = '" . OccurrenceRepository::OBJECT_TYPE_POST . "' AND {$alias}.object_id = {$postIdExpr} AND " . self::uniqueNotLinkSql( $alias );
	}

	/**
	 * Подзапрос «строки записей»: пары (object_id, source_id) без повторов.
	 *
	 * Псевдоним таблицы вхождений — `o`. Фильтр по записям вызывающий задаёт
	 * сам (`o.object_id IN (%d, %d)` или «все записи»).
	 *
	 * @param string $objectFilterSql SQL-условие на `o.object_id`.
	 * @param bool   $withSeo         Добавить колонку `is_seo` (1/0).
	 */
	public static function ownRowsSql( string $objectFilterSql, bool $withSeo = false ): string {
		$occurrences = Schema::table( 'occurrences' );
		$seo         = $withSeo ? ', ' . self::isSeoSql( 'o.source_id' ) . ' AS is_seo' : '';

		return "SELECT DISTINCT o.object_id, o.source_id{$seo} FROM {$occurrences} o
					WHERE o.object_type = '" . OccurrenceRepository::OBJECT_TYPE_POST . "' AND {$objectFilterSql}
						AND " . self::uniqueNotLinkSql( 'o' );
	}
}
