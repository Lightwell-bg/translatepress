<?php
/**
 * Тесты расстановки маркеров для визуального редактора.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\EditorMarkers;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;

#[CoversClass( EditorMarkers::class )]
final class EditorMarkersTest extends TestCase {

	/**
	 * Размечает документ так же, как это делает предпросмотр редактора.
	 *
	 * @param string $html Разметка страницы.
	 * @return string Итоговый HTML с маркерами.
	 */
	private function marked( string $html ): string {
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$ids      = array();
		$next     = 100;

		foreach ( $segments as $segment ) {
			$ids[ $segment->uniqHash ] ??= $next++;
		}

		( new EditorMarkers() )->mark( $segments, $ids, $document );

		return $document->html();
	}

	/**
	 * Если текст — единственный ребёнок, маркер вешается на сам элемент:
	 * лишняя обёртка в предпросмотре может поехать по вёрстке темы.
	 */
	public function testSingleChildParentIsMarkedWithoutWrapper(): void {
		$html = $this->marked( '<!DOCTYPE html><html><body><p>Привет</p></body></html>' );

		$this->assertStringContainsString( 'data-mlp-source-id="100"', $html );
		$this->assertStringContainsString( 'data-mlp-kind="text"', $html );
		$this->assertStringNotContainsString( 'mlp-marker', $html );
	}

	public function testMixedContentGetsSpanWrapper(): void {
		$html = $this->marked( '<!DOCTYPE html><html><body><p>Привет <b>мир</b></p></body></html>' );

		$this->assertStringContainsString( 'class="mlp-marker"', $html );
		$this->assertStringContainsString( 'Привет', $html );
		$this->assertStringContainsString( '<b', $html );
	}

	public function testAttributesAreMarkedWithTheirOwnAttribute(): void {
		$html = $this->marked(
			'<!DOCTYPE html><html><body><img src="a.png" alt="Кот" title="Подсказка"></body></html>'
		);

		$this->assertStringContainsString( 'data-mlp-source-id-alt=', $html );
		$this->assertStringContainsString( 'data-mlp-source-id-title=', $html );
	}

	/**
	 * `<title>` не виден на странице, кликнуть по нему нельзя — маркер там
	 * только замусорил бы разметку.
	 */
	public function testTitleIsNotMarked(): void {
		$html = $this->marked(
			'<!DOCTYPE html><html><head><title>Заголовок</title></head><body><p>Текст</p></body></html>'
		);

		$this->assertStringNotContainsString( '<title data-mlp', $html );
		$this->assertStringContainsString( '<title>Заголовок</title>', $html );
	}

	/**
	 * Браузер выкидывает посторонний span за пределы таблицы, поэтому внутри
	 * строки таблицы обёртка не создаётся.
	 */
	public function testNoWrapperInsideTableRow(): void {
		$html = $this->marked(
			'<!DOCTYPE html><html><body><table><tr><td>Ячейка</td></tr></table></body></html>'
		);

		// Ячейка — единственный ребёнок, её помечаем атрибутом.
		$this->assertStringContainsString( 'data-mlp-source-id=', $html );
		$this->assertStringNotContainsString( '<tr><span', $html );
	}

	public function testUnknownStringsAreLeftAlone(): void {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body><p>Привет</p></body></html>' );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );

		// Идентификаторов нет — значит и маркеров быть не должно.
		( new EditorMarkers() )->mark( $segments, array(), $document );

		$this->assertStringNotContainsString( 'data-mlp', $document->html() );
	}

	public function testBlockKeepsItsOwnElementAsMarker(): void {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body><p>Привет <b>мир</b></p></body></html>' );

		$this->assertNotNull( $document );

		$paragraph = $document->document()->getElementsByTagName( 'p' )->item( 0 );

		$this->assertNotNull( $paragraph );

		$segment = new Segment(
			$paragraph,
			Segment::KIND_HTML_BLOCK,
			null,
			'Привет <b>мир</b>',
			'',
			'',
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 )
		);

		( new EditorMarkers() )->mark( array( $segment ), array( str_repeat( 'b', 64 ) => 7 ), $document );

		$html = $document->html();

		$this->assertStringContainsString( 'data-mlp-source-id="7"', $html );
		$this->assertStringContainsString( 'data-mlp-kind="html_block"', $html );
	}
}
