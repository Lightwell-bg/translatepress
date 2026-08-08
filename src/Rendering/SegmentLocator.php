<?php
/**
 * Точное расположение сегмента в ещё не разобранной исходной строке.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

use WpMlp\Support\Text;

/**
 * Находит, каким именно куском текста в СЫРОЙ, ещё не разобранной строке
 * (`post_title`/`post_excerpt`/`post_content` как они лежат в базе) нужно
 * заменять сегмент — не через повторную сборку DOM, а прямой подстрокой.
 *
 * DOM-парсер решает entity-сущности при разборе: `&amp;` в исходнике
 * становится `&` в `nodeValue`. Это значит, что «искать буквально
 * `$segment->text` в исходной строке» сработает не всегда — если в тексте
 * был `&`, `<`, `>`, автор (или сам WordPress при сохранении) мог записать
 * его как сущность. Поэтому здесь не один кандидат для поиска, а несколько,
 * от буквального к экранированному, — {@see RawContentPatcher} пробует их
 * по порядку и берёт первый, который в исходной строке действительно
 * нашёлся. Если не нашёлся ни один — значит текст был закодирован каким-то
 * третьим способом (числовая сущность, именованная сущность вроде
 * `&mdash;`, вписанная в текст вручную), и подставлять перевод НЕКУДА:
 * патчер должен провалиться явно, а не гадать.
 */
final class SegmentLocator {

	/**
	 * Кандидаты точной подстроки, которую нужно найти в исходной строке —
	 * от самого раннего до самого позднего в списке приоритета.
	 *
	 * @param Segment $segment Сегмент (KIND_TEXT или KIND_ATTRIBUTE — для
	 *                         остальных видов возвращается пустой список:
	 *                         KIND_HTML_BLOCK сам по себе разметка, а не
	 *                         текст в известном месте строки, и заменяется
	 *                         иначе; KIND_SEO из post_content не бывает).
	 * @return list<string>
	 */
	public static function candidates( Segment $segment ): array {
		$raw = self::rawValue( $segment );

		if ( '' === $raw ) {
			return array();
		}

		$encoded = htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' );

		return $encoded === $raw ? array( $raw ) : array( $raw, $encoded );
	}

	/**
	 * Сырое (ещё не нормализованное) значение узла или атрибута — как оно
	 * было у ЭТОГО конкретного сегмента на момент разбора, до применения
	 * Text::normalize(). Внутренние пробелы могут отличаться от
	 * $segment->text (это нормально: именно поэтому ищем ЭТУ строку, а
	 * подставляем перевод $segment->text).
	 *
	 * @param Segment $segment Сегмент.
	 */
	private static function rawValue( Segment $segment ): string {
		if ( Segment::KIND_ATTRIBUTE === $segment->kind && null !== $segment->attribute ) {
			return (string) $segment->node->getAttribute( $segment->attribute );
		}

		if ( Segment::KIND_TEXT === $segment->kind ) {
			list( , $core, ) = Text::splitEdges( (string) $segment->node->nodeValue );

			return $core;
		}

		return '';
	}
}
