<?php
/**
 * Тесты выбора провайдера: OpenAI или LingoCanvas Cloud.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Translation;

use LingoCanvas\Settings\Settings;
use LingoCanvas\Support\Env;
use LingoCanvas\Translation\CenterAiCloudProvider;
use LingoCanvas\Translation\ManualProvider;
use LingoCanvas\Translation\OpenAiProvider;
use LingoCanvas\Translation\ProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ProviderFactory::class )]
#[CoversClass( Settings::class )]
final class ProviderFactoryCloudTest extends TestCase {

	protected function setUp(): void {
		Env::reset();
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		Env::reset();
		lingocanvas_test_options( array() );
	}

	/**
	 * @param array<string, mixed>  $overrides Значения поверх настроек по умолчанию.
	 * @param array<string, string> $constants Подмена констант wp-config.php.
	 */
	private function factory( array $overrides = array(), array $constants = array() ): ProviderFactory {
		lingocanvas_test_options( array( Settings::optionName() => array_merge( Settings::defaults(), $overrides ) ) );

		return new ProviderFactory( new Settings(), static fn( string $name ): ?string => $constants[ $name ] ?? null );
	}

	public function testDefaultProviderIsOpenAiAndUnchanged(): void {
		$this->assertSame( 'openai', ( new Settings() )->provider() );
		$this->assertInstanceOf( ManualProvider::class, $this->factory()->create() );

		$ready = $this->factory( array( 'openai_api_key' => 'sk', 'openai_model' => 'm' ) );
		$this->assertInstanceOf( OpenAiProvider::class, $ready->create() );
		$this->assertSame( array(), $ready->missing() );
	}

	public function testCloudTokenAndConsentIgnoredWhileOpenAiSelected(): void {
		$factory = $this->factory( array( 'cloud_token' => 'lcc_abcdefgh', 'cloud_consent_at' => 5 ) );

		$this->assertInstanceOf( ManualProvider::class, $factory->create() );
		$this->assertSame( array( ProviderFactory::FIELD_KEY, ProviderFactory::FIELD_MODEL ), $factory->missing() );
	}

	public function testCloudConnectedBuildsCloudProvider(): void {
		$factory = $this->factory( array( 'provider' => 'cloud', 'cloud_token' => 'lcc_abcdefgh', 'cloud_consent_at' => 1700000000 ) );

		$this->assertTrue( $factory->isCloudConnected() );
		$this->assertTrue( $factory->isReady() );
		$this->assertSame( array(), $factory->missing() );
		$this->assertInstanceOf( CenterAiCloudProvider::class, $factory->create() );
	}

	public function testCloudWithoutConsentFallsBackToManual(): void {
		$factory = $this->factory( array( 'provider' => 'cloud', 'cloud_token' => 'lcc_abcdefgh', 'cloud_consent_at' => 0 ) );

		$this->assertFalse( $factory->isCloudConnected() );
		$this->assertInstanceOf( ManualProvider::class, $factory->create() );
		$this->assertSame( array( ProviderFactory::FIELD_CLOUD_CONSENT ), $factory->missing() );
		$this->assertStringContainsString( 'not enabled', ProviderFactory::unavailableMessage( $factory->missing() ) );
	}

	public function testCloudWithoutTokenFallsBackToManual(): void {
		$factory = $this->factory( array( 'provider' => 'cloud', 'cloud_consent_at' => 1700000000 ) );

		$this->assertInstanceOf( ManualProvider::class, $factory->create() );
		$this->assertSame( array( ProviderFactory::FIELD_CLOUD_TOKEN ), $factory->missing() );
		$this->assertStringContainsString( 'not connected', ProviderFactory::unavailableMessage( $factory->missing() ) );
	}

	public function testCloudWithOpenAiKeyDoesNotFallBackToOpenAi(): void {
		$factory = $this->factory( array( 'provider' => 'cloud', 'openai_api_key' => 'sk', 'openai_model' => 'm' ) );

		$this->assertInstanceOf( ManualProvider::class, $factory->create() );
	}

	public function testConstantOverridesTokenAndUrl(): void {
		$factory = $this->factory(
			array( 'provider' => 'cloud', 'cloud_token' => 'lcc_fromdb123', 'cloud_consent_at' => 1, 'cloud_api_url' => 'https://db.example.test' ),
			array(
				ProviderFactory::CONST_CLOUD_TOKEN   => 'lcc_fromconst1',
				ProviderFactory::CONST_CLOUD_API_URL => 'https://const.example.test/',
			)
		);

		$this->assertSame( 'lcc_fromconst1', $factory->cloudToken() );
		$this->assertSame( 'https://const.example.test', $factory->cloudApiUrl() );
	}

	public function testInsecureConstantUrlIsIgnored(): void {
		$factory = $this->factory( array(), array( ProviderFactory::CONST_CLOUD_API_URL => 'http://evil.example.test' ) );

		$this->assertSame( Settings::DEFAULT_CLOUD_API_URL, $factory->cloudApiUrl() );
	}

	public function testFilterStillApplies(): void {
		$factory     = $this->factory( array( 'provider' => 'cloud', 'cloud_token' => 'lcc_abcdefgh', 'cloud_consent_at' => 1700000000 ) );
		$replacement = new ManualProvider();

		// Без фильтра выбранный плагином провайдер — Cloud.
		$this->assertInstanceOf( CenterAiCloudProvider::class, $factory->create() );

		lingocanvas_test_filter(
			'lingocanvas_translation_provider',
			static function ( $provider ) use ( $replacement ) {
				unset( $provider );

				return $replacement;
			}
		);

		try {
			$this->assertSame( $replacement, $factory->create() );
		} finally {
			lingocanvas_test_filter( 'lingocanvas_translation_provider', null );
		}

		$this->assertInstanceOf( CenterAiCloudProvider::class, $factory->create() );
	}

	public function testSettingsDefaultsAndUrlRules(): void {
		$settings = new Settings();

		$this->assertSame( 'https://cloud.centerai.eu', $settings->cloudApiUrl() );
		$this->assertSame( 0, $settings->cloudConsentAt() );
		$this->assertSame( '', $settings->cloudToken() );
		$this->assertSame( 'https://a.example.test', Settings::sanitizeCloudApiUrl( 'https://a.example.test/' ) );
		$this->assertSame( 'http://localhost:8000', Settings::sanitizeCloudApiUrl( 'http://localhost:8000' ) );
		$this->assertSame( 'http://127.0.0.1:8000', Settings::sanitizeCloudApiUrl( 'http://127.0.0.1:8000' ) );
		$this->assertSame( '', Settings::sanitizeCloudApiUrl( 'http://a.example.test' ) );
		$this->assertSame( '', Settings::sanitizeCloudApiUrl( 'ftp://a.example.test' ) );
		$this->assertSame( '', Settings::sanitizeCloudApiUrl( 'https://user:pw@a.example.test' ) );
		$this->assertSame( 'openai', Settings::sanitizeProvider( 'bogus', 'openai' ) );
	}

	public function testSanitizeKeepsCloudSettingsWhenFormOmitsThem(): void {
		$settings = $this->factory( array( 'provider' => 'cloud', 'cloud_token' => 'lcc_abcdefgh', 'cloud_consent_at' => 123, 'cloud_api_url' => 'http://127.0.0.1:9' ) );
		unset( $settings );

		$sanitized = ( new Settings() )->sanitize( array( 'default_locale' => 'ru', 'languages' => array( 'ru' => array( 'locale' => 'ru' ) ) ) )['settings'];

		$this->assertSame( 'cloud', $sanitized['provider'] );
		$this->assertSame( 'lcc_abcdefgh', $sanitized['cloud_token'] );
		$this->assertSame( 123, $sanitized['cloud_consent_at'] );
		$this->assertSame( 'http://127.0.0.1:9', $sanitized['cloud_api_url'] );
	}

	public function testSanitizeHandlesCloudFormInput(): void {
		$this->factory( array( 'cloud_token' => 'lcc_abcdefgh', 'cloud_consent_at' => 123 ) );
		$base = array( 'default_locale' => 'ru', 'languages' => array( 'ru' => array( 'locale' => 'ru' ) ) );

		$set = ( new Settings() )->sanitize( $base + array( 'provider' => 'cloud', 'cloud_token' => 'lcc_newtoken99', 'cloud_api_url' => 'http://bad.example.test' ) )['settings'];
		$this->assertSame( 'cloud', $set['provider'] );
		$this->assertSame( 'lcc_newtoken99', $set['cloud_token'] );
		$this->assertSame( Settings::DEFAULT_CLOUD_API_URL, $set['cloud_api_url'] );

		$junk = ( new Settings() )->sanitize( $base + array( 'provider' => 'evil', 'cloud_token' => '<script>' ) )['settings'];
		$this->assertSame( 'openai', $junk['provider'] );
		$this->assertSame( 'lcc_abcdefgh', $junk['cloud_token'] );

		$cleared = ( new Settings() )->sanitize( $base + array( 'cloud_token_clear' => '1', 'cloud_consent' => '' ) )['settings'];
		$this->assertSame( '', $cleared['cloud_token'] );
		$this->assertSame( 0, $cleared['cloud_consent_at'] );

		$kept = ( new Settings() )->sanitize( $base + array( 'cloud_consent' => '1' ) )['settings'];
		$this->assertSame( 123, $kept['cloud_consent_at'] );
	}
}
