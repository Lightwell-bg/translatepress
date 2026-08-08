<?php
/**
 * Языковой префикс для ссылок, не построенных через home_url().
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

/**
 * Добавляет языковой префикс к внутренним ссылкам в готовом HTML (ТЗ 4.2:
 * маршрутизатор «переписывает внутренние ссылки в итоговом DOM»).
 *
 * `UrlConverter::filterHomeUrl()` покрывает почти все ссылки на сайте, но не
 * все: он перехватывает `home_url()`, а тема или виджет вполне может написать
 * `<a href="/kontakty/">` буквальным текстом в шаблоне, минуя эту функцию.
 * Такая ссылка ведёт на исходный язык, даже когда её вывели на `/en/...`, —
 * футер и другие жёстко закодированные в теме блоки страдают от этого чаще
 * всего. Этот фильтр — второй, независимый проход: правит то, что осталось
 * непрефиксованным после генерации страницы, каким бы способом ссылку ни
 * вывели.
 */
final class InternalLinks implements DocumentFilter {

	/**
	 * CSS-класс, которым переключатель языков (NavMenu, LanguageSwitcher)
	 * помечает свои собственные ссылки.
	 *
	 * Каждая такая ссылка уже ведёт на СВОЙ язык (ru → без префикса,
	 * bg → `/bg/...`, en → `/en/...`), выбранный не по языку текущей
	 * страницы, а по языку, который она предлагает. Если прогнать её через
	 * общий проход ниже, префикс текущего языка страницы затрёт чужой —
	 * переключатель перестанет вести куда должен.
	 */
	private const SWITCHER_CLASS = 'mlp-language-item';

	/**
	 * @param UrlConverter $urls Построение языковых адресов.
	 */
	public function __construct( private readonly UrlConverter $urls ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( HtmlDocument $document, Language $target ): void {
		if ( $target->isDefault ) {
			return;
		}

		$basePath = LanguageResolver::basePath();
		$homeHost = (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) ?? '' );
		$slugs    = $this->urls->knownSlugs();

		foreach ( $document->document()->getElementsByTagName( 'a' ) as $link ) {
			if ( ! $link->hasAttribute( 'href' ) ) {
				continue;
			}

			if ( self::hasClass( $link, self::SWITCHER_CLASS ) ) {
				continue;
			}

			$href = trim( (string) $link->getAttribute( 'href' ) );

			if ( ! self::isEligible( $href ) ) {
				continue;
			}

			$host = (string) ( wp_parse_url( $href, PHP_URL_HOST ) ?? '' );

			// Внешний домен трогать нельзя: языковой префикс есть только у нас.
			if ( '' !== $host && '' !== $homeHost && $host !== $homeHost ) {
				continue;
			}

			$localized = UrlConverter::withLanguagePrefix( $href, $basePath, $target->slug, $slugs );

			if ( $localized !== $href ) {
				$link->setAttribute( 'href', $localized );
			}
		}
	}

	/**
	 * Стоит ли вообще разбирать этот адрес. Чистая функция.
	 *
	 * Пропускаются `mailto:`, `tel:`, `javascript:`, `data:`, ссылки-якоря
	 * (`#section`) и относительные адреса без ведущего слеша (`?filter=x`,
	 * `search/`) — у них нет пути, который можно было бы безопасно
	 * префиксовать, не разрушив исходный смысл ссылки.
	 *
	 * @param string $href Значение атрибута `href`.
	 */
	public static function isEligible( string $href ): bool {
		if ( '' === $href ) {
			return false;
		}

		return 1 === preg_match( '#^(https?://|//|/(?!/))#i', $href );
	}

	/**
	 * Несёт ли элемент указанный класс. Точное совпадение токена, а не
	 * поиск подстроки: `mlp-language-item` не должен сработать на
	 * `mlp-language-items-wrapper`.
	 *
	 * @param object $element Элемент DOM.
	 * @param string $class   Искомый класс.
	 */
	private static function hasClass( object $element, string $class ): bool {
		if ( ! $element->hasAttribute( 'class' ) ) {
			return false;
		}

		$classes = preg_split( '/\s+/', trim( (string) $element->getAttribute( 'class' ) ) );

		return in_array( $class, $classes ?: array(), true );
	}
}
