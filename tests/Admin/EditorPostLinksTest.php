<?php
/**
 * Тесты ссылок на редактор в списках записей и метабоксе.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\EditorLink;
use WpMlp\Admin\EditorPostLinks;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

#[CoversClass( EditorPostLinks::class )]
final class EditorPostLinksTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => '<script>alert(1)</script>English', 'status' => 'published' ),
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

		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'posts' => array( 4 => $post ) ) ) );

		return $post;
	}

	public function testRowActionAddedForPublishedPost(): void {
		$actions = $this->links()->rowActions( array( 'edit' => '<a>e</a>' ), $this->post() );

		$this->assertArrayHasKey( 'mlp_translate', $actions );
		$this->assertStringContainsString( 'mlp_path=%2Fabout%2F', $actions['mlp_translate'] );
		$this->assertStringContainsString( 'Перевести', $actions['mlp_translate'] );
	}

	public function testRowActionSkippedForDraft(): void {
		$actions = $this->links()->rowActions( array( 'edit' => '<a>e</a>' ), $this->post( 'draft' ) );

		$this->assertArrayNotHasKey( 'mlp_translate', $actions );
	}

	public function testRowActionSkippedWithoutCapability(): void {
		$post = $this->post();
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array( 'manage_options' => false ) ) ) );

		$this->assertArrayNotHasKey( 'mlp_translate', $this->links()->rowActions( array(), $post ) );
	}

	public function testMetaBoxEscapesLanguageLabel(): void {
		ob_start();
		$this->links()->renderMetaBox( $this->post() );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'mlp_locale=en', $html );
	}

	public function testMetaBoxNotesUnpublishedPost(): void {
		ob_start();
		$this->links()->renderMetaBox( $this->post( 'draft' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Опубликуйте запись', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function testMetaBoxEmptyWithoutCapability(): void {
		$post = $this->post();
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array( 'manage_options' => false ) ) ) );

		ob_start();
		$this->links()->renderMetaBox( $post );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
