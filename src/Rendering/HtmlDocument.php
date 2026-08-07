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
		return mb_decode_numericentity( $html, self::NON_ASCII, 'UTF-8' );
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
		$encoded = mb_encode_numericentity( $html, self::NON_ASCII, 'UTF-8' );

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
