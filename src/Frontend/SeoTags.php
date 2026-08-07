<?php
/**
 * Базовые SEO-теги языковых страниц.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Rendering\DocumentFilter;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * hreflang, canonical и атрибут `lang` (ТЗ 8.1–8.3).
 *
 * hreflang печатается через `wp_head` — так он появляется и на языке по
 * умолчанию, где буфера перевода нет.
 *
 * Canonical правится прямо в готовом DOM. Так решается совместимость с Yoast,
 * Rank Math и SEOPress без отдельных адаптеров: что бы ни вывел SEO-плагин,
 * в ответе останется ровно один canonical, указывающий на текущий языковой
 * адрес (ТЗ 8.2, критерий приёмки 13).
 */
final class SeoTags implements Hookable, DocumentFilter {

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
	public function register(): void {
		add_action( 'wp_head', array( $this, 'printHreflang' ), 1 );
		add_filter( 'language_attributes', array( $this, 'filterLanguageAttributes' ) );
	}

	/**
	 * Печатает взаимный набор hreflang и x-default.
	 */
	public function printHreflang(): void {
		if ( ! $this->isIndexable() ) {
			return;
		}

		$languages = $this->settings->published();

		if ( count( $languages ) < 2 ) {
			return;
		}

		foreach ( $languages as $language ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $language->bcp47() ),
				esc_url( $this->urls->canonicalUrlFor( $language ) )
			);
		}

		printf(
			'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
			esc_url( $this->urls->canonicalUrlFor( $this->settings->defaultLanguage() ) )
		);
	}

	/**
	 * Подставляет язык текущего запроса в атрибуты тега `html`.
	 *
	 * @param string $output Значение вида `lang="ru-RU"`.
	 */
	public function filterLanguageAttributes( $output ): string {
		$output = (string) $output;
		$lang   = $this->resolver->current()->bcp47();

		if ( 1 === preg_match( '/lang="[^"]*"/', $output ) ) {
			return (string) preg_replace( '/lang="[^"]*"/', 'lang="' . esc_attr( $lang ) . '"', $output );
		}

		return trim( $output . ' lang="' . esc_attr( $lang ) . '"' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( HtmlDocument $document, Language $target ): void {
		$root = $document->root();

		if ( null !== $root && 'html' === strtolower( (string) $root->nodeName ) ) {
			// Страховка на случай темы, которая не вызывает language_attributes().
			$root->setAttribute( 'lang', $target->bcp47() );
		}

		$this->fixCanonical( $document, $target );
	}

	/**
	 * Приводит canonical к текущему языковому адресу.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param Language     $target   Текущий язык.
	 */
	private function fixCanonical( HtmlDocument $document, Language $target ): void {
		$url      = $this->urls->canonicalUrlFor( $target );
		$existing = $this->findCanonical( $document );

		if ( array() !== $existing ) {
			$first = array_shift( $existing );
			$first->setAttribute( 'href', $url );

			// Дубли canonical от нескольких SEO-модулей убираем.
			foreach ( $existing as $extra ) {
				$parent = $extra->parentNode;

				if ( null !== $parent ) {
					$parent->removeChild( $extra );
				}
			}

			return;
		}

		if ( ! $this->isIndexable() ) {
			return;
		}

		$head = $document->document()->getElementsByTagName( 'head' )->item( 0 );

		if ( null === $head ) {
			return;
		}

		$link = $document->document()->createElement( 'link' );
		$link->setAttribute( 'rel', 'canonical' );
		$link->setAttribute( 'href', $url );

		$head->appendChild( $link );
	}

	/**
	 * Находит все теги canonical в документе.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @return list<object>
	 */
	private function findCanonical( HtmlDocument $document ): array {
		$found = array();

		foreach ( $document->document()->getElementsByTagName( 'link' ) as $link ) {
			if ( 'canonical' === strtolower( trim( (string) $link->getAttribute( 'rel' ) ) ) ) {
				$found[] = $link;
			}
		}

		return $found;
	}

	/**
	 * Стоит ли выводить canonical и hreflang на этой странице.
	 *
	 * У страницы ошибки и результатов поиска канонического адреса нет.
	 */
	private function isIndexable(): bool {
		// До хука `wp` главного запроса ещё нет, и условные теги вызывать нельзя.
		if ( ! did_action( 'wp' ) ) {
			return false;
		}

		return ! is_404() && ! is_search();
	}
}
