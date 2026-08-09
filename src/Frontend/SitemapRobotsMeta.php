<?php
/**
 * Решение «noindex ли запись» по meta трёх SEO-плагинов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

/**
 * Читает не хуки и не вывод SEO-плагина, а его собственное postmeta —
 * единственный способ узнать «эта страница технически исключена из
 * индекса» без рендера каждой кандидатной страницы целиком (а страниц в
 * карте сайта могут быть тысячи, рендерить их все ради одной проверки
 * непрактично).
 *
 * Значение чисто рекомендательное для карты сайта: если формат meta
 * когда-нибудь изменится или плагин обновится не так, как здесь описано,
 * штатное поведение — страница остаётся в карте — безопаснее, чем
 * гадать. Ничего не переводится и не подменяется, только читается.
 *
 * Вынесено отдельно от {@see Sitemap} и не знает о WordPress: сами
 * значения postmeta читает Sitemap::isTechnicalOrNoindex(), сюда приходят
 * уже готовыми — так решение проверяется юнит-тестом без реального сайта.
 */
final class SitemapRobotsMeta {

	/**
	 * Помечена ли запись noindex хотя бы одним из трёх плагинов. Чистая функция.
	 *
	 * @param mixed $yoast    Значение `_yoast_wpseo_meta-robots-noindex`: `'1'` — noindex явно включён, `'2'` — index явно включён, иначе — умолчание сайта.
	 * @param mixed $rankMath Значение `rank_math_robots`: список отмеченных чекбоксов, один из них может быть `'noindex'`.
	 * @param mixed $seoPress Значение `_seopress_robots_noindex`: `'yes'` — noindex включён.
	 */
	public static function isNoindex( mixed $yoast, mixed $rankMath, mixed $seoPress ): bool {
		if ( '1' === (string) $yoast ) {
			return true;
		}

		if ( is_array( $rankMath ) && in_array( 'noindex', $rankMath, true ) ) {
			return true;
		}

		return 'yes' === (string) $seoPress;
	}
}
