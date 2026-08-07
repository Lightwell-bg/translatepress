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
	 * @param string $translation Готовый перевод.
	 */
	public function apply( string $translation ): void {
		if ( '' === $translation ) {
			return;
		}

		if ( self::KIND_ATTRIBUTE === $this->kind && null !== $this->attribute ) {
			$this->node->setAttribute( $this->attribute, $translation );

			return;
		}

		$this->node->nodeValue = $this->prefix . $translation . $this->suffix;
	}
}
