<?php
/**
 * Тесты блока «Показывать на сайте» в метабоксе записи и его сохранения.
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
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Storage\TranslationCache;
use WpMlp\Tests\Doubles\FakeWpdb;

#[CoversClass( EditorPostLinks::class )]
final class PostVisibilityMetaBoxTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
		$_POST           = array();
		wp_mlp_test_postmeta( array() );
		$this->configure( Settings::POSTS_MANUAL );
		$this->world();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_POST = array();
		wp_mlp_test_options( array() );
		wp_mlp_test_postmeta( array() );
		wp_mlp_test_wp( array() );
	}

	private function configure( string $mode ): void {
		wp_mlp_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::OPTION => array(
					'default_locale'          => 'ru',
					'untranslated_posts_mode' => $mode,
					'languages'               => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
						'de' => array( 'locale' => 'de', 'slug' => 'de', 'label' => 'Deutsch', 'status' => 'published' ),
					),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $extra Дополнительные ключи хранилища (caps, autosave, revision).
	 */
	private function world( array $extra = array() ): void {
		wp_mlp_test_wp(
			array_merge(
				array(
					'posts' => array(
						4 => new \WP_Post( array( 'ID' => 4, 'post_status' => 'publish', 'post_name' => 'about', 'post_type' => 'page' ) ),
						5 => new \WP_Post( array( 'ID' => 5, 'post_status' => 'draft', 'post_name' => 'soon', 'post_type' => 'page' ) ),
						6 => new \WP_Post( array( 'ID' => 6, 'post_status' => 'inherit', 'post_name' => 'img', 'post_type' => 'attachment' ) ),
					),
				),
				$extra
			)
		);
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	private function links( bool $withVisibility = true ): EditorPostLinks {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );

		return new EditorPostLinks( $settings, new EditorLink( $settings, $urls ), $withVisibility ? $this->visibility() : null );
	}

	/**
	 * @param array<string, string> $checked Отмеченные языки: код => '1'.
	 */
	private function submit( array $checked, bool $withNonce = true ): void {
		$_POST = array( EditorPostLinks::FIELD => $checked );

		if ( $withNonce ) {
			$_POST[ EditorPostLinks::NONCE_FIELD ] = wp_create_nonce( EditorPostLinks::NONCE_ACTION );
		}
	}

	private function box( int $id ): string {
		ob_start();
		$this->links()->renderMetaBox( wp_mlp_test_wp()['posts'][ $id ] );

		return (string) ob_get_clean();
	}

	// --- Разметка ------------------------------------------------------------

	public function testCheckboxesPerSecondaryLanguageWithState(): void {
		$this->visibility()->set( 4, 'de', true );

		$html = $this->box( 4 );

		$this->assertStringContainsString( 'Show on the site', $html );
		$this->assertStringContainsString( 'name="mlp_visible[en]" value="1">', $html );
		$this->assertStringContainsString( 'name="mlp_visible[de]" value="1" checked="checked">', $html );
		$this->assertStringNotContainsString( 'mlp_visible[ru]', $html );
		$this->assertStringContainsString( 'name="mlp_post_visibility_nonce"', $html );
	}

	public function testNoVisibilityBlockInAllModeOrWithoutService(): void {
		$this->configure( Settings::POSTS_ALL );
		$this->assertStringNotContainsString( 'mlp_visible', $this->box( 4 ) );

		$this->configure( Settings::POSTS_MANUAL );
		ob_start();
		$this->links( false )->renderMetaBox( wp_mlp_test_wp()['posts'][4] );
		$this->assertStringNotContainsString( 'mlp_visible', (string) ob_get_clean() );
	}

	public function testBlockAlsoShownForDraft(): void {
		$html = $this->box( 5 );

		$this->assertStringContainsString( 'Publish the post', $html );
		$this->assertStringContainsString( 'name="mlp_visible[en]"', $html );
	}

	public function testBlockHiddenWithoutEditRight(): void {
		$this->world( array( 'caps' => array( 'edit_post' => false ) ) );

		$this->assertStringNotContainsString( 'mlp_visible', $this->box( 4 ) );
	}

	public function testBlockHiddenWithoutPluginRight(): void {
		$this->world( array( 'caps' => array( 'manage_options' => false ) ) );

		$this->assertStringNotContainsString( 'mlp_visible', $this->box( 4 ) );

		// Блок видимости сам тоже проверяет оба права, не полагаясь на метабокс.
		$method = new \ReflectionMethod( EditorPostLinks::class, 'renderVisibility' );

		ob_start();
		$method->invoke( $this->links(), wp_mlp_test_wp()['posts'][4] );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	// --- Сохранение ----------------------------------------------------------

	public function testSaveSetsCheckedAndClearsUnchecked(): void {
		$this->visibility()->set( 4, 'de', true );
		$this->submit( array( 'en' => '1' ) );

		$this->links()->saveVisibility( 4 );

		$this->assertTrue( $this->visibility()->isVisible( 4, 'en' ) );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'de' ), 'Снятая галочка не приходит — язык скрывается.' );
	}

	public function testSaveRejectsMissingOrBadNonce(): void {
		$this->submit( array( 'en' => '1' ), false );
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ), 'Без nonce (быстрое редактирование, REST).' );

		$this->submit( array( 'en' => '1' ) );
		$_POST[ EditorPostLinks::NONCE_FIELD ] = 'forged';
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ) );
	}

	public function testSaveRequiresEditRightAndPluginRight(): void {
		$this->submit( array( 'en' => '1' ) );

		$this->world( array( 'caps' => array( 'edit_post' => false ) ) );
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ) );

		$this->world( array( 'caps' => array( 'manage_options' => false ) ) );
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ) );
	}

	public function testSaveSkipsAutosaveAndRevision(): void {
		$this->submit( array( 'en' => '1' ) );

		$this->world( array( 'autosave' => array( 4 => true ) ) );
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ) );

		$this->world( array( 'revision' => array( 4 => true ) ) );
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ) );
	}

	public function testSaveSkipsIneligibleTypeAndAllMode(): void {
		$this->submit( array( 'en' => '1' ) );
		$this->links()->saveVisibility( 6 );
		$this->assertFalse( $this->visibility()->isVisible( 6, 'en' ), 'Вложение.' );

		$this->configure( Settings::POSTS_ALL );
		$this->links()->saveVisibility( 4 );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'en' ), 'Режим «все».' );
	}

	public function testSaveIgnoresUnknownAndDefaultLanguages(): void {
		$this->submit( array( 'ru' => '1', 'xx' => '1', 'de' => '1' ) );

		$this->links()->saveVisibility( 4 );

		$this->assertFalse( $this->visibility()->isVisible( 4, 'ru' ) );
		$this->assertFalse( $this->visibility()->isVisible( 4, 'xx' ) );
		$this->assertTrue( $this->visibility()->isVisible( 4, 'de' ) );
	}
}
