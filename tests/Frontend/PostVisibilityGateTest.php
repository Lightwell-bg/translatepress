<?php
/**
 * Тесты ручного показа записей на дополнительных языках (фронтенд).
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\PostVisibilityGate;
use WpMlp\Rendering\EditorContext;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Storage\TranslationCache;
use WpMlp\Tests\Doubles\FakeWpdb;

/**
 * Запрос без БД: достаточно get()/set() и двух условных тегов.
 */
final class FakeGateQuery extends \WP_Query {

	/** @var array<string, mixed> */
	public array $vars;

	/**
	 * @param array<string, mixed> $vars     Переменные запроса.
	 * @param bool                 $main     Главный ли запрос.
	 * @param bool                 $singular Выбирает ли он одну запись.
	 */
	public function __construct( array $vars = array(), private bool $main = false, private bool $singular = false ) {
		parent::__construct();

		$this->vars = $vars;
	}

	public function is_main_query(): bool {
		return $this->main;
	}

	public function is_singular(): bool {
		return $this->singular;
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

/**
 * Запрос комментариев без БД: фильтру нужны только его переменные.
 */
final class FakeCommentQuery {

	/**
	 * @param array<string, mixed> $query_vars Переменные запроса.
	 */
	public function __construct( public array $query_vars = array() ) {
	}
}

#[CoversClass( PostVisibilityGate::class )]
final class PostVisibilityGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeWpdb();

		wp_mlp_test_postmeta( array() );
		wp_mlp_test_request( array() );
		unset( $GLOBALS['wp_mlp_test_meta_primed'] );
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/en/about/';

		$this->configure( Settings::POSTS_MANUAL );

		wp_mlp_test_wp(
			array(
				// Посетитель: права править записи у него нет, поэтому скрытая запись уводит на русскую версию.
				'caps'  => array( 'edit_post' => false ),
				'posts' => array(
					10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) ),
					11 => new \WP_Post( array( 'ID' => 11, 'post_type' => 'post' ) ),
					12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment' ) ),
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_mlp_test_meta_primed'], $_SERVER['REQUEST_URI'] );
		$_GET = array();
		wp_mlp_test_options( array() );
		wp_mlp_test_postmeta( array() );
		wp_mlp_test_wp( array() );
		wp_mlp_test_request( array() );
		wp_mlp_test_filter( 'mlp_visibility_bypass', null );
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

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	private function gate(): PostVisibilityGate {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );

		return new PostVisibilityGate(
			$settings,
			$resolver,
			$this->visibility(),
			new EditorContext( $resolver ),
			new UrlConverter( $settings, $resolver )
		);
	}

	private function queryMeta( FakeGateQuery $query ): mixed {
		return $query->vars['meta_query'] ?? null;
	}

	// --- Списки (pre_get_posts) ------------------------------------------

	public function testQueryGetsExistsClauseOnSecondaryLanguageInManualMode(): void {
		$query = new FakeGateQuery( array( 'post_type' => 'post' ), true );

		$this->gate()->filterQuery( $query );

		$this->assertSame(
			array( PostVisibilityGate::CLAUSE_KEY => array( 'key' => '_mlp_visible_en', 'compare' => 'EXISTS' ) ),
			$this->queryMeta( $query )
		);
	}

	public function testSecondaryQueryOfWidgetsIsFilteredToo(): void {
		$query = new FakeGateQuery( array( 'post_type' => array( 'post', 'page' ), 'suppress_filters' => true ), false );

		$this->gate()->filterQuery( $query );

		$this->assertSame( '_mlp_visible_en', $this->queryMeta( $query )[ PostVisibilityGate::CLAUSE_KEY ]['key'] );
	}

	public function testEmptyAndAnyPostTypeAreFiltered(): void {
		foreach ( array( array(), array( 'post_type' => '' ), array( 'post_type' => 'any' ) ) as $vars ) {
			$query = new FakeGateQuery( $vars );
			$this->gate()->filterQuery( $query );

			$this->assertNotNull( $this->queryMeta( $query ) );
		}
	}

	public function testExistingMetaQueryIsKeptAndCombinedWithAnd(): void {
		$existing = array(
			'relation' => 'OR',
			array( 'key' => 'color', 'value' => 'red' ),
			array( 'key' => 'size', 'value' => 'xl' ),
		);
		$query    = new FakeGateQuery( array( 'post_type' => 'post', 'meta_query' => $existing ) );

		$this->gate()->filterQuery( $query );

		$meta = $this->queryMeta( $query );

		$this->assertSame( 'AND', $meta['relation'] );
		$this->assertSame( $existing, $meta[0], 'Чужой запрос вложен целиком, его OR не потерян.' );
		$this->assertSame( 'EXISTS', $meta[ PostVisibilityGate::CLAUSE_KEY ]['compare'] );
	}

	public function testClauseIsAddedOnlyOncePerQuery(): void {
		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );
		$gate  = $this->gate();

		$gate->filterQuery( $query );
		$first = $this->queryMeta( $query );
		$gate->filterQuery( $query );

		$this->assertSame( $first, $this->queryMeta( $query ) );
	}

