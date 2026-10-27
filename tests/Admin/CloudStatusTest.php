<?php
/**
 * Тесты показа состояния аккаунта LingoCanvas Cloud и связанных полей формы настроек.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use LingoCanvas\Admin\CloudStatus;
use LingoCanvas\Admin\SettingsPage;
use LingoCanvas\I18n\LanguagePacks;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Tests\Doubles\CloudHttp;
use LingoCanvas\Translation\CloudException;
use LingoCanvas\Translation\ProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( CloudStatus::class )]
final class CloudStatusTest extends TestCase {

	private const TOKEN = 'lcc_secret_token_value_123456';
	private const NOW   = 1790000000; // 2026-09-21.

	protected function setUp(): void {
		CloudHttp::reset();
		lingocanvas_test_http( array() );
		lingocanvas_test_options( array( Settings::optionName() => array_merge( Settings::defaults(), array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) ) ) );
		$_POST = array();
	}

	protected function tearDown(): void {
		CloudHttp::reset();
		lingocanvas_test_http( array() );
		lingocanvas_test_options( array() );
		$_POST = array();
	}

	private function cloudStatus(): CloudStatus {
		return new CloudStatus( new ProviderFactory( new Settings(), static fn( string $name ): ?string => null, array() ) );
	}

	/**
	 * @param array<string, mixed> $overrides Поля ответа API поверх обычного.
	 * @return array<string, mixed>
	 */
	private function account( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'account'      => array( 'id' => 1, 'name' => 'Acme', 'plan' => 'starter', 'status' => 'active' ),
				'sites'        => array( 'used' => 1, 'max' => 3 ),
				'quota'        => array( 'monthly_remaining' => 80000, 'packs_remaining' => 250000, 'packs_usable' => true, 'total_remaining' => 330000 ),
				'subscription' => array( 'status' => 'active', 'current_period_end' => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW + 10 * DAY_IN_SECONDS ), 'cancel_at_period_end' => false ),
			),
			$overrides
		);
	}

	public function testFormatActiveSubscription(): void {
		$view = CloudStatus::format( $this->account(), self::NOW );

		$this->assertSame( 'Starter', $view['plan'] );
		$this->assertSame( 'Active', $view['subscription'] );
		$this->assertSame( 'Renews on ' . gmdate( (string) get_option( 'date_format', '' ) ?: 'F j, Y', self::NOW + 10 * DAY_IN_SECONDS ), $view['renewal'] );
		$this->assertSame( '80,000', $view['monthly_left'] );
		$this->assertSame( '250,000', $view['packs_left'] );
		$this->assertSame( '', $view['packs_note'] );
		$this->assertSame( '1 of 3', $view['sites'] );
		$this->assertTrue( $view['live'] );
	}

	public function testFormatCancelAtPeriodEndSaysEndsOn(): void {
		$view = CloudStatus::format( $this->account( array( 'subscription' => array( 'cancel_at_period_end' => true ) ) ), self::NOW );

		$this->assertStringStartsWith( 'Ends on ', $view['renewal'] );
		$this->assertTrue( $view['live'] );
	}

	public function testFormatPacksNotUsableAddsNote(): void {
		$view = CloudStatus::format( $this->account( array( 'quota' => array( 'packs_usable' => false ) ) ), self::NOW );

		$this->assertStringContainsString( 'only while the subscription is active', $view['packs_note'] );

		$none = CloudStatus::format( $this->account( array( 'quota' => array( 'packs_usable' => false, 'packs_remaining' => 0 ) ) ), self::NOW );
		$this->assertSame( '', $none['packs_note'] );
	}

	public function testFormatNoSubscriptionAndExpiredAndCanceled(): void {
		$none = CloudStatus::format(
			array(
				'account' => array( 'plan' => null ),
				'sites'   => array( 'used' => 0, 'max' => null ),
				'quota'   => array( 'monthly_remaining' => 0, 'packs_remaining' => 0 ),
				'subscription' => null,
			),
			self::NOW
		);

		$this->assertSame( 'No plan', $none['plan'] );
		$this->assertSame( 'No subscription', $none['subscription'] );
		$this->assertSame( '', $none['renewal'] );
		$this->assertSame( '0', $none['sites'] );
		$this->assertFalse( $none['live'] );

		$expired = CloudStatus::format( $this->account(), self::NOW + 11 * DAY_IN_SECONDS );
		$this->assertFalse( $expired['live'] );

		$canceled = CloudStatus::format( $this->account( array( 'subscription' => array( 'status' => 'canceled' ) ) ), self::NOW );
		$this->assertSame( 'Canceled', $canceled['subscription'] );
		$this->assertStringStartsWith( 'Ended on ', $canceled['renewal'] );
		$this->assertFalse( $canceled['live'] );

		$pastDue = CloudStatus::format( $this->account( array( 'subscription' => array( 'status' => 'past_due' ) ) ), self::NOW );
		$this->assertSame( 'Payment overdue', $pastDue['subscription'] );
		$this->assertFalse( $pastDue['live'] );
	}

	public function testGetCachesForSixtySecondsAndRefreshBypasses(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, $this->account() );
		CloudHttp::$queue[] = CloudHttp::json( 200, $this->account( array( 'quota' => array( 'monthly_remaining' => 5 ) ) ) );

		$status = $this->cloudStatus();
		$first  = $status->get();

		$this->assertSame( $first, $status->get() );
		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertSame( CloudStatus::CACHE_TTL, lingocanvas_test_http()['transient_ttl'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, CloudHttp::$requests[0]['args']['headers']['Authorization'] );

		$fresh = $status->get( true );
		$this->assertSame( '5', $fresh['monthly_left'] );
		$this->assertCount( 2, CloudHttp::$requests );
	}

	public function testCacheKeyIsPerSiteAndNeverContainsToken(): void {
		$key = $this->cloudStatus()->cacheKey();

		$this->assertStringStartsWith( 'lingocanvas_cloud_status_', $key );
		$this->assertStringNotContainsString( self::TOKEN, $key );
		$this->assertStringNotContainsString( 'site.example.test', $key );
	}

	public function testGetThrowsWithoutTokenAndForgetClearsCache(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, $this->account() );

		$status = $this->cloudStatus();
		$status->get();
		$status->forget();
		$this->assertFalse( get_transient( $status->cacheKey() ) );

		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );

		$this->expectException( CloudException::class );
		$this->cloudStatus()->get();
	}

	public function testApiErrorIsNotCached(): void {
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );

		$status = $this->cloudStatus();

		try {
			$status->get();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'invalid_token', $e->errorCode() );
		}

		$this->assertFalse( get_transient( $status->cacheKey() ) );
	}

	public function testGetWithoutConsentMakesNoRequestEvenWithConstantToken(): void {
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );

		$providers = new ProviderFactory(
			new Settings(),
			static fn( string $name ): ?string => ProviderFactory::CONST_CLOUD_TOKEN === $name ? 'lcc_constant_token_abcdef' : null,
			array()
		);

		try {
			( new CloudStatus( $providers ) )->get( true );
			$this->fail( 'Ожидалось исключение consent_required.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'consent_required', $e->errorCode() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	// --- Поля формы настроек ------------------------------------------------------------

	public function testPostedInputPassesProviderAndConsentFields(): void {
		$settings = new Settings();
		$page     = new SettingsPage( $settings, new LanguagePacks(), new ProviderFactory( $settings, static fn( string $name ): ?string => null ) );

		$_POST = array( 'provider' => 'cloud', 'cloud_consent' => '1', 'cloud_token' => 'must-not-pass', 'evil' => 'x' );

		$this->assertSame( array( 'provider' => 'cloud', 'cloud_consent' => '1' ), $page->postedInput() );
	}

	public function testSavingWithConsentUncheckedClearsConsentAndFallsBackToManual(): void {
		lingocanvas_test_options( array( Settings::optionName() => array_merge( Settings::defaults(), array( 'cloud_token' => self::TOKEN, 'provider' => 'cloud', 'cloud_consent_at' => 1700000000 ) ) ) );

		$settings = new Settings();
		$result   = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
				'provider'       => 'cloud',
				'cloud_consent'  => '0',
			)
		);

		$this->assertSame( 0, $result['settings']['cloud_consent_at'] );
		$this->assertSame( self::TOKEN, $result['settings']['cloud_token'] );

		$settings->save( $result['settings'] );
		$providers = new ProviderFactory( new Settings(), static fn( string $name ): ?string => null, array() );

		$this->assertSame( array( ProviderFactory::FIELD_CLOUD_CONSENT ), $providers->missing() );
		$this->assertInstanceOf( \LingoCanvas\Translation\ManualProvider::class, $providers->create() );
	}
}
