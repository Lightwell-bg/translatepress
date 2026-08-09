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
	 * Строка добавляется ровно один раз: если она там уже есть (например,
	 * фильтр `robots_txt` в этом запросе почему-то отработал дважды —
	 * контейнер сам этого не допускает, но чужой код теоретически может
	 * вызвать метод напрямую), повторного добавления не будет. Чужие
	 * строки `Sitemap:` (например, от Yoast или Rank Math — у них
	 * собственная карта сайта) не трогаются: несколько разных карт в
	 * robots.txt — валидный и обычный случай, не дубль.
	 *
	 * @param string $output Содержимое robots.txt.
	 */
	public function addToRobots( $output ): string {
		$output = (string) $output;
		$line   = 'Sitemap: ' . $this->url();

		if ( str_contains( $output, $line ) ) {
			return $output;
		}

		return rtrim( $output ) . "\n" . $line . "\n";
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

		// Какие записи переведены на каждый из дополнительных языков и
		// когда их перевод правили в последний раз (см. lastmodFor()).
		$translated     = array();
		$lastTranslated = array();

		foreach ( $secondary as $language ) {
			$translated[ $language->locale ]     = array_fill_keys(
				$this->occurrences->translatedObjectIds( $language->locale ),
				true
			);
			$lastTranslated[ $language->locale ] = $this->occurrences->lastTranslatedAt( $language->locale );
		}

		$postIds = $this->publicPostIds();
		$entries = $this->frontPageEntries( $default, $secondary, $translated, $postIds );

		foreach ( $postIds as $postId ) {
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

			$lastmod = $this->lastmodFor( (int) $postId, $default, $secondary, $lastTranslated );

			foreach ( $this->buildGroup( $path, $languages, $lastmod ) as $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Дата правки записи по каждому языку.
	 *
	 * У исходного языка это всегда собственная дата правки записи. У
	 * остальных — та же дата, если перевод не обновлялся позже, либо дата
	 * последнего обновления перевода, если она свежее: перевод хранится не
	 * в самой записи, а в отдельной таблице, и правка записи её не
	 * затрагивает — без этого учёта `lastmod` английской версии страницы
	 * навсегда оставался бы датой последней правки русского текста.
	 *
	 * @param int                             $postId         Идентификатор записи.
	 * @param Language                        $default        Язык по умолчанию.
	 * @param array<string, Language>         $secondary      Дополнительные языки.
	 * @param array<string, array<int, string>> $lastTranslated Дата последнего перевода записи по языкам (см. entries()).
	 * @return array<string, string> Код языка => дата в ISO 8601.
	 */
	private function lastmodFor( int $postId, Language $default, array $secondary, array $lastTranslated ): array {
		$sourceLastmod = (string) get_post_modified_time( 'c', true, $postId );
		$byLanguage    = array( $default->locale => $sourceLastmod );

		foreach ( $secondary as $language ) {
			$translationUpdated = $lastTranslated[ $language->locale ][ $postId ] ?? '';

			$byLanguage[ $language->locale ] = SitemapLastmod::newerOf( $sourceLastmod, $translationUpdated );
		}

		return $byLanguage;
	}

	/**
	 * Адреса главной страницы.
	 *
	 * @param Language                        $default    Язык по умолчанию.
	 * @param array<string, Language>         $secondary  Дополнительные языки.
	 * @param array<string, array<int, true>> $translated Переведённые записи по языкам.
	 * @param list<int>                       $postIds    Публичные записи (см. publicPostIds()) — источник даты правки, когда у главной нет своей.
	 * @return list<array{loc: string, lastmod?: string, alternates?: list<array{hreflang: string, href: string}>}>
	 */
	private function frontPageEntries( Language $default, array $secondary, array $translated, array $postIds ): array {
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

		$lastmod    = $this->frontPageLastmod( $frontId, $postIds );
		$byLanguage = array();

		foreach ( $languages as $language ) {
			$byLanguage[ $language->locale ] = $lastmod;
		}

		return $this->buildGroup( '/', $languages, $byLanguage );
	}

	/**
	 * Дата изменения главной страницы.
	 *
	 * У статической главной (`page_on_front`) есть своя запись — та же
	 * `get_post_modified_time()`, что и у любой другой страницы карты сайта.
	 * У «последних записей» такой записи нет: в этой роли берётся дата самой
	 * свежей публично видимой записи — $postIds уже отсортирован по
	 * `modified DESC` (см. publicPostIds()), поэтому это просто первый
	 * элемент списка, без лишнего запроса.
	 *
	 * @param int       $frontId Идентификатор статической главной (0, если её нет).
	 * @param list<int> $postIds Публичные записи, отсортированные по дате правки, свежие первыми.
	 */
	private function frontPageLastmod( int $frontId, array $postIds ): string {
		if ( $frontId > 0 ) {
			return (string) get_post_modified_time( 'c', true, $frontId );
		}

		return array() !== $postIds ? (string) get_post_modified_time( 'c', true, $postIds[0] ) : '';
	}

	/**
	 * Строит взаимный набор адресов одной страницы.
	 *
	 * `alternates` (взаимный кластер `hreflang`) строится по тому же
	 * списку языков и остаётся общим для всех записей группы — меняется
	 * только `lastmod` каждой отдельной записи, у языков одной и той же
	 * страницы он может отличаться (см. lastmodFor()).
	 *
	 * @param string                $path      Путь без языкового префикса.
	 * @param list<Language>        $languages Языки, на которых страница существует.
	 * @param array<string, string> $lastmod   Дата изменения в ISO 8601 по коду языка; пустая строка или отсутствие ключа — без `lastmod`.
	 * @return list<array{loc: string, lastmod?: string, alternates?: list<array{hreflang: string, href: string}>}>
	 */
	private function buildGroup( string $path, array $languages, array $lastmod ): array {
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

			$own = $lastmod[ $language->locale ] ?? '';

			if ( '' !== $own ) {
				$entry['lastmod'] = $own;
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

		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		$ids = $this->withoutServicePages( $ids );

		/**
		 * Последний рубеж для того, что нельзя узнать программно, — своя
		 * служебная страница темы или отдельного плагина. Каждый элемент —
		 * id записи; хук ничего не решает сам, просто даёт исключить (или
		 * вернуть) конкретные id без правки кода плагина.
		 *
		 * @param list<int> $ids Идентификаторы записей, отобранные в карту сайта.
		 */
		return apply_filters( 'mlp_sitemap_post_ids', $ids );
	}

	/**
	 * Убирает страницы корзины, оформления заказа и личного кабинета
	 * WooCommerce (с их вложенными страницами — история заказов,
	 * подтверждение, счёт, неудавшаяся транзакция и т. п.), а также
	 * страницы, которые владелец сайта явно исключил в настройках.
	 *
	 * Это не контент для читателя: часть привязана к сессии посетителя
	 * (корзина, история заказов), часть — промежуточный шаг оформления,
	 * одинаковый у каждого покупателя, — индексировать их вредно, поисковик
	 * не должен предлагать чужую страницу оформления заказа в выдаче.
	 * Магазин (`wc_get_page_id('shop')`) сюда намеренно не входит: это
	 * настоящий каталог товаров, его как раз нужно индексировать.
	 *
	 * WooCommerce распознаётся только по его собственному API
	 * (`wc_get_page_id()`) — обычная страница, созданная вручную (без
	 * WooCommerce или другого известного плагина корзины), никаким
	 * программным способом не отличается от любой другой страницы. Для неё
	 * есть только явный список слагов в настройках (см.
	 * Settings::sitemapExcludedSlugs()).
	 *
	 * @param list<int> $ids Отобранные идентификаторы записей.
	 * @return list<int>
	 */
	private function withoutServicePages( array $ids ): array {
		$excluded = array_values(
			array_unique(
				array_merge(
					$this->woocommercePageIds(),
					$this->manuallyExcludedIds( $ids ),
					$this->technicalOrNoindexIds( $ids )
				)
			)
		);

		if ( array() === $excluded ) {
			return $ids;
		}

		$ancestors = array();

		foreach ( $ids as $id ) {
			$ancestors[ $id ] = array_map( 'intval', get_post_ancestors( $id ) );
		}

		return SitemapPageFilter::excluding( $ids, $excluded, $ancestors );
	}

	/**
	 * Идентификаторы служебных страниц WooCommerce — корзина, оформление
	 * заказа, личный кабинет. Пусто, если WooCommerce не активен.
	 *
	 * @return list<int>
	 */
	private function woocommercePageIds(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}

		$ids = array();

		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $role ) {
			$id = (int) wc_get_page_id( $role );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Идентификаторы записей, чей слаг владелец сайта вписал в настройки
	 * («Мультиязычность → Языки → Исключить из карты сайта») вручную.
	 *
	 * @param list<int> $ids Отобранные идентификаторы записей.
	 * @return list<int>
	 */
	private function manuallyExcludedIds( array $ids ): array {
		$slugs = $this->settings->sitemapExcludedSlugs();

		if ( array() === $slugs ) {
			return array();
		}

		$matched = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( null !== $post && in_array( $post->post_name, $slugs, true ) ) {
				$matched[] = $id;
			}
		}

		return $matched;
	}

	/**
	 * Идентификаторы записей, технических или помеченных noindex, — не по
	 * слагу или названию, а по признакам, которые нельзя перепутать
	 * с обычным контентом: защита паролем или явный noindex от Yoast SEO,
	 * Rank Math либо SEOPress (тех же трёх, с которыми плагин уже совместим
	 * в остальном — см. SeoTags, SeoMeta). Так исключаются страницы вроде
	 * «Нет доступа» или вручную собранного оформления заказа без единого
	 * плагина корзины — программно их иначе не отличить.
	 *
	 * @param list<int> $ids Отобранные идентификаторы записей.
	 * @return list<int>
	 */
	private function technicalOrNoindexIds( array $ids ): array {
		return array_values( array_filter( $ids, array( $this, 'isTechnicalOrNoindex' ) ) );
	}

	/**
	 * Техническая ли запись сама по себе — без учёта родителей.
	 *
	 * @param int $postId Идентификатор записи.
	 */
	private function isTechnicalOrNoindex( int $postId ): bool {
		$post = get_post( $postId );

		if ( null !== $post && '' !== (string) $post->post_password ) {
			return true;
		}

		return SitemapRobotsMeta::isNoindex(
			get_post_meta( $postId, '_yoast_wpseo_meta-robots-noindex', true ),
			get_post_meta( $postId, 'rank_math_robots', true ),
			get_post_meta( $postId, '_seopress_robots_noindex', true )
		);
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
