<?php
/**
 * Правка SEO-метатегов под текущий язык.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Rendering\DocumentFilter;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\JsonLdDocument;
use WpMlp\Rendering\JsonLdRules;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Support\Locale;

/**
 * Приводит к текущему языку то, что нельзя перевести — только заменить.
 *
 * Тексты (`og:title`, `og:description`, заголовки в JSON-LD) переводятся
 * обычным путём, через словарь. А вот адреса и код локали переводить
 * бессмысленно: их нужно подменить на значения текущей языковой версии,
 * иначе расшаренная английская страница ведёт на русский адрес и объявляет
 * себя русской.
 *
 * Работает по готовому HTML, а не через фильтры Yoast или Rank Math. Это и
 * есть «адаптер» к ним: что бы они ни вывели, правится итоговая разметка —
 * поэтому не нужно подстраиваться под их внутренние API и нечему ломаться
 * от их обновлений. Тем же способом уже правится canonical (см. SeoTags).
 */
final class SeoMeta implements DocumentFilter {

	/**
	 * Метатеги с адресом текущей страницы.
	 */
	private const URL_META = array( 'og:url', 'twitter:url' );

	/**
	 * @param Settings         $settings Настройки плагина.
	 * @param LanguageResolver $resolver Язык текущего запроса.
	 * @param UrlConverter     $urls     Построение языковых адресов.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguageResolver $resolver,
		private readonly UrlConverter $urls
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( HtmlDocument $document, Language $target ): void {
		$this->fixMetaTags( $document, $target );
		$this->fixJsonLd( $document, $target );
	}

	/**
	 * Правит `<meta>`: адрес страницы и код локали.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param Language     $target   Текущий язык.
	 */
	private function fixMetaTags( HtmlDocument $document, Language $target ): void {
		$canonical = $this->urls->canonicalUrlFor( $target );
		$seen      = array();

		foreach ( $document->document()->getElementsByTagName( 'meta' ) as $meta ) {
			$key = strtolower( trim( (string) $meta->getAttribute( 'property' ) ) );

			if ( '' === $key ) {
				$key = strtolower( trim( (string) $meta->getAttribute( 'name' ) ) );
			}

			if ( in_array( $key, self::URL_META, true ) ) {
				$meta->setAttribute( 'content', $canonical );

				continue;
			}

			if ( 'og:locale' === $key ) {
				$meta->setAttribute( 'content', $this->ogLocale( $target ) );
				$seen['og:locale'] = true;
			}
		}

		if ( isset( $seen['og:locale'] ) ) {
			$this->refreshAlternateLocales( $document, $target );
		}
	}

	/**
	 * Пересобирает набор `og:locale:alternate`.
	 *
	 * SEO-плагин выводит его для одного языка — своего. Старые значения
	 * убираются целиком, чтобы не остался список от исходной локали.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param Language     $target   Текущий язык.
	 */
	private function refreshAlternateLocales( HtmlDocument $document, Language $target ): void {
		$existing = array();

		foreach ( $document->document()->getElementsByTagName( 'meta' ) as $meta ) {
			$key = strtolower( trim( (string) $meta->getAttribute( 'property' ) ) );

			if ( 'og:locale:alternate' === $key ) {
				$existing[] = $meta;
			}
		}

		$parent = null;

		foreach ( $existing as $meta ) {
			$parent = $meta->parentNode ?? $parent;

			if ( null !== $meta->parentNode ) {
				$meta->parentNode->removeChild( $meta );
			}
		}

		if ( null === $parent ) {
			$parent = $document->document()->getElementsByTagName( 'head' )->item( 0 );
		}

		if ( null === $parent ) {
			return;
		}

		foreach ( $this->settings->published() as $language ) {
			if ( $language->locale === $target->locale ) {
				continue;
			}

			$meta = $document->document()->createElement( 'meta' );
			$meta->setAttribute( 'property', 'og:locale:alternate' );
			$meta->setAttribute( 'content', $this->ogLocale( $language ) );

			$parent->appendChild( $meta );
		}
	}

	/**
	 * Локаль в формате Open Graph: `ru_RU`, а не `ru-RU` и не голое `ru`.
	 *
	 * Facebook требует полный код `xx_XX` из своего документированного
	 * списка (https://developers.facebook.com/docs/messenger-platform/... —
	 * og:locale); просто заменить дефис на подчёркивание в `bcp47()`
	 * недостаточно, когда код языка в настройках задан без региона
	 * («en», а не «en-US») — тогда получится невалидное голое «en».
	 *
	 * @param Language $language Язык.
	 */
	private function ogLocale( Language $language ): string {
		return Locale::toOgLocale( $language->locale );
	}

	/**
	 * Приводит структурированные данные к текущему языку: адреса страниц
	 * получают префикс, `inLanguage` — код текущего языка.
	 *
	 * Оба прохода — по одному и тому же разобранному графу за один проход
	 * по тегам `<script>`, а не по два: страница может нести несколько
	 * блоков JSON-LD, и разбирать каждый дважды незачем.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param Language     $target   Текущий язык.
	 */
	private function fixJsonLd( HtmlDocument $document, Language $target ): void {
		$origin   = untrailingslashit( (string) get_option( 'home' ) );
		$basePath = LanguageResolver::basePath();
		$slugs    = $this->urls->knownSlugs();

		foreach ( $document->document()->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( (string) $script->getAttribute( 'type' ) ) ) ) {
				continue;
			}

