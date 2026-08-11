<?php
/**
 * Служебные маркеры узлов для визуального редактора.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

use WpMlp\Support\Text;

/**
 * Помечает переводимые узлы идентификаторами строк (ТЗ 10.2).
 *
 * Маркеры ставятся только в предпросмотре редактора: в обычном ответе их быть
 * не должно, иначе они попадут в индекс и в кэш страниц.
 */
final class EditorMarkers {

	/** Идентификатор строки текстового узла или блока. */
	public const ATTR_ID = 'data-mlp-source-id';

	/** Вид строки: text или html_block. */
	public const ATTR_KIND = 'data-mlp-kind';

	/** Префикс для строк-атрибутов: data-mlp-source-id-placeholder. */
	public const ATTR_PREFIX = 'data-mlp-source-id-';

	/**
	 * Домен строки интерфейса — она не переводится в редакторе на месте.
	 *
	 * У таких строк нет ни сегмента, ни идентификатора: gettext-контур уже
	 * их обслужил, и Extractor их намеренно пропускает (иначе завелись бы
	 * дубли в «Контенте»). Без отдельной пометки клик по такой строке в
	 * предпросмотре просто ничего не делал бы — а это худший вид отказа:
	 * пользователь не понимает, сломано оно или так задумано.
	 */
	public const ATTR_GETTEXT = 'data-mlp-gettext';

	/**
	 * Родители, чьё содержимое в предпросмотре не кликабельно.
	 *
	 * `<title>` не виден на странице, а внутрь `<textarea>` разметку положить
	 * нельзя — эти строки правятся через список «Перевод строк».
	 */
	private const SKIP_PARENTS = array( 'title', 'textarea', 'script', 'style' );

	/**
	 * Родители, внутри которых нельзя создать обёртку `<span>`.
	 *
	 * Браузер вынесет посторонний элемент за пределы таблицы или списка и
	 * сломает вёрстку, поэтому здесь помечается только цельный элемент.
	 */
	private const NO_WRAP_PARENTS = array(
		'html',
		'head',
		'table',
		'thead',
		'tbody',
		'tfoot',
		'tr',
		'ul',
		'ol',
		'dl',
		'select',
		'optgroup',
	);

	/**
	 * Расставляет маркеры по найденным строкам.
	 *
	 * @param list<Segment>      $segments Строки страницы.
	 * @param array<string, int> $ids      Идентификаторы строк по hex uniq_hash.
	 * @param HtmlDocument       $document Разобранный документ.
	 */
	public function mark( array $segments, array $ids, HtmlDocument $document ): void {
		foreach ( $segments as $segment ) {
			$id = $ids[ $segment->uniqHash ] ?? null;

			if ( null === $id ) {
				continue;
			}

			if ( Segment::KIND_ATTRIBUTE === $segment->kind && null !== $segment->attribute ) {
				$segment->node->setAttribute( self::ATTR_PREFIX . $segment->attribute, (string) $id );

				continue;
			}

			if ( Segment::KIND_HTML_BLOCK === $segment->kind ) {
				$segment->node->setAttribute( self::ATTR_ID, (string) $id );
				$segment->node->setAttribute( self::ATTR_KIND, Segment::KIND_HTML_BLOCK );

				continue;
			}

			$this->markTextNode( $segment, $id, $document );
		}
	}

	/**
	 * Помечает узлы, которые обслужил gettext-контур.
	 *
	 * Отдельный проход по документу, а не часть mark(): у этих строк нет
	 * сегментов вовсе — Extractor их пропустил, — значит и пройтись по ним
	 * вместе с остальными не получится. Цена лишнего обхода платится
	 * только в предпросмотре редактора, на обычной странице этот метод не
	 * вызывается.
	 *
	 * @param HtmlDocument          $document Разобранный документ.
	 * @param array<string, string> $served   Нормализованный текст => домен.
	 */
	public function markGettext( HtmlDocument $document, array $served ): void {
		if ( array() === $served ) {
			return;
		}

		$root = $document->root();

		if ( null === $root ) {
			return;
		}

		$stack = array( $root );

		while ( array() !== $stack ) {
			$node = array_pop( $stack );

			if ( 3 === $node->nodeType ) {
				$this->markGettextNode( $node, $served );

				continue;
			}

			if ( 1 !== $node->nodeType || ! isset( $node->childNodes ) ) {
				continue;
			}

			foreach ( $node->childNodes as $child ) {
				$stack[] = $child;
			}
		}
	}

	/**
	 * Вешает пометку на родителя текстового узла, если текст пришёл из gettext.
	 *
	 * @param object                $node   Текстовый узел.
	 * @param array<string, string> $served Нормализованный текст => домен.
	 */
	private function markGettextNode( object $node, array $served ): void {
		$normalized = Text::normalize( (string) $node->nodeValue );

		if ( '' === $normalized || ! isset( $served[ $normalized ] ) ) {
			return;
		}

		$parent = $node->parentNode;

		if ( null === $parent || ! isset( $parent->nodeName ) ) {
			return;
		}

		if ( in_array( strtolower( (string) $parent->nodeName ), self::SKIP_PARENTS, true ) ) {
			return;
		}

		// Уже переводимый узел не трогаем: у него свой, полноценный маркер.
		if ( $parent->hasAttribute( self::ATTR_ID ) ) {
			return;
		}

		$parent->setAttribute( self::ATTR_GETTEXT, $served[ $normalized ] );
	}

	/**
	 * Помечает текстовый узел.
	 *
	 * Если узел единственный ребёнок родителя, маркер вешается прямо на
	 * родителя — так в предпросмотр не добавляется ни одного лишнего элемента.
	 * Иначе узел приходится обернуть в `<span>`.
	 *
	 * @param Segment      $segment  Строка.
	 * @param int          $id       Идентификатор строки.
	 * @param HtmlDocument $document Разобранный документ.
	 */
	private function markTextNode( Segment $segment, int $id, HtmlDocument $document ): void {
		$parent = $segment->node->parentNode;

		if ( null === $parent || ! isset( $parent->nodeName ) ) {
			return;
		}

		$parentTag = strtolower( (string) $parent->nodeName );

		if ( in_array( $parentTag, self::SKIP_PARENTS, true ) ) {
			return;
		}

		if ( 1 === $parent->childNodes->length && $parent->hasAttribute( self::ATTR_ID ) === false ) {
			$parent->setAttribute( self::ATTR_ID, (string) $id );
			$parent->setAttribute( self::ATTR_KIND, Segment::KIND_TEXT );

			return;
		}

		if ( in_array( $parentTag, self::NO_WRAP_PARENTS, true ) ) {
			return;
		}

		$span = $document->document()->createElement( 'span' );
		$span->setAttribute( self::ATTR_ID, (string) $id );
		$span->setAttribute( self::ATTR_KIND, Segment::KIND_TEXT );
		$span->setAttribute( 'class', 'mlp-marker' );

		$parent->replaceChild( $span, $segment->node );
		$span->appendChild( $segment->node );
	}
}
