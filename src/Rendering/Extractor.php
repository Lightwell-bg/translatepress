<?php
/**
 * Извлечение переводимых строк из DOM.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

use WpMlp\Support\Hash;
use WpMlp\Support\Text;

/**
 * Обходит документ и собирает всё, что можно перевести (ТЗ 4.3–4.4).
 *
 * Обход итеративный, со стеком: рекурсия на глубоких деревьях страниц
 * конструкторов легко упирается в лимит вложенности PHP.
 */
final class Extractor {

	/** Тип узла: элемент. */
	private const NODE_ELEMENT = 1;

	/** Тип узла: текст. */
	private const NODE_TEXT = 3;

	/**
	 * Теги, внутрь которых не заходим совсем.
	 *
	 * Здесь либо код, либо графика: перевод сломает разметку или потратит
	 * время впустую. `script` и `style` исключаются до разбора по ТЗ 4.3.
	 */
	private const SKIP_SUBTREE = array(
		'script',
		'style',
		'noscript',
		'template',
		'svg',
		'math',
		'iframe',
		'object',
		'embed',
		'canvas',
		'code',
		'pre',
		'kbd',
		'samp',
		'var',
		'textarea',
	);

	/**
	 * Элементы служебного интерфейса WordPress по id.
	 */
	private const SKIP_IDS = array( 'wpadminbar' );

	/**
	 * Атрибуты, переводимые у любого элемента.
	 */
	private const GLOBAL_ATTRIBUTES = array( 'title', 'alt', 'placeholder', 'aria-label' );

	/**
	 * Типы input, у которых `value` — это надпись на кнопке, а не данные.
	 */
	private const BUTTON_TYPES = array( 'submit', 'button', 'reset' );

	/**
	 * Собирает переводимые единицы документа.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param string       $locale   Исходный язык сайта.
	 * @return list<Segment>
	 */
	public function extract( HtmlDocument $document, string $locale ): array {
		$root = $document->root();

		if ( null === $root ) {
			return array();
		}

		$contextHash = Hash::of( '' );
		$segments    = array();
		$stack       = array( $root );

		while ( array() !== $stack ) {
			$node = array_pop( $stack );

			if ( self::NODE_TEXT === $node->nodeType ) {
				$segment = $this->textSegment( $node, $locale, $contextHash );

				if ( null !== $segment ) {
					$segments[] = $segment;
				}

				continue;
			}

			if ( self::NODE_ELEMENT !== $node->nodeType ) {
				continue;
			}

			if ( $this->isSkipped( $node ) ) {
				continue;
			}

			foreach ( $this->attributeSegments( $node, $locale, $contextHash ) as $segment ) {
				$segments[] = $segment;
			}

			if ( in_array( strtolower( (string) $node->nodeName ), self::SKIP_SUBTREE, true ) ) {
				continue;
			}

			foreach ( $node->childNodes as $child ) {
				$stack[] = $child;
			}
		}

		return $segments;
	}

	/**
	 * Не переводить это поддерево?
	 *
	 * @param object $element Элемент DOM.
	 */
	private function isSkipped( object $element ): bool {
		if ( $element->hasAttribute( 'data-no-translation' ) ) {
			return true;
		}

		if ( 'no' === strtolower( (string) $element->getAttribute( 'translate' ) ) ) {
			return true;
		}

		return in_array( strtolower( (string) $element->getAttribute( 'id' ) ), self::SKIP_IDS, true );
	}

	/**
	 * Строит сегмент для текстового узла.
	 *
	 * @param object $node        Текстовый узел.
	 * @param string $locale      Исходный язык.
	 * @param string $contextHash Хеш контекста.
	 */
	private function textSegment( object $node, string $locale, string $contextHash ): ?Segment {
		$raw = (string) $node->nodeValue;

		if ( '' === trim( $raw ) ) {
			return null;
		}

		list( $prefix, $core, $suffix ) = Text::splitEdges( $raw );

		$normalized = Text::normalize( $core );

		if ( ! Text::isTranslatable( $normalized ) ) {
			return null;
		}

		return $this->makeSegment( $node, Segment::KIND_TEXT, null, $normalized, $prefix, $suffix, $locale, $contextHash );
	}

	/**
	 * Строит сегменты для переводимых атрибутов элемента.
	 *
	 * @param object $element     Элемент DOM.
	 * @param string $locale      Исходный язык.
	 * @param string $contextHash Хеш контекста.
	 * @return list<Segment>
	 */
	private function attributeSegments( object $element, string $locale, string $contextHash ): array {
		$segments = array();

		foreach ( $this->translatableAttributes( $element ) as $attribute ) {
			if ( ! $element->hasAttribute( $attribute ) ) {
				continue;
			}

			$normalized = Text::normalize( (string) $element->getAttribute( $attribute ) );

			if ( ! Text::isTranslatable( $normalized ) ) {
				continue;
			}

			$segments[] = $this->makeSegment(
				$element,
				Segment::KIND_ATTRIBUTE,
				$attribute,
				$normalized,
				'',
				'',
				$locale,
				$contextHash
			);
		}

		return $segments;
	}

	/**
	 * Какие атрибуты этого элемента переводимы.
	 *
	 * @param object $element Элемент DOM.
	 * @return list<string>
	 */
	private function translatableAttributes( object $element ): array {
		$attributes = self::GLOBAL_ATTRIBUTES;
		$tag        = strtolower( (string) $element->nodeName );

		// `value` — надпись только у кнопок; у текстовых полей это данные посетителя.
		if ( 'input' === $tag
			&& in_array( strtolower( (string) $element->getAttribute( 'type' ) ), self::BUTTON_TYPES, true )
		) {
			$attributes[] = 'value';
		}

		// Единственный meta, который нужен уже на Этапе 1: описание страницы.
		if ( 'meta' === $tag && 'description' === strtolower( (string) $element->getAttribute( 'name' ) ) ) {
			$attributes[] = 'content';
		}

		return $attributes;
	}

	/**
	 * Считает хеши и собирает сегмент.
	 *
	 * @param object      $node        Узел DOM.
	 * @param string      $kind        Segment::KIND_*.
	 * @param string|null $attribute   Имя атрибута.
	 * @param string      $text        Нормализованный текст.
	 * @param string      $prefix      Отступ слева.
	 * @param string      $suffix      Отступ справа.
	 * @param string      $locale      Исходный язык.
	 * @param string      $contextHash Хеш контекста.
	 */
	private function makeSegment(
		object $node,
		string $kind,
		?string $attribute,
		string $text,
		string $prefix,
		string $suffix,
		string $locale,
		string $contextHash
	): Segment {
		$sourceHash = Hash::of( $text );

		/*
		 * Порядок частей повторяет уникальный индекс из ТЗ 6.1. domain,
		 * gettext_context и plural_key на Этапе 1 всегда пустые: gettext-контур
		 * появится позже, и тогда они начнут различать одинаковые строки.
		 */
		$uniqHash = Hash::ofParts(
			array( $locale, $kind, $sourceHash, $contextHash, '', '', '' )
		);

		return new Segment( $node, $kind, $attribute, $text, $prefix, $suffix, $sourceHash, $uniqHash );
	}
}