	public function testMainSingularQueryIsLeftToTheRedirect(): void {
		$query = new FakeGateQuery( array( 'post_type' => 'page' ), true, true );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
	}

	public function testSecondarySingularLookupIsStillFiltered(): void {
		// Не главный запрос (например, блок «похожее» с p=ID): скрытая запись не должна просочиться.
		$query = new FakeGateQuery( array( 'p' => 10, 'post_type' => 'page' ), false, true );

		$this->gate()->filterQuery( $query );

		$this->assertNotNull( $this->queryMeta( $query ) );
	}

	public function testNonPublicAttachmentAndMixedTypesAreSkipped(): void {
		foreach ( array( 'secret', 'attachment', 'nav_menu_item', array( 'post', 'attachment' ), array( 'post', 'secret' ) ) as $type ) {
			$query = new FakeGateQuery( array( 'post_type' => $type ) );
			$this->gate()->filterQuery( $query );

			$this->assertNull( $this->queryMeta( $query ), 'Тип ' . wp_json_encode( $type ) . ' не должен затрагиваться.' );
		}
	}

	public function testInternalQueryMarkedToBeIgnoredIsSkipped(): void {
		$query = new FakeGateQuery( array( 'post_type' => 'post', PostVisibilityGate::SKIP_QUERY_VAR => true ) );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
	}

