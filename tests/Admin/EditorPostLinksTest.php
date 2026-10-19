<?php
/**
 * Тесты ссылок на редактор в списках записей и метабоксе.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Admin\EditorLink;
use LingoCanvas\Admin\EditorPostLinks;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;

#[CoversClass( EditorPostLinks::class )]
final class EditorPostLinksTest extends TestCase {

	protected function setUp(): void {
		lingocanvas_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::optionName() => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => '<script>alert(1)</script>English', 'status' => 'published' ),
					),
				),
			)
		);
		lingocanvas_test_wp( array() );
	}

	protected function tearDown(): void {
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array() );
	}

	private function links(): EditorPostLinks {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );

		return new EditorPostLinks( $settings, new EditorLink( $settings, $urls ) );
	}

	private function post( string $status = 'publish' ): \WP_Post {
		$post = new \WP_Post(
			array(
				'ID'          => 4,
				'post_status' => $status,
				'post_title'  => 'x',
				'post_name'   => 'about',
				'post_type'   => 'page',
			)
		);

		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( 'posts' => array( 4 => $post ) ) ) );

		return $post;
	}

	public function testRowActionAddedForPublishedPost(): void {
		$actions = $this->links()->rowActions( array( 'edit' => '<a>e</a>' ), $this->post() );

		$this->assertArrayHasKey( 'lingocanvas_translate', $actions );
		$this->assertStringContainsString( 'lingocanvas_path=%2Fabout%2F', $actions['lingocanvas_translate'] );
		$this->assertStringContainsString( 'Translate', $actions['lingocanvas_translate'] );
	}

	public function testRowActionSkippedForDraft(): void {
		$actions = $this->links()->rowActions( array( 'edit' => '<a>e</a>' ), $this->post( 'draft' ) );

		$this->assertArrayNotHasKey( 'lingocanvas_translate', $actions );
	}

	public function testRowActionSkippedWithoutCapability(): void {
		$post = $this->post();
		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( 'caps' => array( 'manage_options' => false ) ) ) );

		$this->assertArrayNotHasKey( 'lingocanvas_translate', $this->links()->rowActions( array(), $post ) );
	}

	public function testMetaBoxEscapesLanguageLabel(): void {
		ob_start();
		$this->links()->renderMetaBox( $this->post() );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'lingocanvas_locale=en', $html );
	}

	public function testMetaBoxNotesUnpublishedPost(): void {
		ob_start();
		$this->links()->renderMetaBox( $this->post( 'draft' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Publish the post', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function testMetaBoxEmptyWithoutCapability(): void {
		$post = $this->post();
		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( 'caps' => array( 'manage_options' => false ) ) ) );

		ob_start();
		$this->links()->renderMetaBox( $post );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
