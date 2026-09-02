<?php
/**
 * Перевод заголовка ссылки на RSS-фид комментариев записи.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Rendering\DocumentFilter;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\SourceRepository;
use WpMlp\Support\Hash;
use WpMlp\Support\Text;

/**
 * Заголовок вида `title="CenterAI » {заголовок записи} Comments Feed"`
 * ядро WordPress собирает само, в `feed_links_extra()` на `wp_head`, —
 * ДО того, как плагин получает готовый HTML. Слово «Comments Feed» при
 * этом уже переведено: его переводит сам WordPress через LocaleSwitcher,
 * официальным языковым пакетом (см. `I18n\LocaleSwitcher`). А вот
 * заголовок записи внутри строки — нет: он взят напрямую из БД функцией
 * `get_the_title()`, и в архитектуре «одна запись, N языковых URL» это
 * всегда оригинал, независимо от языка страницы.
 *
 * Атрибут `title` у `<link>` в остальном намеренно не переводится вовсе
 * (см. `Extractor::METADATA_TAGS`) — таких строк на сайте десятки, по
 * одной на рубрику/метку/автора, и обычный посетитель их не видит. Эта
 * ссылка — исключение: в отличие от прочих, она несёт настоящий текст
 * записи, а не служебный ярлык, и текст этот виден в RSS-агрегаторах и
 * исходном коде страницы.
 *
 * Перевод не запрашивается отдельно и не отображается в «Переводе строк»
 * как своя строка: заголовок записи уже переводится обычным образом (в
 * H1/`<title>`), и здесь используется тот же самый перевод — по общему
 * для всех текстовых сегментов правилу хеширования. Владельцу сайта
 * ничего дополнительно заполнять не нужно.
 */
final class CommentsFeedTitle implements DocumentFilter {

	/**
	 * @param Settings         $settings Настройки плагина.
	 * @param SourceRepository $sources  Поиск сохранённых переводов.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SourceRepository $sources
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( HtmlDocument $document, Language $target ): void {
		if ( $target->isDefault ) {
			return;
		}

		// Query ещё не разрешён (`wp` не отработал) — доверять его данным нельзя.
		if ( ! did_action( 'wp' ) || ! is_singular() ) {
			return;
		}

		$postId = get_queried_object_id();

		if ( $postId <= 0 ) {
			return;
		}

		$rawTitle = trim( (string) get_the_title( $postId ) );

		if ( '' === $rawTitle ) {
			return;
		}

		$href = (string) get_post_comments_feed_link( $postId );

		if ( '' === $href ) {
			return;
		}

		$link = self::findLinkElement( $document, $href );

		if ( null === $link ) {
			return;
		}

		$translated = $this->translatedTitle( $rawTitle, $target->locale );

		if ( null === $translated ) {
			return;
		}

		$current = (string) $link->getAttribute( 'title' );
		$updated = self::replaceTitle( $current, $rawTitle, $translated );

		if ( $updated !== $current ) {
			$link->setAttribute( 'title', $updated );
		}
	}

	/**
	 * Ищет в документе `<link>` фида комментариев этой записи.
	 *
	 * @param HtmlDocument $document Документ.
	 * @param string       $href     Адрес фида комментариев записи.
	 */
	public static function findLinkElement( HtmlDocument $document, string $href ): ?object {
		foreach ( $document->document()->getElementsByTagName( 'link' ) as $link ) {
			if ( ! $link->hasAttribute( 'title' ) || ! $link->hasAttribute( 'href' ) ) {
				continue;
			}

			$attributes = array(
				'rel'  => (string) $link->getAttribute( 'rel' ),
				'type' => (string) $link->getAttribute( 'type' ),
				'href' => (string) $link->getAttribute( 'href' ),
			);

			if ( self::matchesFeedLink( $attributes, $href ) ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Уже сохранённый перевод заголовка записи для целевого языка.
	 *
	 * @param string $rawTitle     Заголовок записи как есть в БД.
	 * @param string $targetLocale Целевой язык.
	 */
	private function translatedTitle( string $rawTitle, string $targetLocale ): ?string {
		$hash  = self::uniqHash( $rawTitle, $this->settings->defaultLanguage()->locale );
		$found = $this->sources->lookup( array( $hash ), $targetLocale );
		$text  = $found[ $hash ]['text'] ?? null;

		return ( is_string( $text ) && '' !== $text ) ? $text : null;
	}

	/**
	 * uniq_hash заголовка записи как обычного текстового сегмента. Чистая
	 * функция.
	 *
	 * Повторяет расчёт `Extractor::makeSegment()` для `Segment::KIND_TEXT`:
	 * тот же способ нормализации, тот же порядок частей ключа. Иначе
	 * перевод, уже сохранённый для H1/`<title>` этой же записи, здесь
	 * попросту не нашёлся бы.
	 *
	 * @param string $rawTitle     Заголовок записи как есть в БД.
	 * @param string $sourceLocale Исходный язык сайта.
	 */
	public static function uniqHash( string $rawTitle, string $sourceLocale ): string {
		$sourceHash = Hash::of( Text::normalize( $rawTitle ) );

		return Hash::ofParts( array( $sourceLocale, Segment::KIND_TEXT, $sourceHash, Hash::of( '' ), '', '', '' ) );
	}

	/**
	 * Подставляет перевод заголовка внутрь значения атрибута. Чистая
	 * функция.
	 *
	 * Заменяется только сам заголовок — «CenterAI »/« Comments Feed»
	 * вокруг него уже переведены WordPress: их незачем разбирать и
	 * пересобирать заново, риск ошибиться в шаблоне выше пользы.
	 *
	 * Проверка `'' === $rawTitle` ничего не меняет функционально —
	 * `str_replace('', ...)` в PHP и без неё тихо возвращает строку как
	 * есть, вставки между символами не будет. Оставлена ради читаемости:
	 * без неё код полагается на это поведение неявно, через два
	 * последовательных частных случая (`str_contains` с пустой иголкой
	 * всегда истинен, а `str_replace` с пустой — no-op), и это легко
	 * счесть багом при следующем чтении. Мутационная проверка эту строку
	 * не ловит — поведение от неё не зависит, что здесь честно и ожидаемо.
	 *
	 * @param string $attributeValue Текущее значение атрибута `title`.
	 * @param string $rawTitle       Заголовок записи как есть в БД.
	 * @param string $translated     Перевод заголовка.
	 */
	public static function replaceTitle( string $attributeValue, string $rawTitle, string $translated ): string {
		if ( '' === $rawTitle || ! str_contains( $attributeValue, $rawTitle ) ) {
			return $attributeValue;
		}

		return str_replace( $rawTitle, $translated, $attributeValue );
	}

	/**
	 * Ссылка ли это на фид комментариев именно этой записи. Чистая
	 * функция.
	 *
	 * @param array{rel: string, type: string, href: string} $attributes Атрибуты ссылки.
	 * @param string                                          $href       Ожидаемый адрес.
	 */
	public static function matchesFeedLink( array $attributes, string $href ): bool {
		if ( 'alternate' !== strtolower( $attributes['rel'] ) ) {
			return false;
		}

		if ( 'application/rss+xml' !== strtolower( $attributes['type'] ) ) {
			return false;
		}

		return $attributes['href'] === $href;
	}
}