	public function testNothingHappensInModeAll(): void {
		$this->configure( Settings::POSTS_ALL );
		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	public function testNothingHappensOnDefaultLanguage(): void {
		$_SERVER['REQUEST_URI'] = '/about/';
		$query                 = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	public function testNothingHappensInAdminAjaxOrCron(): void {
		foreach ( array( 'is_admin', 'ajax', 'cron' ) as $context ) {
			wp_mlp_test_request( array( $context => true ) );
			$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

			$this->gate()->filterQuery( $query );

			$this->assertNull( $this->queryMeta( $query ), $context );
		}
	}

	public function testNothingHappensBeforeInit(): void {
		wp_mlp_test_wp( array( 'did_action' => array( 'init' => 0 ) ) + wp_mlp_test_wp() );
		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
	}

	public function testEditorPreviewIsNotRestricted(): void {
		$_GET[ EditorContext::QUERY_FLAG ]  = '1';
		$_GET[ EditorContext::QUERY_NONCE ] = wp_create_nonce( EditorContext::NONCE_ACTION );

		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	public function testBadEditorNonceDoesNotBypass(): void {
		$_GET[ EditorContext::QUERY_FLAG ]  = '1';
		$_GET[ EditorContext::QUERY_NONCE ] = 'forged';

		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		$this->assertNotNull( $this->queryMeta( $query ) );
	}

	public function testBypassFilterTurnsRestrictionOff(): void {
		wp_mlp_test_filter( 'mlp_visibility_bypass', static fn(): bool => true );
		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		$this->assertNull( $this->queryMeta( $query ) );
	}

	// --- Редирект одиночной записи ---------------------------------------

	public function testHiddenPostRedirectsToSamePathOnDefaultLanguage(): void {
		$this->assertSame( 'https://example.test/about/', $this->gate()->redirectTarget( 10 ) );
	}

	public function testVisiblePostDoesNotRedirect(): void {
		$this->visibility()->set( 10, 'en', true );

		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	public function testVisibilityIsPerLanguage(): void {
		// Включена на немецком — на английском по-прежнему скрыта.
		$this->visibility()->set( 10, 'de', true );

		$this->assertSame( 'https://example.test/about/', $this->gate()->redirectTarget( 10 ) );

		$_SERVER['REQUEST_URI'] = '/de/about/';
		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	public function testStaticFrontPageRedirectsToDefaultHome(): void {
		$_SERVER['REQUEST_URI'] = '/en/';

		$this->assertSame( 'https://example.test/', $this->gate()->redirectTarget( 10 ) );
	}

	public function testNoRedirectForNonPostsAndIneligibleTypes(): void {
		$gate = $this->gate();

		$this->assertNull( $gate->redirectTarget( 0 ), 'Не запись (архив, поиск).' );
		$this->assertNull( $gate->redirectTarget( 12 ), 'Вложение.' );
		$this->assertNull( $gate->redirectTarget( 999 ), 'Нет такой записи.' );
	}

	public function testRedirectTargetCannotLoop(): void {
		// Цель — язык по умолчанию, на нём ограничение не действует вовсе.
		$target = $this->gate()->redirectTarget( 10 );

		$this->assertNotNull( $target );
		$this->assertStringNotContainsString( '/en/', $target );

		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	// --- Обход редиректа для редактора записи --------------------------------

	private function asEditor( bool $adminBar = true ): void {
		$store = wp_mlp_test_wp();

		$store['caps']      = array( 'edit_post' => true );
		$store['admin_bar'] = $adminBar;

		wp_mlp_test_wp( $store );
	}

	public function testEditorOfHiddenPostIsNotRedirected(): void {
		$this->asEditor();

		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
	}

	public function testEditorRightIsPerPost(): void {
		$store                      = wp_mlp_test_wp();
		$store['caps']              = array( 'edit_post' => false, 'edit_post:10' => true );
		$store['admin_bar']         = true;
		wp_mlp_test_wp( $store );

		$this->assertNull( $this->gate()->redirectTarget( 10 ) );
		$this->assertSame( 'https://example.test/about/', $this->gate()->redirectTarget( 11 ) );
	}

	public function testEditorWithoutAdminBarIsStillRedirected(): void {
		// Без плашки в админ-панели скрытую страницу легко принять за опубликованную.
		$this->asEditor( false );

		$this->assertSame( 'https://example.test/about/', $this->gate()->redirectTarget( 10 ) );
	}

	public function testVisitorIsStillRedirectedWhileEditorBypassExists(): void {
		$this->assertSame( 'https://example.test/about/', $this->gate()->redirectTarget( 10 ) );
	}

	public function testEditorStillGetsFilteredLists(): void {
		$this->asEditor();

		$query = new FakeGateQuery( array( 'post_type' => 'post' ) );

		$this->gate()->filterQuery( $query );

		// Обход касается только страницы самой записи: списки и меню фильтруются как у посетителя.
		$this->assertSame(
			array( PostVisibilityGate::CLAUSE_KEY => array( 'key' => '_mlp_visible_en', 'compare' => 'EXISTS' ) ),
			$this->queryMeta( $query )
		);
		$this->assertTrue( $this->gate()->isEnforced() );
	}

	// --- Меню -------------------------------------------------------------

	/**
	 * @return object Пункт меню WordPress — только поля, которые читает фильтр.
	 */
	private function menuItem( int $dbId, int $objectId, string $type = 'post_type', string $object = 'page', int $parent = 0 ): object {
		return (object) array(
			'ID'               => $dbId,
			'db_id'            => $dbId,
			'type'             => $type,
			'object'           => $object,
			'object_id'        => (string) $objectId,
			'menu_item_parent' => (string) $parent,
		);
	}

	/**
	 * @param array<int, object> $items Пункты меню.
	 * @return list<int> db_id оставшихся пунктов.
	 */
	private function remainingMenu( array $items ): array {
		return array_map( static fn( object $item ): int => (int) $item->db_id, $this->gate()->filterMenuItems( $items ) );
	}

	public function testMenuDropsHiddenPostsKeepsVisibleAndNonPostItems(): void {
		$this->visibility()->set( 10, 'en', true );

		$items = array(
			$this->menuItem( 1, 10 ),                                 // страница, включена
			$this->menuItem( 2, 11, 'post_type', 'post' ),            // запись, скрыта
			$this->menuItem( 3, 0, 'custom', 'custom' ),              // произвольная ссылка
			$this->menuItem( 4, 55, 'taxonomy', 'category' ),         // рубрика
			$this->menuItem( 5, 12, 'post_type', 'attachment' ),      // вложение — не наш тип
		);

		$this->assertSame( array( 1, 3, 4, 5 ), $this->remainingMenu( $items ) );
	}

	public function testMenuDropsChildrenOfHiddenItemEvenIfListedBeforeParent(): void {
		$this->visibility()->setMany( array( 10 ), 'en', true );

		// Родитель (db 7, запись 11) скрыт; дети идут в массиве раньше него и вложены на два уровня.
		$items = array(
			$this->menuItem( 9, 10, 'post_type', 'page', 8 ),
			$this->menuItem( 8, 10, 'post_type', 'page', 7 ),
			$this->menuItem( 7, 11, 'post_type', 'post' ),
			$this->menuItem( 6, 10 ),
		);

		$this->assertSame( array( 6 ), $this->remainingMenu( $items ) );
	}

	public function testMenuMetaIsPrimedOnceForAllItems(): void {
		$items = array( $this->menuItem( 1, 10 ), $this->menuItem( 2, 11, 'post_type', 'post' ) );

		$this->gate()->filterMenuItems( $items );

		$this->assertCount( 1, $GLOBALS['wp_mlp_test_meta_primed'] );
		$this->assertSame( array( 10, 11 ), $GLOBALS['wp_mlp_test_meta_primed'][0][1] );
	}

	public function testMenuUntouchedInModeAllOrOnDefaultLanguage(): void {
		$items = array( $this->menuItem( 1, 10 ), $this->menuItem( 2, 11, 'post_type', 'post' ) );

		$this->configure( Settings::POSTS_ALL );
		$this->assertCount( 2, $this->gate()->filterMenuItems( $items ) );

		$this->configure( Settings::POSTS_MANUAL );
		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertCount( 2, $this->gate()->filterMenuItems( $items ) );
	}

	// --- Блочное меню ----------------------------------------------------------

	/**
	 * @param array<string, mixed> $attrs Атрибуты блока.
	 * @return array<string, mixed>
	 */
	private function navBlock( array $attrs, string $name = 'core/navigation-link' ): array {
		return array( 'blockName' => $name, 'attrs' => $attrs );
	}

	public function testNavigationBlockOfHiddenPostIsRemoved(): void {
		$this->visibility()->set( 10, 'en', true );
		$html = '<li class="wp-block-navigation-item">…</li>';

		$hidden  = $this->navBlock( array( 'kind' => 'post-type', 'type' => 'post', 'id' => 11 ) );
		$visible = $this->navBlock( array( 'kind' => 'post-type', 'type' => 'page', 'id' => 10 ) );

		$this->assertSame( '', $this->gate()->filterNavigationBlock( $html, $hidden ) );
		$this->assertSame( $html, $this->gate()->filterNavigationBlock( $html, $visible ) );
		$this->assertSame(
			'',
			$this->gate()->filterNavigationBlock( $html, $this->navBlock( array( 'kind' => 'post-type', 'type' => 'post', 'id' => 11 ), 'core/navigation-submenu' ) ),
			'Подменю убирается целиком.'
		);
	}

	public function testNavigationBlockOtherKindsAndBlocksAreUntouched(): void {
		$html = '<li>x</li>';
		$gate = $this->gate();

		$this->assertSame( $html, $gate->filterNavigationBlock( $html, $this->navBlock( array( 'kind' => 'taxonomy', 'type' => 'category', 'id' => 11 ) ) ) );
		$this->assertSame( $html, $gate->filterNavigationBlock( $html, $this->navBlock( array( 'kind' => 'custom', 'url' => 'https://x.test/' ) ) ) );
		$this->assertSame( $html, $gate->filterNavigationBlock( $html, $this->navBlock( array( 'kind' => 'post-type', 'type' => 'post', 'id' => 11 ), 'core/paragraph' ) ) );
		$this->assertSame( $html, $gate->filterNavigationBlock( $html, $this->navBlock( array( 'kind' => 'post-type', 'type' => 'attachment', 'id' => 12 ) ) ), 'Вложение — не наш тип.' );
		$this->assertSame( $html, $gate->filterNavigationBlock( $html, $this->navBlock( array( 'kind' => 'post-type', 'type' => 'post' ) ) ), 'Без id.' );
	}

	public function testNavigationBlockUntouchedWhenNotEnforced(): void {
		$html   = '<li>x</li>';
		$hidden = $this->navBlock( array( 'kind' => 'post-type', 'type' => 'post', 'id' => 11 ) );

		$this->configure( Settings::POSTS_ALL );
		$this->assertSame( $html, $this->gate()->filterNavigationBlock( $html, $hidden ) );

		$this->configure( Settings::POSTS_MANUAL );
		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertSame( $html, $this->gate()->filterNavigationBlock( $html, $hidden ), 'Язык по умолчанию.' );

		$_SERVER['REQUEST_URI']             = '/en/about/';
		$_GET[ EditorContext::QUERY_FLAG ]  = '1';
		$_GET[ EditorContext::QUERY_NONCE ] = wp_create_nonce( EditorContext::NONCE_ACTION );
		$this->assertSame( $html, $this->gate()->filterNavigationBlock( $html, $hidden ), 'Предпросмотр редактора.' );
	}

	public function testNavigationBlockPrimesMetaOnce(): void {
		$gate  = $this->gate();
		$block = $this->navBlock( array( 'kind' => 'post-type', 'type' => 'post', 'id' => 11 ) );

		$gate->filterNavigationBlock( '<li>a</li>', $block );
		$gate->filterNavigationBlock( '<li>b</li>', $block );

		$this->assertCount( 1, $GLOBALS['wp_mlp_test_meta_primed'] );
		$this->assertSame( array( 11 ), $GLOBALS['wp_mlp_test_meta_primed'][0][1] );
	}

	// --- Комментарии ---------------------------------------------------------------

	public function testCommentsClausesExcludeCommentsOfHiddenPosts(): void {
		$result = $this->gate()->filterCommentsClauses( array( 'where' => "comment_approved = '1'" ), new FakeCommentQuery() );

		$where = $result['where'];

		$this->assertStringStartsWith( "comment_approved = '1' AND ( NOT EXISTS", $where );
		$this->assertStringContainsString( 'wp_comments.comment_post_ID', $where );
		// Не трогаем комментарии к записям неподходящих типов: условие только по подходящим.
		$this->assertStringContainsString( "mlp_cp.post_type IN ('post','page')", $where );
		$this->assertStringNotContainsString( 'attachment', $where );
		$this->assertStringContainsString( "mlp_cm.meta_key = '_mlp_visible_en'", $where );
	}

	public function testCommentsClausesWithEmptyWhere(): void {
		$result = $this->gate()->filterCommentsClauses( array( 'where' => '' ), new FakeCommentQuery() );

		$this->assertStringStartsWith( '( NOT EXISTS', $result['where'] );
	}

	public function testCommentsClausesUntouchedWhenNotEnforced(): void {
		$clauses = array( 'where' => "comment_approved = '1'" );
		$query   = new FakeCommentQuery();

		$this->configure( Settings::POSTS_ALL );
		$this->assertSame( $clauses, $this->gate()->filterCommentsClauses( $clauses, $query ) );

		$this->configure( Settings::POSTS_MANUAL );
		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertSame( $clauses, $this->gate()->filterCommentsClauses( $clauses, $query ), 'Язык по умолчанию.' );

		$_SERVER['REQUEST_URI'] = '/en/about/';
		wp_mlp_test_request( array( 'is_admin' => true ) );
		$this->assertSame( $clauses, $this->gate()->filterCommentsClauses( $clauses, $query ), 'Админка.' );

		wp_mlp_test_request( array() );
		$_GET[ EditorContext::QUERY_FLAG ]  = '1';
		$_GET[ EditorContext::QUERY_NONCE ] = wp_create_nonce( EditorContext::NONCE_ACTION );
		$this->assertSame( $clauses, $this->gate()->filterCommentsClauses( $clauses, $query ), 'Предпросмотр редактора.' );
	}

	public function testCommentsOfEditedHiddenPostAreKeptForItsEditor(): void {
		$this->asEditor();

		$clauses = array( 'where' => "comment_approved = '1'" );

		$this->assertSame( $clauses, $this->gate()->filterCommentsClauses( $clauses, new FakeCommentQuery( array( 'post_id' => 10 ) ) ) );
		$this->assertNotSame( $clauses, $this->gate()->filterCommentsClauses( $clauses, new FakeCommentQuery() ), 'Общая выборка (виджет) фильтруется и для редактора.' );
	}

	public function testBlockAndCommentFiltersAreRegistered(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Frontend/PostVisibilityGate.php' );

		$this->assertStringContainsString( "add_filter( 'render_block', array( \$this, 'filterNavigationBlock' )", $source );
		$this->assertStringContainsString( "add_filter( 'comments_clauses', array( \$this, 'filterCommentsClauses' )", $source );
	}

	// --- Страницы (виджет «Страницы») и соседние записи --------------------

	public function testGetPagesDropsHiddenPages(): void {
		$this->visibility()->set( 10, 'en', true );

		$pages = array(
			new \WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) ),
			new \WP_Post( array( 'ID' => 20, 'post_type' => 'page' ) ),
		);

		$result = $this->gate()->filterPages( $pages );

		$this->assertSame( array( 10 ), array_map( static fn( \WP_Post $p ): int => $p->ID, $result ) );
	}

	public function testAdjacentPostWhereRestrictsToVisible(): void {
		$post  = new \WP_Post( array( 'ID' => 11, 'post_type' => 'post' ) );
		$where = $this->gate()->filterAdjacentWhere( "WHERE p.post_date < 'x'", false, '', 'category', $post );

		$this->assertStringStartsWith( "WHERE p.post_date < 'x' AND EXISTS", $where );
		$this->assertStringContainsString( "mlp_pm.post_id = p.ID AND mlp_pm.meta_key = '_mlp_visible_en'", $where );
	}

	public function testAdjacentPostWhereUntouchedWhenNotEnforcedOrForOtherTypes(): void {
		$post = new \WP_Post( array( 'ID' => 11, 'post_type' => 'post' ) );

		$_SERVER['REQUEST_URI'] = '/about/';
		$this->assertSame( 'W', $this->gate()->filterAdjacentWhere( 'W', false, '', 'category', $post ) );

		$_SERVER['REQUEST_URI'] = '/en/about/';
		$secret                 = new \WP_Post( array( 'ID' => 13, 'post_type' => 'secret' ) );
		$this->assertSame( 'W', $this->gate()->filterAdjacentWhere( 'W', false, '', 'category', $secret ) );
		$this->assertSame( 'W', $this->gate()->filterAdjacentWhere( 'W', false, '', 'category', null ) );
	}
}
