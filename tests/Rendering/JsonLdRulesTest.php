<?php
/**
 * Тесты правил перевода структурированных данных.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\JsonLdRules;

#[CoversClass( JsonLdRules::class )]
final class JsonLdRulesTest extends TestCase {

	public function testWebPageUrlIsLocalisable(): void {
		$this->assertTrue( JsonLdRules::isUrl( 'url', 'WebPage' ) );
		$this->assertTrue( JsonLdRules::isUrl( 'url', 'Article' ) );
	}

	/**
	 * @param string $mediaType Тип объекта, у которого `url` — адрес файла.
	 */
	#[DataProvider( 'mediaTypes' )]
	public function testMediaObjectUrlIsNotLocalisable( string $mediaType ): void {
		$this->assertFalse( JsonLdRules::isUrl( 'url', $mediaType ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function mediaTypes(): array {
		return array(
			array( 'ImageObject' ),
			array( 'imageobject' ),
			array( 'VideoObject' ),
			array( 'AudioObject' ),
			array( 'ImageGallery' ),
		);
	}

	public function testOnlyTheUrlKeyMatches(): void {
		$this->assertFalse( JsonLdRules::isUrl( 'contentUrl', 'WebPage' ) );
		$this->assertFalse( JsonLdRules::isUrl( '@id', 'WebPage' ) );
	}

	#[DataProvider( 'mediaUrls' )]
	public function testLooksLikeMediaFile( string $url ): void {
		$this->assertTrue( JsonLdRules::looksLikeMediaFile( $url ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function mediaUrls(): array {
		return array(
			array( 'https://site.ru/wp-content/uploads/2026/photo.jpg' ),
			array( 'https://site.ru/a/b/c/image.PNG' ),
			array( 'https://cdn.example.com/video.mp4' ),
			array( 'https://site.ru/file.pdf' ),
		);
	}

	public function testPageUrlDoesNotLookLikeMediaFile(): void {
		$this->assertFalse( JsonLdRules::looksLikeMediaFile( 'https://site.ru/about/' ) );
		$this->assertFalse( JsonLdRules::looksLikeMediaFile( 'https://site.ru/en/about/' ) );
	}

	public function testAtIdIsNeverTranslatableRegardlessOfType(): void {
		$this->assertFalse( JsonLdRules::isTranslatable( '@id', 'Organization' ) );
		$this->assertFalse( JsonLdRules::isTranslatable( '@id', 'WebPage' ) );
		$this->assertFalse( JsonLdRules::isTranslatable( '@id', 'Article' ) );
	}

	/**
	 * @param string $type Тип сущности реального мира — @id одинаков на всех языках.
	 */
	#[DataProvider( 'stableEntityTypes' )]
	public function testAtIdIsAStableIdOnRealWorldEntities( string $type ): void {
		$this->assertTrue( JsonLdRules::isStableId( '@id', $type ) );
		$this->assertFalse( JsonLdRules::isPageScopedId( '@id', $type ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function stableEntityTypes(): array {
		return array(
			array( 'Organization' ),
			array( 'organization' ),
			array( 'Person' ),
			array( 'WebSite' ),
		);
	}

	/**
	 * @param string $type Тип конкретной языковой версии страницы — @id локализуется как url.
	 */
	#[DataProvider( 'pageScopedTypes' )]
	public function testAtIdIsPageScopedOnPageEntities( string $type ): void {
		$this->assertTrue( JsonLdRules::isPageScopedId( '@id', $type ) );
		$this->assertFalse( JsonLdRules::isStableId( '@id', $type ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function pageScopedTypes(): array {
		return array(
			array( 'WebPage' ),
			array( 'Article' ),
			array( 'BlogPosting' ),
			array( 'NewsArticle' ),
			array( 'BreadcrumbList' ),
		);
	}

	public function testAtIdOnUnknownTypeIsNeitherStableNorPageScoped(): void {
		$this->assertFalse( JsonLdRules::isStableId( '@id', 'ImageObject' ) );
		$this->assertFalse( JsonLdRules::isPageScopedId( '@id', 'ImageObject' ) );
	}

	public function testOnlyAtIdKeyMatches(): void {
		$this->assertFalse( JsonLdRules::isStableId( 'url', 'Organization' ) );
		$this->assertFalse( JsonLdRules::isStableId( 'id', 'Organization' ) );
		$this->assertFalse( JsonLdRules::isPageScopedId( 'url', 'WebPage' ) );
		$this->assertFalse( JsonLdRules::isPageScopedId( 'mainEntityOfPage', 'WebPage' ) );
	}
}
