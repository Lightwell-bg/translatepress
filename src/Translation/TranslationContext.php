<?php
/**
 * Контекст запроса на перевод.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

/**
 * Что провайдеру полезно знать о переводимых строках.
 *
 * Модели перевода дают заметно лучший результат, когда понимают, откуда взята
 * строка: заголовок кнопки и абзац статьи переводятся по-разному. На Этапе 1
 * объект только заполняется и передаётся дальше — использовать его начнёт
 * OpenAI-адаптер следующей сессией.
 */
final class TranslationContext {

	/**
	 * @param string               $kind       Вид строк: text, attribute, html_block.
	 * @param string|null          $objectType Тип объекта WordPress: post, term, url.
	 * @param int|null             $objectId   Идентификатор объекта.
	 * @param string|null          $url        Адрес страницы, где встретились строки.
	 * @param array<string,string> $glossary   Термины с фиксированным переводом.
	 */
	public function __construct(
		public readonly string $kind = 'text',
		public readonly ?string $objectType = null,
		public readonly ?int $objectId = null,
		public readonly ?string $url = null,
		public readonly array $glossary = array()
	) {
	}
}
