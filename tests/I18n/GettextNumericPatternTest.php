<?php
/**
 * Тесты числовых шаблонов gettext («%d minutes» → «13 minutes») и их
 * применения в Extractor.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LingoCanvas\I18n\GettextFragments;
use LingoCanvas\Rendering\Extractor;
use LingoCanvas\Rendering\HtmlDocument;
use LingoCanvas\Rendering\Segment;
use LingoCanvas\Support\Text;

#[CoversClass( GettextFragments::class )]
#[CoversClass( Extractor::class )]
#[CoversClass( Text::class )]
final class GettextNumericPatternTest extends TestCase {

	/**
	 * Совпадает ли нормализованный текст с шаблоном.
	 *
	 * @param string $template Строка gettext с плейсхолдерами.
	 * @param string $text     Текст из DOM.
	 */
	private function templateMatches( string $template, string $text ): bool {
		$pattern = GettextFragments::numericPattern( $template );

		$this->assertNotNull( $pattern );

		return 1 === preg_match( $pattern, Text::normalize( $text ) );
	}

	public function testEnglishMinutesTemplateMatchesNumbers(): void {
		$this->assertTrue( $this->templateMatches( '%d minutes', '13 minutes' ) );
		$this->assertTrue( $this->templateMatches( '%d minutes', '6 minutes' ) );
		$this->assertFalse( $this->templateMatches( '%d minutes', 'many minutes' ) );
	}

	public function testGermanTemplateMatches(): void {
		$this->assertTrue( $this->templateMatches( '%d Minuten', '13 Minuten' ) );
	}

	public function testDecimalNumberMatches(): void {
		$this->assertTrue( $this->templateMatches( '%s min read', '1,5 min read' ) );
		$this->assertTrue( $this->templateMatches( '%s min read', '1.5 min read' ) );
	}

	/**
	 * @param string $template Шаблон без букв в литеральной части.
	 */
	#[DataProvider( 'lettersFreeTemplates' )]
	public function testTemplateWithoutLettersIsNotRecorded( string $template ): void {
		$this->assertNull( GettextFragments::numericPattern( $template ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function lettersFreeTemplates(): array {
		return array(
			'string'   => array( '%s' ),
			'numbered' => array( '%1$s – %2$s' ),
			'digit'    => array( '%d' ),
			'percent'  => array( '%d%%' ),
		);
	}

	public function testStringWithoutPlaceholdersIsNotRecorded(): void {
		$this->assertNull( GettextFragments::numericPattern( 'Reply now' ) );
		$this->assertNull( GettextFragments::numericPattern( '100%% sure' ) );
	}

	public function testPlaceholdersMatchOnlyNumbersNotWords(): void {
		$this->assertFalse( $this->templateMatches( '%s at %s', 'Meeting at noon' ) );
		$this->assertTrue( $this->templateMatches( '%s at %s', '5 at 10' ) );
	}

	public function testDoublePercentIsLiteral(): void {
		$this->assertTrue( $this->templateMatches( '%d%% done', '50% done' ) );
		$this->assertFalse( $this->templateMatches( '%d%% done', '50 done' ) );
	}

	public function testRegexMetacharactersInLiteralAreQuoted(): void {
		$this->assertTrue( $this->templateMatches( '(%d) items [x] + a.b/c', '(3) items [x] + a.b/c' ) );
		$this->assertFalse( $this->templateMatches( '(%d) items [x] + a.b/c', '(3) items [x] + aXb/c' ) );
	}

	public function testTemplateWithTagsIsNotRecorded(): void {
		$this->assertNull( GettextFragments::numericPattern( '<b>%d</b> minutes' ) );
	}

	/**
	 * Тексты сегментов, найденных Extractor.
	 *
	 * @param string       $html     Разметка.
	 * @param list<string> $patterns Числовые шаблоны.
	 * @return list<string>
	 */
	private function extract( string $html, array $patterns ): array {
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		return array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru', array(), false, array(), $patterns )
		);
	}

	public function testExtractorSkipsServedNumericText(): void {
		$patterns = array(
			(string) GettextFragments::numericPattern( '%d minutes' ),
			(string) GettextFragments::numericPattern( '%d Minuten' ),
		);

		$texts = $this->extract(
			'<!DOCTYPE html><html><body><p>13 minutes</p><p>6 Minuten</p><p>Meeting at noon</p></body></html>',
			$patterns
		);

		$this->assertSame( array( 'Meeting at noon' ), $texts );
	}

	public function testNumericPatternWithEntityMatchesDecodedText(): void {
		$this->assertTrue( $this->templateMatches( '%d comments &rarr;', '5 comments →' ) );
	}

	public function testExtractorSkipsServedTextWithDecodedEntity(): void {
		$skip = array();

		foreach ( GettextFragments::of( 'Du hast noch kein Menü, bitte erstellen eines hier &rarr;' ) as $fragment ) {
			$skip[ $fragment ] = true;
		}

		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Du hast noch kein Menü, bitte erstellen eines hier &rarr;</p><p>Meeting at noon</p></body></html>'
		);

		$this->assertNotNull( $document );

		$texts = array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru', array(), false, $skip )
		);

		$this->assertSame( array( 'Meeting at noon' ), $texts );
	}

	public function testExtractorSkipsServedNumericMeta(): void {
		$html = '<!DOCTYPE html><html><head><meta name="twitter:data2" content="13 Minuten"></head><body></body></html>';

		$this->assertSame( array( '13 Minuten' ), $this->extract( $html, array() ) );
		$this->assertSame(
			array(),
			$this->extract( $html, array( (string) GettextFragments::numericPattern( '%d Minuten' ) ) )
		);
	}

	public function testExtractorSkipsServedNumericJsonLd(): void {
		$html = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","description":"13 minutes"}</script></head><body></body></html>';

		$this->assertSame(
			array(),
			$this->extract( $html, array( (string) GettextFragments::numericPattern( '%d minutes' ) ) )
		);
	}

	/**
	 * @param string $text     Значение.
	 * @param bool   $expected Ожидание.
	 */
	#[DataProvider( 'fileNames' )]
	public function testLooksLikeFileName( string $text, bool $expected ): void {
		$this->assertSame( $expected, Text::looksLikeFileName( $text ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function fileNames(): array {
		return array(
			'underscored title' => array( 'Zero-Hallucination_Decision_Engine_Guide', true ),
			'jpg'               => array( 'IMG_2024.jpg', true ),
			'webp'              => array( 'photo.webp', true ),
			'wifi'              => array( 'Wi-Fi', false ),
			'hyphen word'       => array( 'Zero-Hallucination', false ),
			'with space'        => array( 'Windows 11', false ),
			'two words'         => array( 'DeepSeek Harness', false ),
			'sentence with ext' => array( 'My photo.jpg here', false ),
		);
	}

	public function testFileNameLikeImageTextIsNotExtracted(): void {
		$html = '<!DOCTYPE html><html><head>'
			. '<meta property="og:image:alt" content="Zero-Hallucination_Decision_Engine_Guide">'
			. '<meta name="twitter:image:alt" content="IMG_2024.jpg">'
			. '<script type="application/ld+json">{"@type":"ImageObject","caption":"Zero-Hallucination_Decision_Engine_Guide"}</script>'
			. '</head><body>'
			. '<img src="a.jpg" alt="Zero-Hallucination_Decision_Engine_Guide" title="photo.webp">'
			. '<img src="b.jpg" alt="A cat on a sofa">'
			. '</body></html>';

		$this->assertSame( array( 'A cat on a sofa' ), $this->extract( $html, array() ) );
	}

	public function testNormalImageAltInMetaIsStillExtracted(): void {
		$html = '<!DOCTYPE html><html><head><meta property="og:image:alt" content="A cat on a sofa"></head><body></body></html>';

		$this->assertSame( array( 'A cat on a sofa' ), $this->extract( $html, array() ) );
	}
}
