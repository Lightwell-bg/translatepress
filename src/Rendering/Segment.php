<?php
/**
 * Переводимая единица DOM.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Одно место в документе, куда можно подставить перевод.
 *
 * Хранит ссылку на живой узел DOM, поэтому подстановка перевода — это просто
 * присваивание, без повторного поиска по документу.
 */
final class Segment {

	/** Текстовый узел. */
	public const KIND_TEXT = 'text';

	/** Значение атрибута. */
	public const KIND_ATTRIBUTE = 'attribute';

	/**
	 * Целый элемент, переводимый вместе с разметкой внутри.
	 *
	 * Нужен, когда абзац разорван инлайновыми тегами и переводить его по
	 * кусочкам бессмысленно: «Читайте <b>наш</b> блог» (ТЗ 4.4).
	 */
	public const KIND_HTML_BLOCK = 'html_block';

	/**
	 * Строка из структурированных данных JSON-LD (ТЗ 6.1: kind = seo).
	 *
	 * Живёт не в DOM, а внутри `<script type="application/ld+json">`, поэтому
	 * подстановкой занимается сам узел через TranslatableTarget.
	 */
	public const KIND_SEO = 'seo';

	/**
	 * @param object      $node      Текстовый узел или элемент-владелец атрибута.
	 * @param string      $kind      KIND_TEXT или KIND_ATTRIBUTE.
	 * @param string|null $attribute Имя атрибута для KIND_ATTRIBUTE.
	 * @param string      $text      Нормализованный исходный текст — ключ словаря.
	 * @param string      $prefix    Отступ слева в исходном узле.
	 * @param string      $suffix    Отступ справа в исходном узле.
	 * @param string      $sourceHash Hex SHA-256 нормализованного текста.
	 * @param string      $uniqHash   Hex SHA-256 полного ключа строки.
	 */
	public function __construct(
		public readonly object $node,
		public readonly string $kind,
		public readonly ?string $attribute,
		public readonly string $text,
		public readonly string $prefix,
		public readonly string $suffix,
		public readonly string $sourceHash,
		public readonly string $uniqHash
	) {
	}

	/**
	 * Подставляет перевод в документ.
	 *
	 * Экранирование здесь НЕ выполняется намеренно: и присваивание nodeValue,
	 * и setAttribute() экранируют значение сами при сериализации. Вызов
	 * esc_html() перед ними дал бы на выходе `&amp;amp;` вместо `&`.
	 *
	 * @param string            $translation Готовый перевод.
	 * @param HtmlDocument|null $document    Нужен только блокам: их перевод —
	 *                                       разметка, а не текст.
	 */
	public function apply( string $translation, ?HtmlDocument $document = null ): void {
		if ( '' === $translation ) {
			return;
		}

		// JSON-LD и прочие цели вне DOM знают сами, куда себя записать.
		if ( $this->node instanceof TranslatableTarget ) {
			$this->node->applyTranslation( $translation );

			return;
		}

		if ( self::KIND_ATTRIBUTE === $this->kind && null !== $this->attribute ) {
			$this->node->setAttribute( $this->attribute, $translation );

			return;
		}

		if ( self::KIND_HTML_BLOCK === $this->kind ) {
			if ( null !== $document ) {
				$document->setInnerHtml( $this->node, $translation );
			}

			return;
		}

		$this->node->nodeValue = $this->prefix . $translation . $this->suffix;
	}
}
