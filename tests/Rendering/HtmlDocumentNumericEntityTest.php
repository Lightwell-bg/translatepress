<?php
/**
 * Тесты кодирования не-ASCII в числовые сущности (путь libxml без обязательного mbstring).
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rendering\HtmlDocument;

#[CoversClass( HtmlDocument::class )]
final class HtmlDocumentNumericEntityTest extends TestCase {

	public function testEncodeNonAsciiProducesDecimalEntities(): void {
		$this->assertSame( '<p>a&#1078;&#8364;&#128512;</p>', HtmlDocument::encodeNonAscii( "<p>a\u{436}\u{20AC}\u{1F600}</p>" ) );
	}

	public function testRoundTripKeepsText(): void {
		$text = "<p class=\"x\">Привет, мир! &amp; \u{1F1E7}\u{1F1EC} ü</p>";

		$this->assertSame( $text, HtmlDocument::decodeNonAscii( HtmlDocument::encodeNonAscii( $text ) ) );
	}

	public function testDecodeLeavesAsciiEntitiesAlone(): void {
		$this->assertSame( '&#60; &#x41;', HtmlDocument::decodeNonAscii( '&#60; &#x41;' ) );
		$this->assertSame( "\u{436}", HtmlDocument::decodeNonAscii( '&#x436;' ) );
	}
}
