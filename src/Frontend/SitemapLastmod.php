<?php
/**
 * Выбор более поздней из двух дат правки для карты сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

/**
 * Дата правки записи (`wp_posts.post_modified`) относится только к
 * исходному языку — перевод живёт в отдельной таблице и её не меняет. Без
 * этого сравнения `lastmod` английской версии страницы навсегда оставался
 * бы датой последней правки РУССКОГО текста, даже если сам перевод
 * появился только сегодня, — то есть не «реальной датой изменения
 * контента», а датой изменения только его источника.
 *
 * Не знает о WordPress: обе даты уже готовыми строками передаёт вызывающий
 * код ({@see Sitemap}), чтобы сравнение проверялось юнит-тестом без
 * реального сайта.
 */
final class SitemapLastmod {

	/**
	 * Более поздняя из двух дат, в ISO 8601 (том же виде, что и у
	 * остальных `lastmod` в карте сайта). Чистая функция.
	 *
	 * @param string $postModifiedIso8601   Дата правки записи — `get_post_modified_time('c', true, ...)`.
	 * @param string $translationUpdatedUtc Дата правки перевода — MySQL DATETIME в UTC (`translations.updated_at`), `''`, если перевода нет.
	 */
	public static function newerOf( string $postModifiedIso8601, string $translationUpdatedUtc ): string {
		if ( '' === $translationUpdatedUtc ) {
			return $postModifiedIso8601;
		}

		$postTimestamp        = strtotime( $postModifiedIso8601 );
		$translationTimestamp = strtotime( $translationUpdatedUtc . ' UTC' );

		if ( false === $postTimestamp || false === $translationTimestamp ) {
			return $postModifiedIso8601;
		}

		return $translationTimestamp > $postTimestamp
			? gmdate( 'c', $translationTimestamp )
			: $postModifiedIso8601;
	}
}
