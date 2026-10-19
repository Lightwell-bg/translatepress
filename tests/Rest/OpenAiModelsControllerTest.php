<?php
/**
 * Тесты REST-списка моделей OpenAI.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rest\OpenAiModelsController;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Translation\ProviderFactory;

#[CoversClass( OpenAiModelsController::class )]
final class OpenAiModelsControllerTest extends TestCase {

	private const KEY = 'sk-test-secret-key-12345678';

	protected function setUp(): void {
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_http( array() );
	}

	protected function tearDown(): void {
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_http( array() );
	}

	private function controller( string $key = self::KEY, string $base = '' ): OpenAiModelsController {
		lingocanvas_test_options(
			array(
				Settings::optionName() => array(
					'openai_api_key'  => $key,
					'openai_base_url' => $base,
				),
			)
		);

		return new OpenAiModelsController(
			new ProviderFactory( new Settings(), static fn( string $name ): ?string => null, array() )
		);
	}

	/**
	 * @param array<mixed> $data Элементы `data`.
	 * @return array<string, mixed>
	 */
	private function ok( array $data ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( 'data' => $data ) ),
		);
	}

	public function testRequiresManageOptions(): void {
		$controller = $this->controller();

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => false ) ) );
		$this->assertFalse( $controller->canManage() );

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => true ) ) );
		$this->assertTrue( $controller->canManage() );
	}

	public function testMissingKeyGivesClearError(): void {
		$result = $this->controller( '' )->models( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'lingocanvas_openai_no_key', $result->get_error_code() );
		$this->assertStringContainsString( 'key', $result->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertSame( array(), lingocanvas_test_http()['calls'] );
	}

	public function testFiltersAndSortsNewestFirst(): void {
		lingocanvas_test_http_set(
			'response',
			$this->ok(
				array(
					array( 'id' => 'gpt-old', 'created' => 100 ),
					array( 'id' => 'text-embedding-3-small', 'created' => 900 ),
					array( 'id' => 'gpt-new', 'created' => 300 ),
					array( 'id' => 'whisper-1', 'created' => 800 ),
					array( 'id' => 'gpt-4o-mini-tts', 'created' => 800 ),
					array( 'id' => 'gpt-image-1', 'created' => 800 ),
					array( 'id' => 'gpt-realtime', 'created' => 800 ),
					array( 'id' => 'omni-moderation-latest', 'created' => 800 ),
					array( 'id' => 'gpt-4o-search-preview', 'created' => 800 ),
					array( 'id' => 'davinci-002', 'created' => 800 ),
					array( 'id' => 'gpt-mid', 'created' => 200 ),
					array( 'created' => 5 ),
				)
			)
		);

		$result = $this->controller()->models( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame(
			array(
				array( 'id' => 'gpt-new', 'created' => 300 ),
				array( 'id' => 'gpt-mid', 'created' => 200 ),
				array( 'id' => 'gpt-old', 'created' => 100 ),
			),
			$result->get_data()
		);
	}

	public function testRequestUsesBaseUrlKeyAndTimeout(): void {
		lingocanvas_test_http_set( 'response', $this->ok( array() ) );

		$this->controller( self::KEY, 'https://gateway.test/v1/' )->models( new \WP_REST_Request() );

		$call = lingocanvas_test_http()['calls'][0];

		$this->assertSame( 'https://gateway.test/v1/models', $call[0] );
		$this->assertSame( 'Bearer ' . self::KEY, $call[1]['headers']['Authorization'] );
		$this->assertSame( OpenAiModelsController::TIMEOUT, $call[1]['timeout'] );
	}

	public function testSuccessIsCachedAndRefreshBypassesCache(): void {
		lingocanvas_test_http_set( 'response', $this->ok( array( array( 'id' => 'gpt-a', 'created' => 1 ) ) ) );
		$controller = $this->controller();

		$controller->models( new \WP_REST_Request() );
		$second = $controller->models( new \WP_REST_Request() );

		$this->assertCount( 1, lingocanvas_test_http()['calls'] );
		$this->assertSame( OpenAiModelsController::CACHE_TTL, lingocanvas_test_http()['transient_ttl'] );
		$this->assertSame( 'gpt-a', $second->get_data()[0]['id'] );

		lingocanvas_test_http_set( 'response', $this->ok( array( array( 'id' => 'gpt-b', 'created' => 2 ) ) ) );
		$fresh = $controller->models( new \WP_REST_Request( array( 'refresh' => '1' ) ) );

		$this->assertCount( 2, lingocanvas_test_http()['calls'] );
		$this->assertSame( 'gpt-b', $fresh->get_data()[0]['id'] );

		$again = $controller->models( new \WP_REST_Request() );
		$this->assertSame( 'gpt-b', $again->get_data()[0]['id'] );
	}

	public function testCacheKeyDependsOnBaseUrlAndKeyTail(): void {
		lingocanvas_test_http_set( 'response', $this->ok( array( array( 'id' => 'gpt-a', 'created' => 1 ) ) ) );

		$this->controller( self::KEY, '' )->models( new \WP_REST_Request() );
		$this->controller( self::KEY, 'https://other.test/v1' )->models( new \WP_REST_Request() );

		$this->assertCount( 2, lingocanvas_test_http()['calls'] );
		$this->assertCount( 2, lingocanvas_test_http()['transients'] );

		foreach ( array_keys( lingocanvas_test_http()['transients'] ) as $name ) {
			$this->assertStringNotContainsString( self::KEY, $name );
		}
	}

	public function testNetworkErrorGivesWpErrorWithoutKey(): void {
		lingocanvas_test_http_set( 'response', new \WP_Error( 'http_request_failed', 'cURL error 28 for Bearer ' . self::KEY ) );

		$result = $this->controller()->models( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
		$this->assertStringContainsString( 'cURL error 28', $result->get_error_message() );
		$this->assertStringNotContainsString( self::KEY, $result->get_error_message() );
		$this->assertSame( array(), lingocanvas_test_http()['transients'] );
	}

	public function testHttpErrorShowsApiMessageAndIsNotCached(): void {
		lingocanvas_test_http_set(
			'response',
			array(
				'response' => array( 'code' => 401 ),
				'body'     => (string) json_encode( array( 'error' => array( 'message' => 'Incorrect API key provided: ' . self::KEY ) ) ),
			)
		);

		$result = $this->controller()->models( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'HTTP 401', $result->get_error_message() );
		$this->assertStringContainsString( 'Incorrect API key', $result->get_error_message() );
		$this->assertStringNotContainsString( self::KEY, $result->get_error_message() );
		$this->assertSame( array(), lingocanvas_test_http()['transients'] );
	}

	public function testUnexpectedBodyGivesError(): void {
		lingocanvas_test_http_set( 'response', array( 'response' => array( 'code' => 200 ), 'body' => '<html>' ) );

		$result = $this->controller()->models( new \WP_REST_Request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'status' => 400 ), $result->get_error_data() );
	}
}
