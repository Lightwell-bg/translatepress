<?php
/**
 * Тесты проверки протокола адреса и её применения при сохранении и подстановке.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Segment;
use WpMlp\Support\UrlGuard;
use WpMlp\Translation\PostCommitValidator;

#[CoversClass( UrlGuard::class )]
#[CoversClass( Segment::class )]
#[CoversClass( PostCommitValidator::class )]
final class UrlGuardTest extends TestCase {

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function urls(): array {
		return array(
			'relative'          => array( '/blog/x/', true ),
			'relative no slash' => array( 'about/us', true ),
			'anchor'            => array( '#comments', true ),
			'query'             => array( '?page=2', true ),
			'https'             => array( 'https://example.com/a?b=1&c=2', true ),
			'protocol relative' => array( '//example.com/a', true ),
			'mailto'            => array( 'mailto:a@example.com', true ),
			'tel'               => array( 'tel:+359888123456', true ),
			'empty'             => array( '', true ),
			'colon in path'     => array( '/a:b/c', true ),
			'javascript'        => array( 'javascript:alert(1)', false ),
			'javascript case'   => array( 'JaVaScRiPt:alert(1)', false ),
			'javascript space'  => array( " java\tscript:alert(1)", false ),
			'javascript entity' => array( 'javascript&colon;alert(1)', false ),
			'javascript num'    => array( 'javascript&#58;alert(1)', false ),
			'data'              => array( 'data:text/html;base64,PHNjcmlwdD4=', false ),
			'vbscript'          => array( 'vbscript:msgbox(1)', false ),
		);
	}

	#[DataProvider( 'urls' )]
	public function testIsSafe( string $url, bool $expected ): void {
		$this->assertSame( $expected, UrlGuard::isSafe( $url ) );
	}

	public function testUrlAttributes(): void {
		$this->assertTrue( UrlGuard::isUrlAttribute( 'href' ) );
		$this->assertTrue( UrlGuard::isUrlAttribute( 'SRC' ) );
		$this->assertFalse( UrlGuard::isUrlAttribute( 'title' ) );
		$this->assertFalse( UrlGuard::isUrlAttribute( null ) );
	}

	public function testLooksLikeUrl(): void {
		$this->assertTrue( UrlGuard::looksLikeUrl( '/blog/x/' ) );
		$this->assertFalse( UrlGuard::looksLikeUrl( 'Read more' ) );
		$this->assertFalse( UrlGuard::looksLikeUrl( '' ) );
		$this->assertFalse( UrlGuard::looksLikeUrl( 'Company' ) );
		$this->assertTrue( UrlGuard::looksLikeUrl( 'https://x.test/a' ) );
	}

	/**
	 * Решение на сохранении: отклонять ли перевод (looksLikeUrl && !isSafe).
	 */
	private static function rejectedOnSave( string $source, string $translation ): bool {
		return UrlGuard::looksLikeUrl( $source ) && ! UrlGuard::isSafe( $translation );
	}

	public function testSaveCheckAcceptsAttributeTranslationWithColon(): void {
		$this->assertFalse( self::rejectedOnSave( 'Company', 'Company: Acme' ) );
	}

	public function testSaveCheckRejectsDangerousSchemes(): void {
		$this->assertTrue( self::rejectedOnSave( '/about/', 'javascript:alert(1)' ) );
		$this->assertTrue( self::rejectedOnSave( 'https://x.test/a', 'data:text/html,<script>alert(1)</script>' ) );
	}

	public function testSaveCheckLetsBareFilenameThroughButRenderBlocksIt(): void {
		// Документированное ограничение: на сохранении «about.html» не опознаётся как адрес,
		// но подстановка в href в Segment всё равно откажет.
		$this->assertFalse( self::rejectedOnSave( 'about.html', 'javascript:alert(1)' ) );

		$document = new DOMDocument();
		$document->loadHTML( '<a href="about.html">x</a>' );
		$link = $document->getElementsByTagName( 'a' )->item( 0 );
		$this->assertNotNull( $link );

		$segment = new Segment( $link, Segment::KIND_ATTRIBUTE, 'href', 'about.html', '', '', str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) );
		$segment->apply( 'javascript:alert(1)' );
		$this->assertSame( 'about.html', $link->getAttribute( 'href' ) );
	}

	public function testSegmentDoesNotWriteUnsafeHref(): void {
		$document = new DOMDocument();
		$document->loadHTML( '<a href="/orig/">x</a>' );
		$link = $document->getElementsByTagName( 'a' )->item( 0 );

		$this->assertNotNull( $link );

		$segment = new Segment( $link, Segment::KIND_ATTRIBUTE, 'href', '/orig/', '', '', str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) );

		$segment->apply( 'javascript:alert(1)' );
		$this->assertSame( '/orig/', $link->getAttribute( 'href' ) );

		$segment->apply( '/bg/orig/' );
		$this->assertSame( '/bg/orig/', $link->getAttribute( 'href' ) );
	}

	public function testSegmentStillWritesPlainTitle(): void {
		$document = new DOMDocument();
		$document->loadHTML( '<a href="/x/" title="Home">x</a>' );
		$link = $document->getElementsByTagName( 'a' )->item( 0 );

		$this->assertNotNull( $link );

		$segment = new Segment( $link, Segment::KIND_ATTRIBUTE, 'title', 'Home', '', '', str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) );

		$segment->apply( 'Note: start' );
		$this->assertSame( 'Note: start', $link->getAttribute( 'title' ) );
	}

	public function testValidatorRejectsUnsafeLinkTranslation(): void {
		$hash   = str_repeat( 'a', 64 );
		$known  = array( $hash => array( 'id' => 1, 'kind' => Segment::KIND_ATTRIBUTE, 'source_text' => '/blog/x/' ) );
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => $hash, 'translated_text' => 'javascript:alert(1)' ) ),
			$known,
			static fn( int $id ): bool => true
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_UNSAFE_URL . ':' . $hash ), $result['errors'] );
		$this->assertSame( array(), $result['rows'] );

		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => $hash, 'translated_text' => '/bg/blog/x/' ) ),
			$known,
			static fn( int $id ): bool => true
		);

		$this->assertTrue( $result['ok'] );
	}
}
