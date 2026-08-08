<?php
/**
 * Разбивка большого набора строк на запросы к провайдеру перевода.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

/**
 * Пакует строки в группы по символьному бюджету — без участия пользователя
 * (ТЗ визуального редактора: «если текст не помещается в один запрос —
 * разбивай его автоматически»).
 *
 * Один элемент никогда не режется между чанками: разрыв абзаца пополам убил
 * бы контекст, ради которого весь материал вообще отправляется одним
 * заданием, а не по строке. Если единственный элемент сам больше бюджета
 * (огромная цитата), он едет один в своём чанке — это предел того, что можно
 * сделать, не разрезая строку.
 */
final class BatchChunker {

	/**
	 * Бюджет символов на один запрос по умолчанию.
	 *
	 * Консервативное значение с запасом под системный промпт и служебные
	 * JSON-обёртки, комфортно укладывающееся в контекст обычных моделей чата.
	 */
	public const DEFAULT_MAX_CHARS = 6000;

	/**
	 * Делит строки на чанки, сохраняя порядок.
	 *
	 * @param array<string, string> $items    Ключ => текст.
	 * @param int                   $maxChars Бюджет символов на чанк.
	 * @return list<array<string, string>>
	 */
	public static function chunk( array $items, int $maxChars = self::DEFAULT_MAX_CHARS ): array {
		$maxChars = max( 1, $maxChars );
		$chunks   = array();
		$current  = array();
		$size     = 0;

		foreach ( $items as $key => $text ) {
			$length = mb_strlen( $text );

			if ( array() !== $current && $size + $length > $maxChars ) {
				$chunks[] = $current;
				$current  = array();
				$size     = 0;
			}

			$current[ $key ] = $text;
			$size            += $length;
		}

		if ( array() !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}
}
