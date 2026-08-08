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
	 * Значения `name`/`property` тегов `<meta>`, чьё содержимое переводится.
	 *
	 * Точный список, без масок вида `twitter:*`: сравнение ниже — строгий
	 * `in_array()`. Поле, которого нет в списке (например `twitter:card`,
	 * `twitter:site`, `og:type`), не переводится и не подменяется — плагин
	 * его не трогает вообще. Только человекочитаемый текст. Адреса
	 * (`og:url`, `twitter:url`) и локаль (`og:locale`) сюда не входят: их
	 * не переводят, а подменяют — этим занимается SeoMeta.
	 *
	 * `twitter:label1`/`twitter:label2`/`twitter:data2` — подписи
	 * дополнительных полей карточки («Written by», «Est. reading time»,
	 * «5 minutes») и переводятся как обычный текст. `twitter:data1` в
	 * списке нет намеренно: Yoast и Rank Math кладут туда имя автора —
	 * это имя собственное, а не текст для читателя (см. класс-докблок
	 * про имена брендов).
	 */
	private const TRANSLATABLE_META = array(
		'description',
		'og:title',
		'og:description',
		'og:image:alt',
		'og:site_name',
		'article:section',
		'article:tag',
		'twitter:title',
		'twitter:description',
		'twitter:image:alt',
		'twitter:label1',
		'twitter:label2',
		'twitter:data2',
	);

	/**
	 * Инлайновые теги: их присутствие внутри абзаца не мешает перевести его
	 * целиком одним блоком.
	 */
	private const INLINE_TAGS = array(
		'a',
		'abbr',
		'b',
		'br',
		'cite',
		'del',
		'em',
		'i',
		'img',
		'ins',
		'mark',
		'q',
		's',
		'small',
		'span',
		'strong',
		'sub',
		'sup',
		'u',
		'wbr',
	);

	/**
	 * Собирает переводимые единицы документа.
	 *
	 * @param HtmlDocument        $document    Разобранный документ.
	 * @param string              $locale      Исходный язык сайта.
	 * @param array<string, true> $blockHashes Хеши известных translation blocks.
	 * @param bool                $markBlockCandidates Помечать ли кандидатов в блоки
	 *                                                 (только для предпросмотра).
	 * @return list<Segment>
	 */
	public function extract(
		HtmlDocument $document,
		string $locale,
		array $blockHashes = array(),
		bool $markBlockCandidates = false
	): array {
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
				// `script` пропускается целиком, но структурированные данные
				// внутри него — это текст для поисковика, а не код.
				foreach ( $this->jsonLdSegments( $node, $locale, $contextHash ) as $segment ) {
					$segments[] = $segment;
				}

				continue;
			}

			if ( $this->isBlockCandidate( $node ) ) {
				$block = $this->blockSegment( $node, $document, $locale, $contextHash, $blockHashes );

				if ( null !== $block ) {
					// Внутрь блока не спускаемся: он переводится целиком.
					$segments[] = $block;

					continue;
				}

				if ( $markBlockCandidates ) {
					$node->setAttribute( 'data-mlp-block', '1' );
				}
			}

			/*
			 * Стек — LIFO: чтобы дети снимались в том же порядке, в котором
			 * стоят в разметке (а не в обратном), на стек их нужно класть
			 * в обратном порядке. Раньше это было не важно — Translator и
			 * EditorMarkers работают с каждым сегментом через его собственную
			 * ссылку на узел, порядок списка им не нужен. Но RawContentPatcher
			 * (см. Rendering/RawContentPatcher.php) склеивает перевод в
			 * исходную строку, двигаясь по ней только вперёд, — ему порядок
			 * уже необходим.
			 */
			for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) {
				$stack[] = $node->childNodes->item( $i );
			}
		}

		return $segments;
	}

	/**
	 * Строки из блока структурированных данных.
	 *
	 * @param object $element     Элемент DOM.
	 * @param string $locale      Исходный язык.
	 * @param string $contextHash Хеш контекста.
	 * @return list<Segment>
	 */
	private function jsonLdSegments( object $element, string $locale, string $contextHash ): array {
		if ( 'script' !== strtolower( (string) $element->nodeName ) ) {
			return array();
		}

		$type = strtolower( trim( (string) $element->getAttribute( 'type' ) ) );

		if ( 'application/ld+json' !== $type ) {
			return array();
		}

		$json = JsonLdDocument::fromNode( $element );

		if ( null === $json ) {
			return array();
		}

		$segments = array();

		foreach ( $json->fields() as $field ) {
			$normalized = Text::normalize( $field->value );

			if ( ! Text::isTranslatable( $normalized ) ) {
				continue;
			}

			$segments[] = $this->makeSegment(
				$field,
				Segment::KIND_SEO,
				null,
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
	 * Может ли элемент стать translation block.
	 *
	 * Кандидат — элемент, чей текст разорван инлайновыми тегами: в нём есть
	 * непустой текст и хотя бы один дочерний элемент, но нет блочной вёрстки
	 * внутри. Такой абзац бессмысленно переводить по кускам.
	 *
	 * @param object $element Элемент DOM.
	 */
	private function isBlockCandidate( object $element ): bool {
		$hasText    = false;
		$hasInline  = false;

		foreach ( $element->childNodes as $child ) {
			if ( self::NODE_TEXT === $child->nodeType ) {
				$hasText = $hasText || '' !== trim( (string) $child->nodeValue );

				continue;
			}

			if ( self::NODE_ELEMENT !== $child->nodeType ) {
				continue;
			}

			if ( ! in_array( strtolower( (string) $child->nodeName ), self::INLINE_TAGS, true ) ) {
				return false;
			}

			$hasInline = true;
		}

		return $hasText && $hasInline;
	}

	/**
	 * Строит сегмент блока, если такой блок заведён.
	 *
	 * @param object              $element     Элемент DOM.
	 * @param HtmlDocument        $document    Разобранный документ.
	 * @param string              $locale      Исходный язык.
	 * @param string              $contextHash Хеш контекста.
	 * @param array<string, true> $blockHashes Хеши известных блоков.
	 */
	private function blockSegment(
		object $element,
		HtmlDocument $document,
		string $locale,
		string $contextHash,
		array $blockHashes
	): ?Segment {
		if ( array() === $blockHashes ) {
			return null;
		}

		$html = BlockSanitizer::sanitize( $document->innerHtml( $element ) );
		$hash = Hash::of( $html );

		if ( ! isset( $blockHashes[ $hash ] ) ) {
			return null;
		}

		return $this->makeSegment(
			$element,
			Segment::KIND_HTML_BLOCK,
			null,
			$html,
			'',
			'',
			$locale,
			$contextHash
		);
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

		if ( 'meta' === $tag && $this->isTranslatableMeta( $element ) ) {
			$attributes[] = 'content';
		}

		return $attributes;
	}

	/**
	 * Переводимо ли содержимое тега `<meta>`.
	 *
	 * Именно эти поля показывают превью ссылки в соцсетях и мессенджерах —
	 * без них расшаренная английская страница выглядела бы русской.
	 *
	 * Проверяются оба атрибута: стандарт Open Graph требует `property`,
	 * Twitter — `name`, а SEO-плагины (Yoast, Rank Math) в разных версиях
	 * выводят twitter-теги то так, то так.
	 *
	 * @param object $element Элемент `<meta>`.
	 */
	private function isTranslatableMeta( object $element ): bool {
		$key = strtolower( trim( (string) $element->getAttribute( 'property' ) ) );

		if ( '' === $key ) {
			$key = strtolower( trim( (string) $element->getAttribute( 'name' ) ) );
		}

		return in_array( $key, self::TRANSLATABLE_META, true );
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
