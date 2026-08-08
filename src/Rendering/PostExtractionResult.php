<?php
/**
 * Результат разбора записи целиком на переводимые сегменты.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Держит извлечённые сегменты вместе с документами, в которых живут их
 * DOM-узлы.
 *
 * Сами переводы плагин никогда не сохраняет обратно в `post_content` —
 * подстановка по-прежнему происходит при показе страницы, как и везде в
 * плагине (см. класс-докблок {@see Translator}). Документы нужны не для
 * записи, а для того, чтобы после `Segment::apply()` можно было убедиться
 * (в первую очередь — тестами), что вся остальная разметка вокруг
 * переведённого текста не пострадала: без ссылки на документ проверить это
 * было бы нечем.
 */
final class PostExtractionResult {

	/**
	 * @param list<PostSegment> $segments        Все найденные сегменты.
	 * @param HtmlDocument|null $titleDocument   Документ заголовка, если он был непустым.
	 * @param HtmlDocument|null $excerptDocument Документ excerpt, если он был непустым.
	 * @param HtmlDocument|null $contentDocument Документ содержимого, если оно было непустым.
	 */
	public function __construct(
		public readonly array $segments,
		public readonly ?HtmlDocument $titleDocument,
		public readonly ?HtmlDocument $excerptDocument,
		public readonly ?HtmlDocument $contentDocument
	) {
	}

	/**
	 * Содержимое `<body>` документа — то же значение поля без служебной
	 * обёртки `<!DOCTYPE html><html><body>...</body></html>`, в которую
	 * поле оборачивалось для разбора.
	 *
	 * @param HtmlDocument|null $document Один из *Document-документов.
	 */
	public static function bodyHtml( ?HtmlDocument $document ): string {
		if ( null === $document ) {
			return '';
		}

		$body = $document->document()->getElementsByTagName( 'body' )->item( 0 );

		return null !== $body ? $document->innerHtml( $body ) : '';
	}
}