			$json = JsonLdDocument::fromNode( $script );

			if ( null === $json ) {
				continue;
			}

			if ( ! $target->isDefault ) {
				$this->localizeUrls( $json, $target, $origin, $basePath, $slugs );
			}

			$this->normalizeGraphIds( $json, $target, $origin, $basePath, $slugs );

			foreach ( array_keys( $json->inLanguageFields() ) as $path ) {
				$json->setByEncodedPath( $path, $target->bcp47() );
			}
		}
	}

	/**
	 * Приводит все `@id` графа к правильному виду — сначала строит карту
	 * ОПРЕДЕЛЁННЫХ сущностей, затем заменяет по ней КАЖДОЕ упоминание `@id`
	 * в графе, а не только то, что стоит рядом с `@type`.
	 *
	 * Двухпроходная схема — не излишество, а единственный способ починить
	 * то, что ломало прошлую версию: `@id` встречается в графе не только там,
	 * где сущность ОПРЕДЕЛЕНА (`{"@type":"Organization","@id":"..."}`), но и
	 * там, где на неё просто ССЫЛАЮТСЯ — `"publisher":{"@id":"..."}"`,
	 * `"author":{"@id":"..."}"`, `"mainEntityOfPage":{"@id":"..."}"`. У ссылки
	 * своего `@type` обычно нет вовсе, и решать по родителю в дереве (`Article`
	 * у `publisher`) — саму сущность это не делает статьёй. Поэтому:
	 *
	 * 1. `definedEntityTypes()` находит только узлы, где сущность
	 *    ОПРЕДЕЛЕНА (несёт и `@id`, и `@type` на одном уровне), и решает по
	 *    ЕЁ СОБСТВЕННОМУ типу (не по родителю), новое значение `@id`:
	 *    Organization/Person/WebSite — без префикса, WebPage/Article/
	 *    BlogPosting/BreadcrumbList — с префиксом текущего языка.
	 * 2. Готовая карта «старое значение → новое» применяется через
	 *    `allIdFields()` — это ВСЕ вхождения `@id` в графе, включая ссылки.
	 *    Совпало значение — заменили, вне зависимости от того, где именно
	 *    в графе это вхождение находится.
	 *
	 * @param JsonLdDocument $json     Разобранный блок структурированных данных.
	 * @param Language       $target   Текущий язык.
	 * @param string         $origin   Адрес сайта без хвостового слеша.
	 * @param string         $basePath Базовый путь установки WordPress.
	 * @param list<string>   $slugs    Слаги всех языков сайта.
	 */
	private function normalizeGraphIds( JsonLdDocument $json, Language $target, string $origin, string $basePath, array $slugs ): void {
		$idMap = array();

		foreach ( $json->definedEntityTypes() as $id => $types ) {
			if ( ! str_starts_with( $id, $origin ) ) {
				// Чужой домен (импортированная сущность) — не наш префикс, не трогаем.
				continue;
			}

			if ( JsonLdRules::isStableEntityType( $types ) ) {
				$normalized = UrlConverter::withoutLanguagePrefix( $id, $basePath, $slugs );

				if ( $normalized !== $id ) {
					$idMap[ $id ] = $normalized;
				}

				continue;
			}

			if ( ! $target->isDefault && JsonLdRules::isPageScopedEntityType( $types ) ) {
				$localized = UrlConverter::withLanguagePrefix( $id, $basePath, $target->slug, $slugs );

				if ( $localized !== $id ) {
					$idMap[ $id ] = $localized;
				}
			}
		}

		if ( array() === $idMap ) {
			return;
		}

		foreach ( $json->allIdFields() as $path => $value ) {
			if ( isset( $idMap[ $value ] ) ) {
				$json->setByEncodedPath( $path, $idMap[ $value ] );
			}
		}
	}

	/**
	 * Добавляет языковой префикс к адресам страниц внутри графа.
	 *
	 * @param JsonLdDocument $json     Разобранный блок структурированных данных.
	 * @param Language       $target   Текущий язык.
	 * @param string         $origin   Адрес сайта без хвостового слеша.
	 * @param string         $basePath Базовый путь установки WordPress.
	 * @param list<string>   $slugs    Слаги всех языков сайта.
	 */
	private function localizeUrls( JsonLdDocument $json, Language $target, string $origin, string $basePath, array $slugs ): void {
		foreach ( $json->urls() as $path => $url ) {
			// Чужие домены не трогаем: языковой префикс есть только у нас.
			if ( ! str_starts_with( $url, $origin ) ) {
				continue;
			}

			/*
			 * Вторая, независимая от @type защита картинок: JsonLdRules::isUrl()
			 * уже исключает поля url у ImageObject/VideoObject/AudioObject, но
			 * тип в чужом графе может быть указан неточно или отсутствовать —
			 * расширение файла надёжнее.
			 */
			if ( JsonLdRules::looksLikeMediaFile( $url ) ) {
				continue;
			}

			$localized = UrlConverter::withLanguagePrefix( $url, $basePath, $target->slug, $slugs );

			if ( $localized !== $url ) {
				$json->setByEncodedPath( $path, $localized );
			}
		}
	}
}
