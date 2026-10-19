<?php
/**
 * Тесты REST-маршрутов ручного включения записей на языках.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rest\PostVisibilityController;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Tests\Doubles\FakeWpdb;

#[CoversClass( PostVisibilityController::class )]
final class PostVisibilityControllerTest extends TestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;

		lingocanvas_test_postmeta( array() );
		unset( $GLOBALS['lingocanvas_test_routes'] );

		lingocanvas_test_options(
			array(
				Settings::optionName() => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru'    => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en'    => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
						'pt-br' => array( 'locale' => 'pt-br', 'slug' => 'pt', 'label' => 'Português', 'status' => 'draft' ),
					),
				),
			)
		);

		lingocanvas_test_wp(
			array(
				'posts' => array(
					10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) ),
					11 => new \WP_Post( array( 'ID' => 11, 'post_type' => 'post' ) ),
					12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment' ) ),
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['lingocanvas_test_routes'] );
		lingocanvas_test_options( array() );
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_wp( array() );
	}

	private function controller(): PostVisibilityController {
		return new PostVisibilityController(
			new Settings(),
			new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() )
		);
	}

	/**
	 * @param array<string, mixed> $params Параметры запроса.
	 */
	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request( $params );
	}

	private function assertError( mixed $result, string $code, int $status = 400 ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array( 'status' => $status ), $result->get_error_data() );
	}

	public function testRoutesAreRegisteredWithPermissionCallbacks(): void {
		$controller = $this->controller();
		$controller->registerRoutes();

		$routes = $GLOBALS['lingocanvas_test_routes'];

		$this->assertSame( array( 'lingocanvas/v1/posts/visibility', 'lingocanvas/v1/posts/visibility/enable-translated' ), array_keys( $routes ) );
		$this->assertSame( 'POST', $routes['lingocanvas/v1/posts/visibility']['methods'] );
		$this->assertSame( array( $controller, 'canEditPosts' ), $routes['lingocanvas/v1/posts/visibility']['permission_callback'] );
		$this->assertSame( array( $controller, 'canManage' ), $routes['lingocanvas/v1/posts/visibility/enable-translated']['permission_callback'] );
	}

	// --- Права --------------------------------------------------------------

	public function testEditPostIsCheckedForEveryId(): void {
		lingocanvas_test_wp( array( 'caps' => array( 'edit_post:10' => true, 'edit_post:11' => false ) ) + lingocanvas_test_wp() );

		$this->assertTrue( $this->controller()->canEditPosts( $this->request( array( 'ids' => array( 10 ) ) ) ) );
		$this->assertFalse(
			$this->controller()->canEditPosts( $this->request( array( 'ids' => array( 10, 11 ) ) ) ),
			'Нет права хотя бы на одну запись — отказ всему запросу.'
		);
	}

	public function testMalformedIdsFallBackToEditPostsCapability(): void {
		lingocanvas_test_wp( array( 'caps' => array( 'edit_posts' => false ) ) + lingocanvas_test_wp() );
		$this->assertFalse( $this->controller()->canEditPosts( $this->request( array( 'ids' => 'nope' ) ) ) );

		lingocanvas_test_wp( array( 'caps' => array( 'edit_posts' => true ) ) + lingocanvas_test_wp() );
		$this->assertTrue( $this->controller()->canEditPosts( $this->request( array( 'ids' => 'nope' ) ) ) );
	}

	public function testManageOptionsIsRequiredInAdditionToEditPost(): void {
		// Право править запись есть, права управлять плагином нет — отказ, как у остальных маршрутов плагина.
		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => false, 'edit_post' => true, 'edit_posts' => true ) ) + lingocanvas_test_wp() );

		$this->assertFalse( $this->controller()->canEditPosts( $this->request( array( 'ids' => array( 10 ) ) ) ) );
		$this->assertFalse( $this->controller()->canEditPosts( $this->request( array( 'ids' => 'nope' ) ) ) );

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => true, 'edit_post' => true ) ) + lingocanvas_test_wp() );

		$this->assertTrue( $this->controller()->canEditPosts( $this->request( array( 'ids' => array( 10 ) ) ) ) );
	}

	public function testBulkEnableNeedsManageOptions(): void {
		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => false ) ) + lingocanvas_test_wp() );
		$this->assertFalse( $this->controller()->canManage() );

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => true ) ) + lingocanvas_test_wp() );
		$this->assertTrue( $this->controller()->canManage() );
	}

	// --- Разбор ввода -----------------------------------------------------------

	public function testParseIdsIsStrict(): void {
		$this->assertSame( array( 1, 2, 3 ), PostVisibilityController::parseIds( array( 1, '2', 3, 2 ) ) );

		foreach ( array( null, 'x', 5, array(), array( 0 ), array( -1 ), array( 1.5 ), array( true ), array( '' ), array( 'abc' ), array( '1e3' ), array( array( 1 ) ), array( ' 7' ) ) as $bad ) {
			$this->assertNull( PostVisibilityController::parseIds( $bad ), wp_json_encode( $bad ) );
		}
	}

	public function testParseIdsEnforcesCap(): void {
		$this->assertCount( 200, PostVisibilityController::parseIds( range( 1, 200 ) ) );
		$this->assertNull( PostVisibilityController::parseIds( range( 1, 201 ) ) );
	}

	public function testParseBool(): void {
		foreach ( array( true, 1, '1', 'true' ) as $yes ) {
			$this->assertTrue( PostVisibilityController::parseBool( $yes ) );
		}

		foreach ( array( false, 0, '0', 'false' ) as $no ) {
			$this->assertFalse( PostVisibilityController::parseBool( $no ) );
		}

		foreach ( array( null, '', 'yes', 2, array(), 'TRUE ' ) as $bad ) {
			$this->assertNull( PostVisibilityController::parseBool( $bad ) );
		}
	}

	// --- Включение и выключение --------------------------------------------------

	public function testSetsVisibilityAndReturnsNewStates(): void {
		$this->db->var = '2';

		$response = $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10, 11 ), 'locale' => 'en', 'visible' => true ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'locale'       => 'en',
				'visible'      => true,
				'changed'      => 2,
				'states'       => array(
					array( 'id' => 10, 'visible' => true ),
					array( 'id' => 11, 'visible' => true ),
				),
				'visibleCount' => 2,
			),
			$response->get_data()
		);
		$this->assertSame( '1', lingocanvas_test_postmeta()[10]['_lingocanvas_visible_en'] );

		// Выключение.
		$off = $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10 ), 'locale' => 'en', 'visible' => '0' ) ) );

		$this->assertSame( 1, $off->get_data()['changed'] );
		$this->assertSame( array( array( 'id' => 10, 'visible' => false ) ), $off->get_data()['states'] );
		$this->assertArrayNotHasKey( '_lingocanvas_visible_en', lingocanvas_test_postmeta()[10] );
	}

	public function testLanguageWithSubtagUsesNormalizedCode(): void {
		$response = $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10 ), 'locale' => 'PT_br', 'visible' => true ) ) );

		$this->assertSame( 'pt-br', $response->get_data()['locale'] );
		$this->assertSame( '1', lingocanvas_test_postmeta()[10]['_lingocanvas_visible_pt_br'] );
	}

	public function testUnknownOrDefaultLocaleIsRejectedAndNothingIsWritten(): void {
		foreach ( array( 'de', 'ru', '', "en'; DROP TABLE x", null, array( 'en' ) ) as $locale ) {
			$result = $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10 ), 'locale' => $locale, 'visible' => true ) ) );

			$this->assertError( $result, 'lingocanvas_unknown_locale' );
		}

		$this->assertSame( array(), lingocanvas_test_postmeta() );
	}

	public function testInvalidIdsAndVisibleAreRejected(): void {
		$this->assertError( $this->controller()->setVisibility( $this->request( array( 'locale' => 'en', 'visible' => true ) ) ), 'lingocanvas_invalid_ids' );
		$this->assertError( $this->controller()->setVisibility( $this->request( array( 'ids' => range( 1, 201 ), 'locale' => 'en', 'visible' => true ) ) ), 'lingocanvas_invalid_ids' );
		$this->assertError( $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10 ), 'locale' => 'en', 'visible' => 'maybe' ) ) ), 'lingocanvas_invalid_visible' );
		$this->assertError( $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10 ), 'locale' => 'en' ) ) ), 'lingocanvas_invalid_visible' );
	}

	public function testWholeRequestIsRejectedWhenOneIdIsNotAnEligiblePost(): void {
		foreach ( array( 12, 999 ) as $bad ) {
			$result = $this->controller()->setVisibility( $this->request( array( 'ids' => array( 10, $bad ), 'locale' => 'en', 'visible' => true ) ) );

			$this->assertError( $result, 'lingocanvas_invalid_post' );
		}

		$this->assertSame( array(), lingocanvas_test_postmeta(), 'Частично запрос не применяется.' );
	}

	// --- Массовое включение ----------------------------------------------------

	public function testEnableTranslatedReturnsCounts(): void {
		$this->db->cols = array( '10', '11', '12' );
		$this->db->var  = '2';

		$response = $this->controller()->enableTranslated( $this->request( array( 'locale' => 'en' ) ) );

		$this->assertSame( array( 'locale' => 'en', 'enabled' => 2, 'visibleCount' => 2 ), $response->get_data() );
	}

	public function testEnableTranslatedRejectsUnknownLocale(): void {
		$this->assertError( $this->controller()->enableTranslated( $this->request( array( 'locale' => 'de' ) ) ), 'lingocanvas_unknown_locale' );
		$this->assertError( $this->controller()->enableTranslated( $this->request( array() ) ), 'lingocanvas_unknown_locale' );
		$this->assertSame( array(), $this->db->queries, 'С неверным языком в базу не ходим.' );
	}
}
