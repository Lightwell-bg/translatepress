<?php
/**
 * Тесты отбора адресов ссылок для словаря.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\LinkTarget;
use WpMlp\Rendering\Segment;

#[CoversClass( LinkTarget::class )]
final class LinkTargetTest extends TestCase {

	#[DataProvider( 'translatable' )]
	public function testAcceptsRealPageAddresses( string $href ): void {
		$this->assertTrue( LinkTarget::isTranslatable( $href ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function translatable(): array {
		return array(
			array( 'https://centerai.eu/' ),
			array( 'http://example.com/page/' ),
			array( '/kontakty/' ),
			array( '/blog/bg/statya/' ),
			array( '//cdn.example.com/page/' ),
			array( 'statya/' ),
			array( '/search/?q=x&page=2' ),
			// Двоеточие внутри пути — не схема.
			array( '/2026/08:12/report/' ),
		);
	}

	#[DataProvider( 'notTranslatable' )]
	public function testRejectsWhatIsNotAPage( string $href ): void {
		$this->assertFalse( LinkTarget::isTranslatable( $href ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function notTranslatable(): array {
		return array(
			array( '' ),
			array( '   ' ),
			// Якорь внутри этой же страницы — она уже переведена целиком.
			array( '#comments' ),
			array( '#' ),
			array( 'mailto:info@centerai.eu' ),
			array( 'MAILTO:info@centerai.eu' ),
			array( 'tel:+359888123456' ),
			array( 'javascript:void(0)' ),
			array( 'data:text/plain;base64,AAAA' ),
			array( 'sms:+359888' ),
			// Трекинговые простыни руками никто не переводит.
			array( 'https://example.com/?x=' . str_repeat( 'a', 2100 ) ),
		);
	}

	/**
	 * Адрес только обрезается по краям. Схлопывать внутри нельзя: любой
	 * изменённый символ ведёт уже на другую страницу.
	 */
	public function testNormalizeOnlyTrimsEdges(): void {
		$this->assertSame( '/a/b/', LinkTarget::normalize( "  /a/b/\n" ) );
		$this->assertSame( '/a%20b/', LinkTarget::normalize( '/a%20b/' ) );
	}

	/**
	 * Ровно случай с живого сайта: ссылка занимает абзац целиком, поэтому
	 * блоком не становится, и адрес нужно достать отдельной строкой —
	 * иначе перевести можно только надпись, а вёл бы он всё равно туда же.
	 */
	public function testAnchorHrefBecomesItsOwnSegment(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body><p><a href="https://centerai.eu/">Обсудить проект</a></p></body></html>'
		);

		$hrefs = array_values(
			array_filter(
				$segments,
				static fn( Segment $s ): bool => Segment::KIND_ATTRIBUTE === $s->kind && 'href' === $s->attribute
			)
		);

		$this->assertCount( 1, $hrefs );
		$this->assertSame( 'https://centerai.eu/', $hrefs[0]->text );
	}

	public function testTechnicalHrefsAreNotCollected(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body>'
			. '<a href="#top">Наверх</a><a href="mailto:a@b.c">Почта</a>'
			. '</body></html>'
		);

		foreach ( $segments as $segment ) {
			$this->assertNotSame( 'href', $segment->attribute );
		}
	}

	/**
	 * Внутри translation block адрес отдельной строкой НЕ заводится: там он
	 * часть переведённой разметки блока и правится полем в редакторе.
	 * Иначе один и тот же адрес правился бы в двух местах сразу.
	 */
	public function testHrefInsideTranslationBlockIsNotCollectedSeparately(): void {
		$html     = '<!DOCTYPE html><html><body><p>Читайте <a href="/blog/x/">наш</a> блог</p></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$paragraph = $document->document()->getElementsByTagName( 'p' )->item( 0 );
		$blockHash = \WpMlp\Support\Hash::of(
			\WpMlp\Rendering\BlockSanitizer::sanitize( $document->innerHtml( $paragraph ) )
		);

		$segments = ( new Extractor() )->extract( $document, 'ru', array( $blockHash => true ) );

		foreach ( $segments as $segment ) {
			$this->assertNotSame( 'href', $segment->attribute );
		}
	}

	/**
	 * @return list<Segment>
	 */
	private function segments( string $html ): array {
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		return ( new Extractor() )->extract( $document, 'ru' );
	}
}
