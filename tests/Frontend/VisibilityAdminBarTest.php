<?php
/**
 * Тесты плашки «Показано / Скрыто на DE» в админ-панели сайта.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Frontend\VisibilityAdminBar;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Tests\Doubles\FakeWpdb;

/**
 * Админ-панель, которая только запоминает добавленные узлы.
 */
final class FakeAdminBar {

	/** @var array<string, array<string, mixed>> */
	public array $nodes = array();

	/**
	 * @param array<string, mixed> $node Узел.
	 */
	public function add_node( array $node ): void {
		$this->nodes[ (string) $node['id'] ] = $node;
	}
}

#[CoversClass( VisibilityAdminBar::class )]
final class VisibilityAdminBarTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_request( array() );
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/en/about/';

		$this->configure( Settings::POSTS_MANUAL );
		$this->world();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REQUEST_URI'] );
		$_GET = array();
		lingocanvas_test_options( array() );
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_request( array() );
	}

	private function configure( string $mode ): void {
		lingocanvas_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::optionName() => array(
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
	 * @param array<string, mixed> $extra Дополнительные ключи хранилища (caps, admin_bar).
	 */
	private function world( array $extra = array() ): void {
		lingocanvas_test_wp(
			array_merge(
				array(
					'posts' => array(
						10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) ),
						12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment' ) ),
					),
				),
				$extra
			)
		);
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	private function bar( int $postId = 10 ): VisibilityAdminBar {
		$settings = new Settings();

		return new VisibilityAdminBar( $settings, new LanguageResolver( $settings ), $this->visibility(), static fn(): int => $postId );
	}

	private function nodes( VisibilityAdminBar $service ): FakeAdminBar {
		$bar = new FakeAdminBar();
		$service->addNodes( $bar );

		return $bar;
	}

	public function testHiddenPageShowsWarningAndShowButton(): void {
		$bar = $this->nodes( $this->bar() );

		$this->assertSame( 'Hidden on EN: visitors cannot see it', $bar->nodes[ VisibilityAdminBar::NODE_ID ]['title'] );
		$this->assertSame( 'mlp-vis-hidden', $bar->nodes[ VisibilityAdminBar::NODE_ID ]['meta']['class'] );
		$this->assertSame( 'Show on EN', $bar->nodes[ VisibilityAdminBar::TOGGLE_ID ]['title'] );
		$this->assertSame( VisibilityAdminBar::NODE_ID, $bar->nodes[ VisibilityAdminBar::TOGGLE_ID ]['parent'] );
	}

	public function testVisiblePageShowsShownAndHideButton(): void {
		$this->visibility()->set( 10, 'en', true );

		$bar = $this->nodes( $this->bar() );

		$this->assertSame( 'Shown on EN', $bar->nodes[ VisibilityAdminBar::NODE_ID ]['title'] );
		$this->assertSame( 'mlp-vis-shown', $bar->nodes[ VisibilityAdminBar::NODE_ID ]['meta']['class'] );
		$this->assertSame( 'Hide on EN', $bar->nodes[ VisibilityAdminBar::TOGGLE_ID ]['title'] );
	}

	public function testStateIsPerLanguage(): void {
		$this->visibility()->set( 10, 'de', true );

		// Открыт английский: включённый немецкий на плашку не влияет.
		$this->assertSame( array( 10, 'en', false ), $this->bar()->context() );

		$_SERVER['REQUEST_URI'] = '/de/about/';
		$this->assertSame( array( 10, 'de', true ), $this->bar()->context() );
	}

	public function testNothingOnDefaultLanguage(): void {
		$_SERVER['REQUEST_URI'] = '/about/';

		$this->assertNull( $this->bar()->context() );
		$this->assertSame( array(), $this->nodes( $this->bar() )->nodes );
	}

	public function testNothingInAllMode(): void {
		$this->configure( Settings::POSTS_ALL );

		$this->assertNull( $this->bar()->context() );
	}

	public function testNothingWithoutEditRightOrAdminBar(): void {
		$this->world( array( 'caps' => array( 'edit_post' => false ) ) );
		$this->assertNull( $this->bar()->context(), 'Нет права править запись.' );

		$this->world( array( 'admin_bar' => false ) );
		$this->assertNull( $this->bar()->context(), 'Админ-панель не показана.' );
	}

	public function testNothingWithoutPluginRight(): void {
		// Кнопка бьёт в REST, где нужны оба права: без manage_options её незачем показывать.
		$this->world( array( 'caps' => array( 'manage_options' => false ) ) );

		$this->assertNull( $this->bar()->context() );
		$this->assertSame( array(), $this->nodes( $this->bar() )->nodes );
	}

	public function testNothingForNonPostOrIneligibleType(): void {
		$this->assertNull( $this->bar( 0 )->context(), 'Не запись (архив, поиск).' );
		$this->assertNull( $this->bar( 12 )->context(), 'Вложение.' );
		$this->assertNull( $this->bar( 999 )->context(), 'Нет такой записи.' );
	}

	public function testNothingInAdminScreens(): void {
		lingocanvas_test_request( array( 'is_admin' => true ) );

		$this->assertNull( $this->bar()->context() );
	}
}
