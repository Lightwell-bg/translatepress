<?php
/**
 * Обёртка над HTML-парсером.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

// Псевдоним обязателен: имена классов в PHP регистронезависимы, и импорт
// Dom\HTMLDocument столкнулся бы с именем этого класса.
use Dom\HTMLDocument as NativeHtml5Document;
use DOMDocument;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Разбор готового HTML в DOM и обратная сериализация.
 *
 * На PHP 8.4+ используется `\Dom\HTMLDocument` — настоящий HTML5-парсер на C:
 * он корректно разбирает битую разметку, не ломает void-теги и не требует
 * внешних зависимостей. На более старых версиях остаётся libxml DOMDocument
 * с обходом его давней беды с UTF-8.
 *
 * Любая ошибка разбора — не повод показать посетителю сломанную страницу:
 * вызывающий код обязан отдать исходный HTML без изменений (ТЗ 12.1, 17).
 */
final class HtmlDocument {

	/**
	 * Диапазоны для mb_encode_numericentity: весь не-ASCII.
	 */
	private const NON_ASCII = array( 0x80, 0x10FFFF, 0, 0x1FFFFF );

	/**
	 * @param DOMDocument|NativeHtml5Document $document Разобранный документ.
	 * @param bool                            $legacy   Используется ли libxml-парсер.
	 */
	private function __construct(
		private readonly object $document,
		private readonly bool $legacy
	) {
	}

	/**
	 * Доступен ли на этом PHP настоящий HTML5-парсер.
	 */
	public static function hasNativeHtml5Parser(): bool {
		return class_exists( NativeHtml5Document::class );
	}

	/**
	 * Разбирает HTML. Возвращает null, если разобрать не удалось.
	 *
	 * @param string $html        Готовый ответ страницы.
	 * @param bool   $forceLegacy Принудительно использовать libxml — нужно тестам,
	 *                            чтобы фолбэк не оставался непроверенным.
	 */
	public static function parse( string $html, bool $forceLegacy = false ): ?self {
		if ( '' === trim( $html ) ) {
			return null;
		}

		if ( ! $forceLegacy && self::hasNativeHtml5Parser() ) {
			return self::parseNative( $html );
		}

		return self::parseLegacy( $html );
	}

	/**
	 * Корневой элемент документа.
	 */
	public function root(): ?object {
		return $this->document->documentElement;
	}

	/**
	 * Сам объект документа — нужен для createElement().
	 */
	public function document(): object {
		return $this->document;
	}

	/**
	 * Сериализует документ обратно в HTML.
	 */
	public function html(): string {
		if ( ! $this->legacy ) {
			return (string) $this->document->saveHtml();
		}

		$html = (string) $this->document->saveHTML();

		// Возвращаем на место символы, закодированные перед разбором.
		return self::decodeNonAscii( $html );
	}

	/**
	 * Возвращает разметку внутри элемента.
	 *
	 * @param object $element Элемент DOM.
	 */
	public function innerHtml( object $element ): string {
		$html = '';

		foreach ( $element->childNodes as $child ) {
			$html .= $this->legacy
				? $this->document->saveHTML( $child )
				: $this->document->saveHtml( $child );
		}

		return $this->legacy
			? self::decodeNonAscii( $html )
			: $html;
	}

	/**
	 * Все не-ASCII символы → числовые сущности `&#N;`.
	 *
	 * Расширение mbstring необязательно, а WordPress не даёт полифила для
	 * `mb_encode_numericentity()`, поэтому без mbstring работает PCRE-вариант
	 * с тем же результатом. Некорректный UTF-8 PCRE не разбирает — тогда текст
	 * остаётся как есть (так же ведёт себя и путь без преобразования).
	 *
	 * @param string $html Разметка в UTF-8.
	 */
	public static function encodeNonAscii( string $html ): string {
		if ( function_exists( 'mb_encode_numericentity' ) ) {
			return mb_encode_numericentity( $html, self::NON_ASCII, 'UTF-8' );
		}

		$encoded = preg_replace_callback(
			'/[\x{80}-\x{10FFFF}]/u',
			static function ( array $match ): string {
				$bytes = $match[0];
				$first = ord( $bytes[0] );

				if ( $first < 0xE0 ) {
					$code = ( ( $first & 0x1F ) << 6 ) | ( ord( $bytes[1] ) & 0x3F );
				} elseif ( $first < 0xF0 ) {
					$code = ( ( $first & 0x0F ) << 12 ) | ( ( ord( $bytes[1] ) & 0x3F ) << 6 ) | ( ord( $bytes[2] ) & 0x3F );
				} else {
					$code = ( ( $first & 0x07 ) << 18 ) | ( ( ord( $bytes[1] ) & 0x3F ) << 12 ) | ( ( ord( $bytes[2] ) & 0x3F ) << 6 ) | ( ord( $bytes[3] ) & 0x3F );
				}

				return '&#' . $code . ';';
			},
			$html
		);

		return is_string( $encoded ) ? $encoded : $html;
	}

