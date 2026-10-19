<?php
/**
 * Тесты ручного показа записей на языках в списке записей: переключатели,
 * массовые действия, фильтр.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Admin\EditorLink;
use LingoCanvas\Admin\PostListLanguages;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Tests\Doubles\FakeWpdb;

/**
 * Главный запрос списка без БД: get()/set() над массивом переменных.
 */
final class FakeVisibilityListQuery extends \WP_Query {

	/** @var array<string, mixed> */
	public array $vars;

	/**
	 * @param array<string, mixed> $vars Переменные запроса.
	 */
	public function __construct( array $vars = array() ) {
		parent::__construct();

		$this->vars = $vars;
	}

	public function is_main_query(): bool {
		return true;
	}

	/** @param string $key Параметр. */
	public function get( $key, $default = '' ) {
		return $this->vars[ $key ] ?? $default;
	}

	/**
	 * @param string $key   Параметр.
	 * @param mixed  $value Значение.
	 */
	public function set( $key, $value ): void {
		$this->vars[ $key ] = $value;
	}
}

#[CoversClass( PostListLanguages::class )]
final class PostListVisibilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();
		unset( $GLOBALS['wp_query'], $GLOBALS['lingocanvas_test_meta_primed'] );
		$GLOBALS['pagenow'] = 'edit.php';
		$_GET               = array();
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_request( array( 'is_admin' => true ) );
		$this->configure( Settings::POSTS_MANUAL );
		$this->posts( array( 1, 2, 3 ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_query'], $GLOBALS['pagenow'], $GLOBALS['lingocanvas_test_meta_primed'] );
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
	 * @param list<int>             $ids  Записи списка (страницы, опубликованы).
	 * @param array<string, bool>   $caps Права.
	 */
	private function posts( array $ids, array $caps = array() ): void {
		$posts = array();

		foreach ( $ids as $id ) {
			$posts[ $id ] = new \WP_Post(
				array(
					'ID'          => $id,
					'post_status' => 'publish',
					'post_title'  => 'T' . $id,
					'post_name'   => 'p' . $id,
					'post_type'   => 'page',
				)
			);
		}

		lingocanvas_test_wp( array( 'posts' => $posts, 'caps' => $caps ) );
		$GLOBALS['wp_query'] = (object) array( 'posts' => array_values( $posts ) );
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	private function service( bool $withVisibility = true ): PostListLanguages {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );

		return new PostListLanguages( $settings, new EditorLink( $settings, $urls ), $withVisibility ? $this->visibility() : null );
	}

	private function cell( PostListLanguages $service, int $id ): string {
		ob_start();
		$service->renderColumn( PostListLanguages::COLUMN, $id );

		return (string) ob_get_clean();
	}

	// --- Переключатели в колонке ---------------------------------------------

	public function testTogglesRenderedInManualModeWithCorrectState(): void {
		$this->visibility()->set( 1, 'en', true );

		$html = $this->cell( $this->service(), 1 );

		$this->assertSame( 2, substr_count( $html, 'class="mlp-vis-toggle"' ) );
		$this->assertMatchesRegularExpression( '/data-post-id="1" data-locale="en" checked="checked"/', $html );
		$this->assertMatchesRegularExpression( '/data-post-id="1" data-locale="de">/', $html );
		$this->assertStringContainsString( 'on site', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	public function testTogglesHiddenInAllMode(): void {
		$this->configure( Settings::POSTS_ALL );

		$this->assertStringNotContainsString( 'mlp-vis-toggle', $this->cell( $this->service(), 1 ) );
	}

	public function testTogglesAbsentWithoutVisibilityService(): void {
		$this->assertStringNotContainsString( 'mlp-vis-toggle', $this->cell( $this->service( false ), 1 ) );
	}

	public function testToggleDisabledWithReasonWithoutEditRight(): void {
		$this->posts( array( 1, 2 ), array( 'edit_post:2' => false ) );

		$allowed = $this->cell( $this->service(), 1 );
		$denied  = $this->cell( $this->service(), 2 );

		$this->assertStringNotContainsString( 'disabled', $allowed );
		$this->assertSame( 2, substr_count( $denied, 'disabled="disabled"' ) );
		$this->assertStringContainsString( 'You do not have permission to edit this post', $denied );
	}

	public function testToggleDisabledWithoutPluginRight(): void {
		$this->posts( array( 1 ), array( 'manage_options' => false ) );

		// Колонка без права управлять плагином и так не выводится; сам переключатель при этом тоже закрыт.
		$method = new \ReflectionMethod( PostListLanguages::class, 'toggleHtml' );
		$html   = (string) $method->invoke(
			$this->service(),
			lingocanvas_test_wp()['posts'][1],
			( new Settings() )->secondary()['en']
		);

		$this->assertSame( 1, substr_count( $html, 'disabled="disabled"' ) );
		$this->assertStringContainsString( 'You do not have permission to edit this post', $html );
	}

	public function testBulkNeedsPluginRight(): void {
		$this->posts( array( 1 ), array( 'manage_options' => false ) );

		$base = 'https://example.test/wp-admin/edit.php';

		$this->assertSame( $base, $this->service()->handleBulkAction( $base, 'lingocanvas_show_en', array( 1 ) ) );
		$this->assertFalse( $this->visibility()->isVisible( 1, 'en' ) );
	}

	public function testStatesPrimedOnceForAllRows(): void {
		$service = $this->service();

		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->cell( $service, $id );
		}

		$this->assertCount( 1, $GLOBALS['lingocanvas_test_meta_primed'] );
		$this->assertSame( array( 'post', array( 1, 2, 3 ) ), $GLOBALS['lingocanvas_test_meta_primed'][0] );
	}

	// --- Массовые действия -----------------------------------------------------

	public function testBulkActionsListedPerSecondaryLanguageOnlyInManualMode(): void {
		$actions = $this->service()->addBulkActions( array( 'trash' => 'В корзину' ) );

		$this->assertSame(
			array( 'trash', 'lingocanvas_show_en', 'lingocanvas_hide_en', 'lingocanvas_show_de', 'lingocanvas_hide_de' ),
			array_keys( $actions )
		);
		$this->assertSame( 'Show on language: English', $actions['lingocanvas_show_en'] );
		$this->assertSame( 'Hide on language: Deutsch', $actions['lingocanvas_hide_de'] );

		$this->configure( Settings::POSTS_ALL );
		$this->assertSame( array( 'trash' => 'В корзину' ), $this->service()->addBulkActions( array( 'trash' => 'В корзину' ) ) );
	}

	public function testBulkShowSetsFlagsAndReportsCounts(): void {
		$url = $this->service()->handleBulkAction( 'https://example.test/wp-admin/edit.php?post_type=page', 'lingocanvas_show_en', array( 1, 2, 3 ) );

		$this->assertTrue( $this->visibility()->isVisible( 1, 'en' ) );
		$this->assertTrue( $this->visibility()->isVisible( 3, 'en' ) );
		$this->assertFalse( $this->visibility()->isVisible( 1, 'de' ) );
		$this->assertStringContainsString( 'lingocanvas_vis_done=3', $url );
		$this->assertStringContainsString( 'lingocanvas_vis_locale=en', $url );
		$this->assertStringContainsString( 'lingocanvas_vis_state=1', $url );
	}

	public function testBulkHideRemovesFlags(): void {
		$this->visibility()->setMany( array( 1, 2 ), 'de', true );

		$url = $this->service()->handleBulkAction( 'https://example.test/wp-admin/edit.php', 'lingocanvas_hide_de', array( 1, 2 ) );

		$this->assertFalse( $this->visibility()->isVisible( 1, 'de' ) );
		$this->assertStringContainsString( 'lingocanvas_vis_state=0', $url );
	}

	public function testBulkChecksEditRightPerPost(): void {
		$this->posts( array( 1, 2, 3 ), array( 'edit_post:2' => false ) );

		$url = $this->service()->handleBulkAction( 'https://example.test/wp-admin/edit.php', 'lingocanvas_show_en', array( 1, 2, 3 ) );

		$this->assertTrue( $this->visibility()->isVisible( 1, 'en' ) );
		$this->assertFalse( $this->visibility()->isVisible( 2, 'en' ), 'Чужую запись менять нельзя.' );
		$this->assertTrue( $this->visibility()->isVisible( 3, 'en' ) );
		$this->assertStringContainsString( 'lingocanvas_vis_done=2', $url );
		$this->assertStringContainsString( 'lingocanvas_vis_skipped=1', $url );
	}

	public function testBulkIgnoresDefaultAndUnknownLanguagesAndForeignActions(): void {
		$service = $this->service();
		$base    = 'https://example.test/wp-admin/edit.php';

		$this->assertSame( $base, $service->handleBulkAction( $base, 'lingocanvas_show_ru', array( 1 ) ), 'Язык по умолчанию.' );
		$this->assertSame( $base, $service->handleBulkAction( $base, 'lingocanvas_show_xx', array( 1 ) ), 'Неизвестный язык.' );
		$this->assertSame( $base, $service->handleBulkAction( $base, 'trash', array( 1 ) ), 'Чужое действие.' );
		$this->assertFalse( $this->visibility()->isVisible( 1, 'ru' ) );
	}

	public function testBulkDoesNothingInAllModeOrWithoutPluginRight(): void {
		$base = 'https://example.test/wp-admin/edit.php';

		$this->configure( Settings::POSTS_ALL );
		$this->assertSame( $base, $this->service()->handleBulkAction( $base, 'lingocanvas_show_en', array( 1 ) ) );

		$this->configure( Settings::POSTS_MANUAL );
		$this->posts( array( 1 ), array( 'manage_options' => false ) );
		$this->assertSame( $base, $this->service()->handleBulkAction( $base, 'lingocanvas_show_en', array( 1 ) ) );
		$this->assertFalse( $this->visibility()->isVisible( 1, 'en' ) );
	}

	public function testBulkNoticeShowsCountAndLanguage(): void {
		$_GET = array(
			PostListLanguages::NOTICE_COUNT  => '12',
			PostListLanguages::NOTICE_LOCALE => 'en',
			PostListLanguages::NOTICE_STATE  => '1',
		);

		ob_start();
		$this->service()->renderBulkNotice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Shown on EN: 12 posts', $html );

		$_GET[ PostListLanguages::NOTICE_COUNT ] = '1';
		$_GET[ PostListLanguages::NOTICE_STATE ] = '0';

		ob_start();
		$this->service()->renderBulkNotice();
		$this->assertStringContainsString( 'Hidden on EN: 1 post', (string) ob_get_clean() );

		$_GET[ PostListLanguages::NOTICE_LOCALE ] = 'xx';

		ob_start();
		$this->service()->renderBulkNotice();
		$this->assertSame( '', (string) ob_get_clean(), 'Неизвестный язык в адресе — без сообщения.' );
	}

	public function testRussianPlural(): void {
		$this->assertSame( 'запись', PostListLanguages::plural( 1, 'запись', 'записи', 'записей' ) );
		$this->assertSame( 'записи', PostListLanguages::plural( 3, 'запись', 'записи', 'записей' ) );
		$this->assertSame( 'записей', PostListLanguages::plural( 5, 'запись', 'записи', 'записей' ) );
		$this->assertSame( 'записей', PostListLanguages::plural( 11, 'запись', 'записи', 'записей' ) );
		$this->assertSame( 'запись', PostListLanguages::plural( 21, 'запись', 'записи', 'записей' ) );
		$this->assertSame( 'записей', PostListLanguages::plural( 0, 'запись', 'записи', 'записей' ) );
	}

	// --- Фильтр списка ---------------------------------------------------------

	public function testFilterDropdownAddsShownAndHiddenOptionsInManualMode(): void {
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'hidden:de';

		ob_start();
		$this->service()->renderFilter( 'page', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="hidden:en"', $html );
		$this->assertStringContainsString( 'value="shown:en"', $html );
		$this->assertStringContainsString( 'value="hidden:de" selected="selected"', $html );
		$this->assertStringContainsString( 'Hidden on Deutsch', $html );
		$this->assertStringContainsString( 'Shown on English', $html );
		// Прежний фильтр по переводу остаётся.
		$this->assertStringContainsString( 'value="en"', $html );

		$this->configure( Settings::POSTS_ALL );

		ob_start();
		$this->service()->renderFilter( 'page', 'top' );
		$this->assertStringNotContainsString( 'shown:', (string) ob_get_clean() );
	}

	public function testShownFilterAddsExistsMetaQuery(): void {
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'shown:en';
		$query                                   = new FakeVisibilityListQuery( array( 'post_type' => 'page' ) );

		$this->service()->filterByVisibility( $query );

		$this->assertSame( array( array( 'key' => '_lingocanvas_visible_en', 'compare' => 'EXISTS' ) ), $query->vars['meta_query'] );
	}

	public function testHiddenFilterAddsNotExistsMetaQuery(): void {
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'hidden:de';
		$query                                   = new FakeVisibilityListQuery( array( 'post_type' => 'page' ) );

		$this->service()->filterByVisibility( $query );

		$this->assertSame( array( array( 'key' => '_lingocanvas_visible_de', 'compare' => 'NOT EXISTS' ) ), $query->vars['meta_query'] );
	}

	public function testFilterKeepsForeignMetaQueryNested(): void {
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'shown:en';
		$foreign                                 = array( 'relation' => 'OR', array( 'key' => 'x', 'value' => '1' ), array( 'key' => 'y', 'value' => '2' ) );
		$query                                   = new FakeVisibilityListQuery( array( 'post_type' => 'page', 'meta_query' => $foreign ) );

		$this->service()->filterByVisibility( $query );

		$this->assertSame(
			array( 'relation' => 'AND', $foreign, array( 'key' => '_lingocanvas_visible_en', 'compare' => 'EXISTS' ) ),
			$query->vars['meta_query']
		);
	}

	public function testFilterIgnoresBadValuesAndOtherScreens(): void {
		$service = $this->service();

		foreach ( array( 'shown:xx', 'hidden:ru', "shown:en' OR 1=1", 'en', 'bogus' ) as $value ) {
			$_GET[ PostListLanguages::FILTER_PARAM ] = $value;
			$query                                   = new FakeVisibilityListQuery( array( 'post_type' => 'page' ) );

			$service->filterByVisibility( $query );

			$this->assertArrayNotHasKey( 'meta_query', $query->vars, $value );
		}

		$_GET[ PostListLanguages::FILTER_PARAM ] = 'shown:en';
		$GLOBALS['pagenow']                      = 'index.php';
		$query                                   = new FakeVisibilityListQuery( array( 'post_type' => 'page' ) );
		$service->filterByVisibility( $query );
		$this->assertArrayNotHasKey( 'meta_query', $query->vars );

		$GLOBALS['pagenow'] = 'edit.php';
		$this->configure( Settings::POSTS_ALL );
		$query = new FakeVisibilityListQuery( array( 'post_type' => 'page' ) );
		$this->service()->filterByVisibility( $query );
		$this->assertArrayNotHasKey( 'meta_query', $query->vars, 'В режиме «все» фильтра показа нет.' );
	}
}
