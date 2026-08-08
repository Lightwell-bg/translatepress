<?php
/**
 * Мультиязычная карта сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Support\Hookable;

/**
 * Отдаёт карту сайта со всеми языковыми версиями (ТЗ 8.5).
 *
 * Собственный файл, а не встройка в чужой sitemap. Причина практическая:
 * рендерер карты сайта в ядре WordPress выводит только `loc` и `lastmod`
 * и молча игнорирует посторонние элементы, то есть `xhtml:link` с языковыми
 * версиями туда не добавить. А подстраиваться под внутренние фильтры
 * Rank Math или Yoast, не имея их исходников под рукой, значит писать код
 * наугад — он сломается от их обновления.
 *
 * Отдельный файл работает при любом SEO-плагине и всегда одинаково.
 * Поисковик находит его по строке `Sitemap:` в robots.txt.
 */
final class Sitemap implements Hookable {

	/**
	 * Имя файла карты сайта.
	 */
	public const FILE = 'wp-mlp-sitemap.xml';

	/**
	 * Группа объектного кэша.
	 */
	private const CACHE_GROUP = 'mlp_sitemap';

	/**
	 * @param Settings             $settings    Настройки плагина.
	 * @param UrlConverter         $urls        Построение языковых адресов.
	 * @param OccurrenceRepository $occurrences Места использования строк.
	 * @param TranslationCache     $cache       Кэш переводов (нужен номер версии).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly UrlConverter $urls,
		private readonly OccurrenceRepository $occurrences,
		private readonly TranslationCache $cache
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		/*
		 * Перехват на parse_request, а не через add_rewrite_rule: правило
		 * потребовало бы сброса правил после обновления, а плагин здесь
		 * обновляют копированием файлов — хук активации при этом не
		 * выполняется, и карта отдавала бы 404 до ручного пересохранения
		 * постоянных ссылок.
		 */
		add_action( 'parse_request', array( $this, 'maybeRender' ) );
		add_filter( 'robots_txt', array( $this, 'addToRobots' ), 10, 1 );
	}

	/**
	 * Полный адрес карты сайта.
	 */
	public function url(): string {
		return $this->urls->absolute( '/' . self::FILE, $this->settings->defaultLanguage() );
	}

	/**
	 * Добавляет карту в robots.txt, чтобы поисковик нашёл её сам.
	 *
	 * @param string $output Содержимое robots.txt.
	 */
	public function addToRobots( $output ): string {
		return rtrim( (string) $output ) . "\nSitemap: " . $this->url() . "\n";
	}

	/**
	 * Отдаёт карту сайта, если запрошен именно её адрес.
	 */
	public function maybeRender(): void {
		if ( untrailingslashit( LanguageResolver::currentPath() ) !== '/' . self::FILE ) {
			return;
		}

		$xml = $this->xml();

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML собран и экранирован в SitemapXml.
		echo $xml;
		exit;
	}

	/**
	 * XML карты сайта, с кэшированием на час.
	 */
	private function xml(): string {
		$key    = $this->cache->version() . ':xml';
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$xml = SitemapXml::build( $this->entries() );

		wp_cache_set( $key, $xml, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $xml;
	}

	/**
	 * Собирает адреса всех языковых версий.
	 *
	 * @return list<array{loc: string, lastmod?: string, alternates?: list<array{hreflang: string, href: string}>}>
	 */
	private function entries(): array {
		$default   = $this->settings->defaultLanguage();
		$secondary = $this->settings->published();

		unset( $secondary[ $default->locale ] );

		// Какие записи переведены на каждый из дополнительных языков.
		$translated = array();

		foreach ( $secondary as $language ) {
			$translated[ $language->locale ] = array_fill_keys(
				$this->occurrences->translatedObjectIds( $language->locale ),
				true
			);
		}

		$entries = $this->frontPageEntries( $default, $secondary, $translated );

		foreach ( $this->publicPostIds() as $postId ) {
			$path      = $this->relativePath( (int) $postId );
			$languages = array( $default );

			foreach ( $secondary as $language ) {
				/*
				 * Непереведённая страница на /en/ отдаёт русский текст. Пускать
				 * такой адрес в карту сайта нельзя: для поисковика это дубль,
				 * а не английская версия.
				 */
				if ( isset( $translated[ $language->locale ][ (int) $postId ] ) ) {
					$languages[] = $language;
				}
			}

			if ( '' === $path ) {
				continue;
			}

			$lastmod = (string) get_post_modified_time( 'c', true, (int) $postId );

			foreach ( $this->buildGroup( $path, $languages, $lastmod ) as $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Адреса главной страницы.
	 *
	 * @param Language                $default    Язык по умолчанию.
	 * @param array<string, Language> $secondary  Дополнительные языки.
	 * @param array<string, array<int, true>> $translated Переведённые записи по языкам.
	 * @return list<array{loc: string, alternates?: list<array{hreflang: string, href: string}>}>
	 */
	private function frontPageEntries( Language $default, array $secondary, array $translated ): array {
		$languages = array( $default );
		$frontId   = (int) get_option( 'page_on_front' );

		foreach ( $secondary as $language ) {
			// Для статической главной смотрим её перевод, для ленты записей —
			// считаем главную переведённой, если на языке вообще что-то есть.
			$isTranslated = $frontId > 0
				? isset( $translated[ $language->locale ][ $frontId ] )
				: array() !== ( $translated[ $language->locale ] ?? array() );

			if ( $isTranslated ) {
				$languages[] = $language;
			}
		}

		return $this->buildGroup( '/', $languages, '' );
	}

	/**
	 * Строит взаимный набор адресов одной страницы.
	 *
	 * @param string         $path      Путь без языкового префикса.
	 * @param list<Language> $languages Языки, на которых страница существует.
	 * @param string         $lastmod   Дата изменения в формате ISO 8601.
	 * @return list<array{loc: string, lastmod?: string, alternates?: list<array{hreflang: string, href: string}>}>
	 */
	private function buildGroup( string $path, array $languages, string $lastmod ): array {
		$alternates = array();

		foreach ( $languages as $language ) {
			$alternates[] = array(
				'hreflang' => $language->bcp47(),
				'href'     => $this->urls->absolute( $path, $language ),
			);
		}

		if ( count( $languages ) > 1 ) {
			// x-default указывает на исходную версию — она есть всегда.
			$alternates[] = array(
				'hreflang' => 'x-default',
				'href'     => $this->urls->absolute( $path, $languages[0] ),
			);
		}

		$entries = array();

		foreach ( $languages as $language ) {
			$entry = array( 'loc' => $this->urls->absolute( $path, $language ) );

			if ( '' !== $lastmod ) {
				$entry['lastmod'] = $lastmod;
			}

			$entry['alternates'] = $alternates;

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Идентификаторы записей, которые вообще должны попадать в карту сайта.
	 *
	 * @return list<int>
	 */
	private function publicPostIds(): array {
		$types = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			)
		);

		// Front page и обычные страницы публично запрашиваемыми не считаются,
		// хотя в карте сайта нужны.
		$types['page'] = 'page';
		unset( $types['attachment'] );

		$ids = get_posts(
			array(
				'post_type'        => array_values( $types ),
				'post_status'      => 'publish',
				'numberposts'      => SitemapXml::MAX_URLS,
				'fields'           => 'ids',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Путь записи без языкового префикса и базового пути установки.
	 *
	 * @param int $postId Идентификатор записи.
	 */
	private function relativePath( int $postId ): string {
		$permalink = get_permalink( $postId );

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		$path = (string) ( wp_parse_url( $permalink, PHP_URL_PATH ) ?? '' );

		return $this->urls->stripPrefix( LanguageResolver::relativePath( $path, LanguageResolver::basePath() ) );
	}
}