	/**
	 * Числовые сущности `&#N;` / `&#xH;` с кодом от U+0080 → символы UTF-8.
	 *
	 * Парный метод к {@see self::encodeNonAscii()}; без mbstring использует
	 * `html_entity_decode()`, которая входит в ядро PHP.
	 *
	 * @param string $html Разметка после сериализации libxml.
	 */
	public static function decodeNonAscii( string $html ): string {
		if ( function_exists( 'mb_decode_numericentity' ) ) {
			return mb_decode_numericentity( $html, self::NON_ASCII, 'UTF-8' );
		}

		$decoded = preg_replace_callback(
			'/&#(?:[xX]([0-9A-Fa-f]{1,6})|([0-9]{1,7}));/',
			static function ( array $match ): string {
				$code = '' !== $match[1] ? (int) hexdec( $match[1] ) : (int) $match[2];

				if ( $code < 0x80 || $code > 0x10FFFF ) {
					return $match[0];
				}

				return html_entity_decode( '&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			},
			$html
		);

		return is_string( $decoded ) ? $decoded : $html;
	}

	/**
	 * Заменяет содержимое элемента разметкой.
	 *
	 * Вызывающий код обязан очистить разметку через wp_kses ДО вызова: здесь
	 * она разбирается как есть.
	 *
	 * @param object $element Элемент DOM.
	 * @param string $html    Готовая разметка.
	 */
	public function setInnerHtml( object $element, string $html ): void {
		$fragment = $this->parseFragment( $html );

		if ( array() === $fragment ) {
			return;
		}

		while ( null !== $element->firstChild ) {
			$element->removeChild( $element->firstChild );
		}

		foreach ( $fragment as $node ) {
			$element->appendChild( $node );
		}
	}

	/**
	 * Разбирает фрагмент разметки в узлы текущего документа.
	 *
	 * @param string $html Разметка.
	 * @return list<object>
	 */
	private function parseFragment( string $html ): array {
		$source = self::parse( '<!DOCTYPE html><html><body>' . $html . '</body></html>', $this->legacy );

		if ( null === $source ) {
			return array();
		}

		// Оба парсера складывают содержимое в body — на него и опираемся,
		// вместо служебной обёртки с id, которую пришлось бы искать.
		$body = $source->document()->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return array();
		}

		$nodes = array();

		foreach ( $body->childNodes as $child ) {
			$nodes[] = $this->document->importNode( $child, true );
		}

		return $nodes;
	}

	/**
	 * Разбор встроенным HTML5-парсером PHP 8.4+.
	 *
	 * @param string $html Готовый ответ страницы.
	 */
	private static function parseNative( string $html ): ?self {
		try {
			// Только подавление ошибок: doctype и структура документа должны
			// дойти до посетителя ровно такими, какими их отдала тема.
			$document = NativeHtml5Document::createFromString( $html, LIBXML_NOERROR, 'UTF-8' );
		} catch ( Throwable $error ) {
			unset( $error );

			return null;
		}

		if ( null === $document->documentElement ) {
			return null;
		}

		return new self( $document, false );
	}

	/**
	 * Разбор через libxml для PHP 8.1–8.3.
	 *
	 * libxml считает документ latin-1, если не найдёт meta charset, и портит
	 * кириллицу. Поэтому не-ASCII символы заранее превращаются в числовые
	 * сущности, а после сериализации возвращаются обратно.
	 *
	 * @param string $html Готовый ответ страницы.
	 */
	private static function parseLegacy( string $html ): ?self {
		$encoded = self::encodeNonAscii( $html );

		$previous = libxml_use_internal_errors( true );

		$document = new DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $document->loadHTML( $encoded, LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || null === $document->documentElement ) {
			return null;
		}

		return new self( $document, true );
	}
}
