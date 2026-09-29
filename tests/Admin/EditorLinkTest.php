<?php
/**
 * Тесты общего помощника ссылок на визуальный редактор.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\EditorLink;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

#[CoversClass( EditorLink::class )]
final class EditorLinkTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
					),
				),
			)
		);
		wp_mlp_test_wp( array() );
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array() );
	}

	private function link(): EditorLink {
		$settings = new Settings();

		return new EditorLink( $settings, new UrlConverter( $settings, new LanguageResolver( $settings ) ) );
	}

	private function post( int $id, string $status = 'publish', string $type = 'page', string $name = 'about' ): \WP_Post {
		$post = new \WP_Post(
			array(
				'ID'          => $id,
				'post_status' => $status,
				'post_type'   => $type,
				'post_name'   => $name,
			)
		);

		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'posts' => ( wp_mlp_test_wp()['posts'] ?? array() ) + array( $id => $post ) ) ) );

		return $post;
	}

	public function testPublishedPostGivesRelativePath(): void {
		$this->assertSame( '/about/', $this->link()->pathForPost( $this->post( 1 ) ) );
	}

	public function testPathAcceptsPostId(): void {
		$this->post( 2, 'publish', 'post', 'news' );

		$this->assertSame( '/news/', $this->link()->pathForPost( 2 ) );
	}

	public function testFrontPageIsRoot(): void {
		$this->post( 3, 'publish', 'page', '' );

		$this->assertSame( '/', $this->link()->pathForPost( 3 ) );
	}

	public function testUnpublishedStatusesAreRejected(): void {
		foreach ( array( 'draft', 'pending', 'private', 'future', 'trash' ) as $i => $status ) {
			$this->assertNull( $this->link()->pathForPost( $this->post( 10 + $i, $status ) ), $status );
		}
	}

	public function testAttachmentAndNonPublicTypeAreRejected(): void {
		$this->assertNull( $this->link()->pathForPost( $this->post( 20, 'publish', 'attachment' ) ) );
		$this->assertNull( $this->link()->pathForPost( $this->post( 21, 'publish', 'secret' ) ) );
	}

	public function testUnknownPostIsRejected(): void {
		$this->assertNull( $this->link()->pathForPost( 999 ) );
	}

	public function testUnresolvablePermalinkIsRejected(): void {
		$post = $this->post( 30 );
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'permalinks' => array( 30 => '' ) ) ) );

		$this->assertNull( $this->link()->pathForPost( $post ) );
	}

	public function testUrlForPostDefaultsToFirstSecondaryLanguage(): void {
		$url = $this->link()->urlForPost( $this->post( 1 ) );

		$this->assertSame(
			'https://example.test/wp-admin/admin.php?page=wp-mlp-editor&mlp_locale=en&mlp_path=%2Fabout%2F',
			$url
		);
	}

	public function testUrlForPostWithExplicitLocale(): void {
		$url = $this->link()->urlForPost( $this->post( 1 ), 'de' );

		$this->assertStringContainsString( 'mlp_locale=de', (string) $url );
	}

	public function testUrlForDraftIsNull(): void {
		$this->assertNull( $this->link()->urlForPost( $this->post( 5, 'draft' ) ) );
	}

	public function testNormalizeInputHandlesPathsAndUrls(): void {
		$link = $this->link();

		$this->assertSame( '/about/', $link->normalizeInput( '/en/about/' ) );
		$this->assertSame( '/about/', $link->normalizeInput( '/about/' ) );
		$this->assertSame( '/about/', $link->normalizeInput( 'https://example.test/en/about/' ) );
		$this->assertSame( '/', $link->normalizeInput( 'https://other.test/about/' ) );
		$this->assertSame( '/', $link->normalizeInput( '/' ) );
	}

	public function testNormalizeInputStripsSubdirectoryBaseBeforeLanguage(): void {
		wp_mlp_test_options( array_merge( wp_mlp_test_options(), array( 'home' => 'https://example.test/blog' ) ) );
		$link = $this->link();

		$this->assertSame( '/about/', $link->normalizeInput( '/blog/en/about/' ) );
		$this->assertSame( '/', $link->normalizeInput( '/blog/' ) );
		$this->assertSame( '/', $link->normalizeInput( '/blog' ) );
		$this->assertSame( '/blog-post/', $link->normalizeInput( '/blog-post/' ) );
		$this->assertSame( '/about/', $link->normalizeInput( 'https://example.test/blog/en/about/' ) );
	}
}
