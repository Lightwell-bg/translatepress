<?php
/**
 * Тесты REST-маршрутов LingoCanvas Cloud.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rest;

use LingoCanvas\Admin\CloudActions;
use LingoCanvas\Admin\CloudStatus;
use LingoCanvas\Rest\CloudController;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Tests\Doubles\CloudHttp;
use LingoCanvas\Translation\CloudClient;
use LingoCanvas\Translation\ProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( CloudController::class )]
final class CloudControllerTest extends TestCase {

	private const TOKEN = 'lcc_secret_token_value_123456';

	/** @var mixed */
	private $savedWpdb;

	protected function setUp(): void {
		$this->savedWpdb  = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \LingoCanvas\Tests\Doubles\FakeWpdb();
		CloudHttp::reset();
		lingocanvas_test_http( array() );
		lingocanvas_test_wp( array() );
		unset( $GLOBALS['lingocanvas_test_added_actions'] );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->savedWpdb;
		CloudHttp::reset();
		lingocanvas_test_http( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_options( array() );
	}

	/**
	 * @param array<string, mixed>  $overrides Настройки поверх значений по умолчанию.
	 * @param array<string, string> $constants Константы wp-config.php.
	 */
	private function controller( array $overrides = array(), array $constants = array() ): CloudController {
		$options = array( Settings::optionName() => array_merge( Settings::defaults(), $overrides ) );

		// Заявка, архив и прочее служебное состояние Cloud живут в отдельных опциях и сохраняются.
		foreach ( lingocanvas_test_options() as $name => $value ) {
			if ( str_starts_with( (string) $name, 'lingocanvas_cloud_' ) ) {
				$options[ $name ] = $value;
			}
		}

		lingocanvas_test_options( $options );

		$settings = new Settings();
		$providers = new ProviderFactory( $settings, static fn( string $name ): ?string => $constants[ $name ] ?? null, array() );
		$status    = new CloudStatus( $providers );

		return new CloudController( $status, new CloudActions( $providers, $settings, $status ) );
	}

	public function testRoutesRequireManageOptions(): void {
		$controller = $this->controller();

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => false ) ) );
		$this->assertFalse( $controller->canManage() );

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => true ) ) );
		$this->assertTrue( $controller->canManage() );
	}

	public function testRegistersStatusAndClaimWithPermissionCallback(): void {
		$controller = $this->controller();
		$controller->register();

		$actions = $GLOBALS['lingocanvas_test_added_actions']['rest_api_init'] ?? array();
		$this->assertCount( 1, $actions );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rest/CloudController.php' );
		$this->assertSame( 2, substr_count( $source, "'permission_callback' => array( \$this, 'canManage' )" ) );
		$this->assertStringContainsString( "'/cloud/status'", $source );
		$this->assertStringContainsString( "'/cloud/claim'", $source );
	}

	public function testStatusReturnsDisplayFieldsOnlyAndNoToken(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'account'      => array( 'id' => 1, 'name' => 'Acme', 'plan' => 'starter', 'status' => 'active' ),
				'sites'        => array( 'used' => 1, 'max' => 3 ),
				'quota'        => array( 'monthly_remaining' => 80000, 'packs_remaining' => 0, 'packs_usable' => true, 'total_remaining' => 80000 ),
				'subscription' => array( 'status' => 'active', 'current_period_end' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 ), 'cancel_at_period_end' => false ),
			)
		);

		$response = $this->controller( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) )->status( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame(
			array( 'plan', 'subscription', 'renewal', 'monthly_left', 'packs_left', 'packs_note', 'sites', 'live' ),
			array_keys( $response->get_data() )
		);
		$this->assertStringNotContainsString( self::TOKEN, (string) json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'Acme', (string) json_encode( $response->get_data() ) );
	}

	public function testStatusRefreshParamBypassesCache(): void {
		$body = array( 'account' => array( 'plan' => 'starter' ), 'quota' => array(), 'sites' => array() );
		CloudHttp::$queue[] = CloudHttp::json( 200, $body );
		CloudHttp::$queue[] = CloudHttp::json( 200, $body );

		$controller = $this->controller( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$controller->status( new \WP_REST_Request() );
		$controller->status( new \WP_REST_Request() );
		$this->assertCount( 1, CloudHttp::$requests );

		$controller->status( new \WP_REST_Request( array( 'refresh' => '1' ) ) );
		$this->assertCount( 2, CloudHttp::$requests );
	}

	public function testStatusWithoutTokenAndApiErrorGiveHumanWpError(): void {
		$error = $this->controller( array( 'cloud_consent_at' => 1700000000 ) )->status( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertStringContainsString( 'no longer connected', $error->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $error->get_error_data() );

		CloudHttp::$queue[] = CloudHttp::json( 500, array( 'error' => array( 'code' => 'internal_error', 'message' => 'RAW SERVER TEXT ' . self::TOKEN ) ) );
		$error = $this->controller( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) )->status( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertStringNotContainsString( 'RAW SERVER TEXT', $error->get_error_message() );
		$this->assertStringNotContainsString( self::TOKEN, $error->get_error_message() );
		$this->assertSame( array( 'status' => 502 ), $error->get_error_data() );
	}

	public function testClaimReturnsOnlyStatusAndMessage(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => self::TOKEN, 'site_id' => 1, 'account_id' => 1 ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$controller = $this->controller( array( 'cloud_consent_at' => 1700000000 ) );

		$pending = $controller->claim();
		$this->assertSame( 'pending', $pending->get_data()['status'] );
		$this->assertSame( array( 'status', 'message' ), array_keys( $pending->get_data() ) );

		$connected = $controller->claim();
		$this->assertSame( 'connected', $connected->get_data()['status'] );
		$this->assertSame( array( 'status', 'message' ), array_keys( $connected->get_data() ) );
		$this->assertStringNotContainsString( self::TOKEN, (string) json_encode( $connected->get_data() ) );

		$settings = new Settings();
		$this->assertSame( self::TOKEN, $settings->cloudToken() );
		$this->assertSame( Settings::PROVIDER_CLOUD, $settings->provider() );
	}

	public function testClaimErrorIsHumanAndStopsPolling(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::json( 410, array( 'error' => array( 'code' => 'claim_expired', 'message' => 'RAW' ) ) );

		$result = $this->controller( array( 'cloud_consent_at' => 1700000000 ) )->claim()->get_data();

		// Контракт ответа: ровно status + message, человеческий текст, без секретов.
		$this->assertSame( array( 'status', 'message' ), array_keys( $result ) );
		$this->assertSame( 'error', $result['status'] );
		$this->assertStringNotContainsString( 'RAW', $result['message'] );
		$this->assertStringContainsString( 'expired', $result['message'] );

		// Остановка опроса определяется статусом: цикл продолжается только при «pending».
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/cloud.js' );
		$this->assertMatchesRegularExpression( "/'pending'\s*===\s*status\s*\)\s*\{\s*window\.setTimeout\(\s*poll/", $js );
		$this->assertMatchesRegularExpression( '/say\(\s*\(\s*data\s*&&\s*data\.message\s*\)/', $js );
	}

	public function testClaimAlreadyClaimedLostGivesClaimIdButNoSecretAndKeepsClaim(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'topsecretvalue', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::error( 409, 'already_claimed' );

		$result = $this->controller( array( 'cloud_consent_at' => 1700000000 ) )->claim()->get_data();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'lcc_claim_abc', $result['message'] );
		$this->assertStringContainsString( 'Contact support', $result['message'] );
		$this->assertStringNotContainsString( 'topsecretvalue', (string) json_encode( $result ) );
		$this->assertNotFalse( get_option( CloudClient::CLAIM_OPTION, false ) );
	}

	public function testPollingScriptDoesNotStartWithoutConsent(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/cloud.js' );

		$this->assertStringContainsString( 'config.pending && config.consent', $js );
		$this->assertStringContainsString( 'config.connected && config.consent', $js );
	}

	public function testNoConsentMeansNoHttpForStatusOrClaimEvenWithConstantToken(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600 ), false );

		$byOption   = $this->controller( array( 'cloud_token' => self::TOKEN ) );
		$byConstant = $this->controller( array(), array( ProviderFactory::CONST_CLOUD_TOKEN => 'lcc_constant_token_abcdef' ) );

		foreach ( array( $byOption, $byConstant ) as $controller ) {
			$status = $controller->status( new \WP_REST_Request( array( 'refresh' => '1' ) ) );

			$this->assertInstanceOf( \WP_REST_Response::class, $status );
			$this->assertSame( 'no_consent', $status->get_data()['status'] );
		}

		// Заявка без согласия: опрос отказывает, не обращаясь к Cloud.
		$claim = $this->controller()->claim()->get_data();
		$this->assertSame( 'no_consent', $claim['status'] );
		$this->assertNotFalse( get_option( CloudClient::CLAIM_OPTION, false ) );

		$this->assertSame( array(), CloudHttp::$requests );
	}
}
