<?php
/**
 * Сброс кэша страниц сторонних плагинов после изменения перевода.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Дёргает известные плагины полного кэширования страниц (ТЗ 12.1: «сбрасывать
 * страницу/объектный кэш после ручного изменения перевода»).
 *
 * `TranslationCache::flush()` обесценивает только наш собственный кэш
 * «строка → перевод» — он ни для чего не значит, что кэш ГОТОВОГО HTML
 * страницы у стороннего плагина (WP Rocket, WP Fastest Cache и т.п.) тоже
 * стал недействительным. Без этого шага посетитель может ещё долго видеть
 * старую, непереведённую версию страницы: раздающий кэш не спрашивает у
 * WordPress, поменялось ли что-то в базе, он просто отдаёт то, что сохранил.
 *
 * Интеграция through function_exists()/class_exists() — «лучшее из
 * возможного»: точные имена функций взяты из документации соответствующих
 * плагинов на момент написания, а не проверены вживую на этом сайте.
 * Плагины, которых нет, просто пропускаются без ошибки.
 */
final class PageCachePurger {

	/**
	 * Сбрасывает все известные кэши целиком.
	 *
	 * Полный сброс, а не точечный по конкретному URL: перевод одной строки
	 * (например, пункта меню) может встречаться на десятках страниц, и
	 * искать их все дороже и ненадёжнее, чем один раз сбросить всё.
	 */
	public static function purgeAll(): void {
		self::purgeWpSuperCache();
		self::purgeWpFastestCache();
		self::purgeWpRocket();
		self::purgeW3TotalCache();
		self::purgeLiteSpeedCache();
		self::purgeSgOptimizer();
		self::purgeWpEngine();

		/**
		 * Точка расширения для кэшей, которых нет в списке выше (Cloudflare,
		 * Varnish, кастомные reverse-proxy).
		 */
		do_action( 'mlp_purge_page_cache' );
	}

	/**
	 * WP Super Cache.
	 */
	private static function purgeWpSuperCache(): void {
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}

	/**
	 * WP Fastest Cache.
	 */
	private static function purgeWpFastestCache(): void {
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}
	}

	/**
	 * WP Rocket.
	 */
	private static function purgeWpRocket(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * W3 Total Cache.
	 */
	private static function purgeW3TotalCache(): void {
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
	}

	/**
	 * LiteSpeed Cache.
	 */
	private static function purgeLiteSpeedCache(): void {
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	}

	/**
	 * SiteGround Optimizer.
	 */
	private static function purgeSgOptimizer(): void {
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
	}

	/**
	 * WP Engine.
	 */
	private static function purgeWpEngine(): void {
		if ( class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_memcached' ) ) {
			\WpeCommon::purge_memcached();
			\WpeCommon::purge_varnish_cache();
		}
	}
}
