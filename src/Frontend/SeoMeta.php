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
	 * Локаль в формате Open Graph: `ru_RU`, а не `ru-RU`.
	 *
	 * @param Language $language Язык.
	 */
	private function ogLocale( Language $language ): string {
		return str_replace( '-', '_', $language->bcp47() );
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

		foreach ( $document->document()->getElementsByTagName( 'script' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( (string) $script->getAttribute( 'type' ) ) ) ) {
				continue;
			}

			$json = JsonLdDocument::fromNode( $script );

			if ( null === $json ) {
				continue;
			}

			if ( ! $target->isDefault ) {
				$this->localizeUrls( $json, $target, $origin, $basePath );
			}

			foreach ( array_keys( $json->inLanguageFields() ) as $path ) {
				$json->setByEncodedPath( $path, $target->bcp47() );
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
	 */
	private function localizeUrls( JsonLdDocument $json, Language $target, string $origin, string $basePath ): void {
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

			$localized = UrlConverter::withLanguagePrefix( $url, $basePath, $target->slug );

			if ( $localized !== $url ) {
				$json->setByEncodedPath( $path, $localized );
			}
		}
	}
}
