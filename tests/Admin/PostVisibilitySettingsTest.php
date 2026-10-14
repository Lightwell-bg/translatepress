<?php
/**
 * Тесты экрана настроек (режим показа записей, счётчики по языкам) и
 * переключателя показа в визуальном редакторе.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\EditorPage;
use WpMlp\Admin\SettingsPage;
use WpMlp\I18n\LanguagePacks;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Storage\TranslationCache;
use WpMlp\Tests\Doubles\FakeWpdb;
use WpMlp\Translation\ProviderFactory;

#[CoversClass( SettingsPage::class )]
#[CoversClass( EditorPage::class )]
final class PostVisibilitySettingsTest extends TestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		wp_mlp_test_postmeta( array() );
		$this->configure( Settings::POSTS_MANUAL );
		wp_mlp_test_wp(
			array(
				'posts' => array(
					10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about' ) ),
					12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment' ) ),
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
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
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => '<b>English</b>', 'status' => 'published' ),
					),
				),
			)
		);
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	private function settingsPage( bool $withVisibility = true ): SettingsPage {
		$settings = new Settings();

		return new SettingsPage( $settings, new LanguagePacks(), new ProviderFactory( $settings ), $withVisibility ? $this->visibility() : null );
	}

	private function counts( SettingsPage $page ): string {
		$method = new \ReflectionMethod( $page, 'renderVisibilityCounts' );

		ob_start();
		$method->invoke( $page );

		return (string) ob_get_clean();
	}

	private function editorBlock( int $postId, bool $withServices = true ): string {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );
		$page     = new EditorPage(
			$settings,
			$urls,
			new ProviderFactory( $settings ),
			$withServices ? $this->visibility() : null,
			$withServices ? new OccurrenceRepository() : null
		);
		$method   = new \ReflectionMethod( $page, 'visibilityBlock' );

		return (string) $method->invoke( $page, $postId, $settings->secondary()['en'] );
	}

	// --- Настройки ---------------------------------------------------------------

	public function testModeIsRadioWithPlainRussianLabels(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php' );

		$this->assertSame( 2, substr_count( $source, 'type="radio" name="untranslated_posts_mode"' ) );
		$this->assertStringContainsString( 'Show all posts (translated or not)', $source );
		$this->assertStringContainsString( 'In each language, show only posts enabled manually (recommended)', $source );
		$this->assertStringNotContainsString( '<select name="untranslated_posts_mode"', $source );
	}

	public function testCountsShowNumberButtonAndEscapedLanguageName(): void {
		$this->db->var = '12';

		$html = $this->counts( $this->settingsPage() );

		$this->assertStringContainsString( 'Currently shown in &lt;b&gt;English&lt;/b&gt; — posts and pages:', $html );
		$this->assertStringNotContainsString( '<b>English', $html );
		$this->assertStringContainsString( 'class="mlp-vis-count">12</strong>', $html );
		$this->assertStringContainsString( 'data-count="12"', $html );
		$this->assertStringContainsString( 'Enable all posts fully translated into &lt;b&gt;English&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'data-locale="en"', $html );
		$this->assertStringNotContainsString( 'data-locale="ru"', $html );
	}

	public function testEmptyLanguageWarningIsPresentButHidden(): void {
		$this->db->var = '0';

		$html = $this->counts( $this->settingsPage() );

		$this->assertStringContainsString( 'data-count="0"', $html );
		$this->assertMatchesRegularExpression( '/class="notice notice-warning inline mlp-vis-warning" data-locale="en" hidden="hidden">No posts are enabled for &lt;b&gt;English&lt;\/b&gt; — the language will be empty after saving\./u', $html );
	}

	public function testNoCountsWithoutVisibilityService(): void {
		$this->assertSame( '', $this->counts( $this->settingsPage( false ) ) );
	}

	public function testCountQueryRunsOncePerSecondaryLanguage(): void {
		$this->counts( $this->settingsPage() );

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( "p.post_status = 'publish'", $this->db->queries[0] );
	}

	// --- Визуальный редактор -------------------------------------------------------

	public function testEditorSwitchShowsStateAndProgress(): void {
		$this->visibility()->set( 10, 'en', true );
		$this->db->row = array( 'total' => 10, 'done' => 6 );

		$html = $this->editorBlock( 10 );

		$this->assertStringContainsString( 'Show this page in &lt;b&gt;English&lt;/b&gt;', $html );
		$this->assertMatchesRegularExpression( '/data-post-id="10" data-locale="en" checked="checked"/', $html );
		$this->assertStringContainsString( '60% translated', $html );
	}

	public function testEditorSwitchUncheckedWhenHiddenAndShowsNoStringsNote(): void {
		$this->db->row = array( 'total' => 0, 'done' => 0 );

		$html = $this->editorBlock( 10 );

		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringContainsString( 'This page has no strings yet', $html );
	}

	public function testEditorSwitchAbsentInAllModeNonPostAttachmentOrWithoutRight(): void {
		$this->assertSame( '', $this->editorBlock( 0 ), 'Не запись.' );
		$this->assertSame( '', $this->editorBlock( 12 ), 'Вложение.' );
		$this->assertSame( '', $this->editorBlock( 10, false ), 'Нет сервисов.' );

		$this->configure( Settings::POSTS_ALL );
		$this->assertSame( '', $this->editorBlock( 10 ), 'Режим «все».' );

		$this->configure( Settings::POSTS_MANUAL );
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array( 'edit_post' => false ) ) ) );
		$this->assertSame( '', $this->editorBlock( 10 ), 'Нет права править запись.' );

		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array( 'manage_options' => false ) ) ) );
		$this->assertSame( '', $this->editorBlock( 10 ), 'Нет права управлять плагином.' );
	}

	public function testCountsAndBulkButtonNeedManageOptions(): void {
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array( 'manage_options' => false ) ) ) );

		$this->assertSame( '', $this->counts( $this->settingsPage() ) );
	}
}
