<?php
/**
 * Тесты translation blocks.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\BlockSanitizer;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;
use WpMlp\Support\Hash;

#[CoversClass( BlockSanitizer::class )]
#[CoversClass( Extractor::class )]
final class BlockTest extends TestCase {

	public function testSanitizerStripsEditorMarkers(): void {
		$clean = BlockSanitizer::sanitize( '<span data-mlp-source-id="12" class="mlp-marker">Привет</span> <b>мир</b>' );

		$this->assertStringNotContainsString( 'data-mlp', $clean );
		$this->assertStringNotContainsString( 'mlp-marker', $clean );
		$this->assertStringContainsString( 'Привет', $clean );
		$this->assertStringContainsString( '<b>мир</b>', $clean );
	}

	public function testSanitizerCollapsesWhitespace(): void {
		$this->assertSame( 'Привет <b>мир</b>', BlockSanitizer::sanitize( "  Привет\n  <b>мир</b>  " ) );
	}

	/**
	 * Скрипты внутри блока недопустимы: содержимое блока подставляется в
	 * страницу как разметка, а не как текст.
	 */
	public function testSanitizerDropsScripts(): void {
		$clean = BlockSanitizer::sanitize( 'Привет <script>alert(1)</script><b>мир</b>' );

		$this->assertStringNotContainsString( '<script', $clean );
		$this->assertStringContainsString( '<b>мир</b>', $clean );
	}

	/**
	 * Абзац с инлайновыми тегами — кандидат в блок; в предпросмотре редактора
	 * он помечается, чтобы по нему можно было кликнуть.
	 */
	public function testBlockCandidateIsMarkedInEditorMode(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Читайте <b>наш</b> блог</p></body></html>'
		);

		$this->assertNotNull( $document );

		( new Extractor() )->extract( $document, 'ru', array(), true );

		$this->assertStringContainsString( 'data-mlp-block="1"', $document->html() );
	}

	/**
	 * Внутри абзаца с блочной вёрсткой объединять нечего: это не один текст.
	 */
	public function testElementWithBlockChildIsNotCandidate(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><div>Текст<div>Вложенный</div></div></body></html>'
		);

		$this->assertNotNull( $document );

		( new Extractor() )->extract( $document, 'ru', array(), true );

		$this->assertStringNotContainsString( 'data-mlp-block', $document->html() );
	}

	/**
	 * Когда блок заведён, абзац становится одной строкой, а его куски
	 * перестают извлекаться по отдельности.
	 */
	public function testKnownBlockReplacesItsPartsWithOneSegment(): void {
		$html     = '<!DOCTYPE html><html><body><p>Читайте <b>наш</b> блог</p></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$paragraph = $document->document()->getElementsByTagName( 'p' )->item( 0 );
		$inner     = BlockSanitizer::sanitize( $document->innerHtml( $paragraph ) );

		$segments = ( new Extractor() )->extract(
			$document,
			'ru',
			array( Hash::of( $inner ) => true )
		);

		$this->assertCount( 1, $segments );
		$this->assertSame( Segment::KIND_HTML_BLOCK, $segments[0]->kind );
		$this->assertSame( 'Читайте <b>наш</b> блог', $segments[0]->text );
	}

	public function testBlockTranslationReplacesMarkup(): void {
		$html     = '<!DOCTYPE html><html><body><p>Читайте <b>наш</b> блог</p></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$paragraph = $document->document()->getElementsByTagName( 'p' )->item( 0 );
		$inner     = BlockSanitizer::sanitize( $document->innerHtml( $paragraph ) );

		$segments = ( new Extractor() )->extract( $document, 'ru', array( Hash::of( $inner ) => true ) );
		$segments[0]->apply( 'Read <b>our</b> blog', $document );

		$result = $document->html();

		$this->assertStringContainsString( '<p>Read <b>our</b> blog</p>', $result );
		$this->assertStringNotContainsString( 'Читайте', $result );
	}

	public function testWithoutKnownBlocksNothingChanges(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Читайте <b>наш</b> блог</p></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$kinds    = array_map( static fn( Segment $segment ): string => $segment->kind, $segments );

		$this->assertNotContains( Segment::KIND_HTML_BLOCK, $kinds );
	}
}
