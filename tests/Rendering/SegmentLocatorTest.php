<?php
/**
 * Тесты поиска точного расположения сегмента в исходной строке.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;
use WpMlp\Rendering\SegmentLocator;

#[CoversClass( SegmentLocator::class )]
final class SegmentLocatorTest extends TestCase {

	/**
	 * @return list<Segment>
	 */
	private function segmentsOf( string $bodyHtml ): array {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body>' . $bodyHtml . '</body></html>' );

		$this->assertNotNull( $document );

		return ( new Extractor() )->extract( $document, 'ru' );
	}

	public function testPlainTextGivesExactCandidate(): void {
		$segments = $this->segmentsOf( '<p>Привет мир</p>' );

		$this->assertSame( array( 'Привет мир' ), SegmentLocator::candidates( $segments[0] ) );
	}

	/**
	 * Внутренний перевод строки/множественные пробелы в исходном узле не
	 * нормализуются здесь — это буквально то, что лежит в разметке, и
	 * именно это нужно искать, а не нормализованный ключ словаря.
	 */
	public function testCandidateKeepsOriginalInternalWhitespace(): void {
		$segments = $this->segmentsOf( "<p>Привет    мир</p>" );

		$this->assertSame( array( 'Привет    мир' ), SegmentLocator::candidates( $segments[0] ) );
		// А вот значение сегмента для словаря — уже нормализовано.
		$this->assertSame( 'Привет мир', $segments[0]->text );
	}

	/**
	 * Амперсанд — самый частый случай: HTML-парсер отдаёт nodeValue уже
	 * декодированным («&amp;» → «&»), а в исходной строке он почти всегда
	 * записан именно как сущность. Второй кандидат — заэкранированный —
	 * ровно для этого.
	 */
	public function testTextWithAmpersandOffersEscapedFallbackCandidate(): void {
		$html     = '<!DOCTYPE html><html><body><p>Tom &amp; Jerry</p></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$segment  = $segments[0];

		$this->assertSame( 'Tom & Jerry', $segment->text );
		$this->assertSame( array( 'Tom & Jerry', 'Tom &amp; Jerry' ), SegmentLocator::candidates( $segment ) );

		// И действительно находится в исходной строке — по второму кандидату.
		$this->assertStringContainsString( SegmentLocator::candidates( $segment )[1], $html );
	}

	public function testAttributeGivesExactCandidate(): void {
		$segments = $this->segmentsOf( '<img src="a.png" alt="Описание фото">' );

		$attributeSegment = null;

		foreach ( $segments as $segment ) {
			if ( Segment::KIND_ATTRIBUTE === $segment->kind ) {
				$attributeSegment = $segment;
			}
		}

		$this->assertNotNull( $attributeSegment );
		$this->assertSame( array( 'Описание фото' ), SegmentLocator::candidates( $attributeSegment ) );
	}

	/**
	 * Html_block (перевод содержит разметку) и SEO (не бывает в
	 * post_content) — не текст в известном месте строки, кандидатов нет:
	 * PostFieldPatcher обязан пропустить такой сегмент, а не пытаться
	 * искать его вслепую.
	 */
	public function testHtmlBlockHasNoCandidates(): void {
		$segment = new Segment(
			new \stdClass(),
			Segment::KIND_HTML_BLOCK,
			null,
			'Читайте <b>наш</b> блог',
			'',
			'',
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 )
		);

		$this->assertSame( array(), SegmentLocator::candidates( $segment ) );
	}
}
