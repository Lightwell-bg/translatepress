<?php
/**
 * Тесты раздела LingoCanvas Cloud на странице настроек: обработчики admin-post и разметка.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use LingoCanvas\Admin\AllowedHtml;
use LingoCanvas\Admin\CloudActions;
use LingoCanvas\Admin\CloudSection;
use LingoCanvas\Admin\CloudStatus;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Tests\Doubles\CloudHttp;
use LingoCanvas\Tests\Doubles\Redirected;
use LingoCanvas\Translation\CloudClient;
use LingoCanvas\Translation\CloudException;
use LingoCanvas\Translation\ProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( CloudSection::class )]
#[CoversClass( CloudActions::class )]
final class CloudSectionTest extends TestCase {

	private const TOKEN = 'lcc_secret_token_value_123456';

	/** @var mixed */
	private $savedWpdb;

	protected function setUp(): void {
		$this->savedWpdb  = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \LingoCanvas\Tests\Doubles\FakeWpdb();
		CloudHttp::reset();
		lingocanvas_test_http( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );
		unset( $GLOBALS['lingocanvas_test_filters'] );
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->savedWpdb;
		CloudHttp::reset();
		lingocanvas_test_http( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_options( array() );
		unset( $GLOBALS['lingocanvas_test_filters'] );
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * @param array<string, mixed>  $overrides Настройки поверх значений по умолчанию.
	 * @param array<string, string> $constants Константы wp-config.php.
	 */
	private function section( array $overrides = array(), array $constants = array() ): CloudSection {
		// Ожидающая заявка живёт в отдельной опции: при пересборке настроек она сохраняется.
		$options = array( Settings::optionName() => array_merge( Settings::defaults(), $overrides ) );

		// Заявка, архив, замки: служебные опции Cloud при пересборке настроек сохраняются.
		foreach ( lingocanvas_test_options() as $name => $value ) {
			if ( str_starts_with( (string) $name, 'lingocanvas_cloud_' ) ) {
				$options[ $name ] = $value;
			}
		}

		lingocanvas_test_options( $options );

		$settings  = new Settings();
		$providers = new ProviderFactory( $settings, static fn( string $name ): ?string => $constants[ $name ] ?? null, array() );
		$actions   = new CloudActions( $providers, $settings, new CloudStatus( $providers ) );

		return new CloudSection( $settings, $providers, $actions );
	}

	/**
	 * Подставляет форму с верным nonce.
	 *
	 * @param string               $action Действие admin-post.
	 * @param array<string, mixed> $post   Остальные поля формы.
	 */
	private function submit( string $action, array $post = array() ): void {
		$_POST    = $post;
		$_REQUEST = array_merge( $post, array( '_wpnonce' => wp_create_nonce( $action ) ) );
	}

	/**
	 * Запускает обработчик и возвращает перехваченный редирект.
	 */
	private function redirectOf( callable $handler ): Redirected {
		try {
			$handler();
		} catch ( Redirected $redirect ) {
			return $redirect;
		}

		$this->fail( 'Ожидался редирект.' );
	}

	/**
	 * @return array{type: string, message: string}
	 */
	private function notice(): array {
		$notice = lingocanvas_test_http()['transients'][ CloudSection::NOTICE_PREFIX . '1' ] ?? null;

		$this->assertIsArray( $notice );

		return $notice;
	}

	private function checkoutResponse(): array {
		return CloudHttp::json( 200, array( 'checkout_url' => 'https://checkout.stripe.com/c/pay/abc' ) );
	}

	// --- Права и nonce ------------------------------------------------------------

	public function testEveryHandlerRequiresManageOptions(): void {
		$section = $this->section();

		lingocanvas_test_wp( array( 'caps' => array( 'manage_options' => false ) ) );

		foreach ( array( 'handleSignup', 'handleClaim', 'handleCancel', 'handleCheckout', 'handlePortal', 'handleDisconnect' ) as $method ) {
			try {
				$section->$method();
				$this->fail( "{$method} пропустил пользователя без права." );
			} catch ( \RuntimeException $e ) {
				$this->assertStringStartsWith( 'wp_die:403', $e->getMessage(), $method );
			}
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testEveryHandlerRequiresValidNonce(): void {
		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );

		$_POST    = array( 'cloud_consent' => '1' );
		$_REQUEST = array( '_wpnonce' => 'bad-nonce' );

		foreach ( array( 'handleSignup', 'handleClaim', 'handleCancel', 'handleCheckout', 'handlePortal', 'handleDisconnect' ) as $method ) {
			try {
				$section->$method();
				$this->fail( "{$method} принял неверный nonce." );
			} catch ( \RuntimeException $e ) {
				$this->assertStringStartsWith( 'wp_die:403', $e->getMessage(), $method );
			}
		}

		// Nonce от другого действия тоже не подходит.
		$this->submit( CloudSection::ACTION_PORTAL );

		try {
			$section->handleDisconnect();
			$this->fail( 'Чужой nonce принят.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'wp_die:403', $e->getMessage() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
		$this->assertSame( self::TOKEN, ( new Settings() )->cloudToken() );
	}

	// --- Подписка -----------------------------------------------------------------

	public function testSignupWithoutConsentIsRefusedBeforeAnyRequest(): void {
		$section = $this->section();
		$this->submit( CloudSection::ACTION_SIGNUP, array( 'cloud_email' => 'a@b.test' ) );

		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertStringContainsString( 'page=lingocanvas', $redirect->location );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringContainsString( 'agree', $this->notice()['message'] );
		$this->assertSame( array(), CloudHttp::$requests );
		$this->assertSame( 0, ( new Settings() )->cloudConsentAt() );
	}

	public function testSignupSavesConsentAndRedirectsToStripeOnly(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://checkout.stripe.com/c/pay/xyz',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		$section = $this->section();
		$this->submit( CloudSection::ACTION_SIGNUP, array( 'cloud_consent' => '1', 'cloud_email' => 'owner@example.test' ) );

		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/xyz', $redirect->location );
		$this->assertContains( 'checkout.stripe.com', $redirect->allowedHosts );
		$this->assertGreaterThan( 0, ( new Settings() )->cloudConsentAt() );
		$this->assertSame( 'lcc_claim_abc', get_option( CloudClient::CLAIM_OPTION )['claim_id'] );
		$this->assertSame( 'site.example.test', CloudHttp::$requests[0]['body']['site_name'] );
		$this->assertSame( 'owner@example.test', CloudHttp::$requests[0]['body']['email'] );
		$this->assertSame( '', ( new Settings() )->cloudToken() );
	}

	public function testSignupWithSavedConsentNeedsNoCheckbox(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://checkout.stripe.com/c/pay/xyz',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );

		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/xyz', $redirect->location );
		$this->assertSame( 1700000000, ( new Settings() )->cloudConsentAt() );
	}

	public function testSignupRejectsForeignCheckoutHostAndDoesNotAllowIt(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://evil.example.test/pay',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		$section = $this->section();
		$this->submit( CloudSection::ACTION_SIGNUP, array( 'cloud_consent' => '1' ) );

		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertStringContainsString( 'page=lingocanvas', $redirect->location );
		$this->assertNotContains( 'evil.example.test', $redirect->allowedHosts );
		$this->assertSame( CloudException::messageFor( 'bad_redirect' ), $this->notice()['message'] );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION, false ) );
	}

	public function testSignupApiErrorShowsHumanMessageNotServerText(): void {
		CloudHttp::$queue[] = CloudHttp::json( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'RAW SERVER TEXT' ) ) );

		$section = $this->section();
		$this->submit( CloudSection::ACTION_SIGNUP, array( 'cloud_consent' => '1' ) );

		$this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertSame( CloudException::messageFor( 'rate_limited' ), $this->notice()['message'] );
		$this->assertStringNotContainsString( 'RAW SERVER TEXT', $this->notice()['message'] );
	}

	public function testSignupRefusedWhenAlreadyConnectedOrWaitingOrBadEmail(): void {
		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$this->redirectOf( array( $section, 'handleSignup' ) );
		$this->assertStringContainsString( 'already connected', $this->notice()['message'] );

		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'c', 'secret' => 's', 'expires_at' => time() + 600 ), false );
		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$this->redirectOf( array( $section, 'handleSignup' ) );
		$this->assertStringContainsString( 'waiting', $this->notice()['message'] );

		delete_option( CloudClient::CLAIM_OPTION );
		$this->submit( CloudSection::ACTION_SIGNUP, array( 'cloud_email' => 'not-an-email' ) );
		$this->redirectOf( array( $section, 'handleSignup' ) );
		$this->assertStringContainsString( 'e-mail', $this->notice()['message'] );

		$this->assertSame( array(), CloudHttp::$requests );
	}

	// --- Оплата и портал ------------------------------------------------------------

	public function testCheckoutRedirectsToStripeWithOnlyThatHostAllowed(): void {
		CloudHttp::$queue[] = $this->checkoutResponse();

		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CHECKOUT, array( 'product' => 'pack_250k' ) );

		$redirect = $this->redirectOf( array( $section, 'handleCheckout' ) );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/abc', $redirect->location );
		$this->assertSame( array( 'example.test', 'checkout.stripe.com' ), $redirect->allowedHosts );
		$this->assertSame( 'pack_250k', CloudHttp::$requests[0]['body']['product'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, CloudHttp::$requests[0]['args']['headers']['Authorization'] );
	}

	public function testCheckoutUnknownProductIsRejectedWithoutRequest(): void {
		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CHECKOUT, array( 'product' => 'free_money' ) );

		$redirect = $this->redirectOf( array( $section, 'handleCheckout' ) );

		$this->assertStringContainsString( 'page=lingocanvas', $redirect->location );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertSame( array(), CloudHttp::$requests );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function checkoutErrors(): array {
		return array(
			'subscription_required' => array( 'subscription_required', 'active LingoCanvas Cloud subscription is required', 'pack_250k' ),
			'already_subscribed'    => array( 'already_subscribed', 'already has an active', 'starter' ),
			'no_billing_account'    => array( 'no_billing_account', 'no billing account', 'pack_250k' ),
			'billing_disabled'      => array( 'billing_disabled', 'Payments are temporarily unavailable', 'starter' ),
		);
	}

	/**
	 * @dataProvider checkoutErrors
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'checkoutErrors' )]
	public function testCheckoutApiErrorsGiveHumanMessages( string $code, string $fragment, string $product ): void {
		CloudHttp::$queue[] = CloudHttp::json( 409, array( 'error' => array( 'code' => $code, 'message' => 'RAW' ) ) );

		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CHECKOUT, array( 'product' => $product ) );

		$this->redirectOf( array( $section, 'handleCheckout' ) );

		$this->assertStringContainsString( $fragment, $this->notice()['message'] );
		$this->assertStringNotContainsString( 'RAW', $this->notice()['message'] );
	}

	public function testPortalRedirectsToBillingStripeAndReportsNotConfigured(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'url' => 'https://billing.stripe.com/p/session/abc' ) );

		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_PORTAL );

		$redirect = $this->redirectOf( array( $section, 'handlePortal' ) );
		$this->assertSame( 'https://billing.stripe.com/p/session/abc', $redirect->location );
		$this->assertContains( 'billing.stripe.com', $redirect->allowedHosts );

		CloudHttp::$queue[] = CloudHttp::json( 503, array( 'error' => array( 'code' => 'portal_not_configured', 'message' => 'x' ) ) );
		$this->submit( CloudSection::ACTION_PORTAL );
		$this->redirectOf( array( $section, 'handlePortal' ) );
		$this->assertStringContainsString( 'not available yet', $this->notice()['message'] );
	}

	// --- Заявка: проверка оплаты, отмена -----------------------------------------------

	public function testClaimPendingThenConnectedSavesTokenAndSelectsCloud(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CLAIM );
		$this->redirectOf( array( $section, 'handleClaim' ) );

		$this->assertSame( 'info', $this->notice()['type'] );
		$this->assertSame( '', ( new Settings() )->cloudToken() );

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => self::TOKEN, 'site_id' => 1, 'account_id' => 1 ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );
		$this->submit( CloudSection::ACTION_CLAIM );
		$this->redirectOf( array( $section, 'handleClaim' ) );

		$settings = new Settings();
		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertSame( self::TOKEN, $settings->cloudToken() );
		$this->assertSame( Settings::PROVIDER_CLOUD, $settings->provider() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION, false ) );
		$this->assertStringNotContainsString( self::TOKEN, (string) json_encode( $this->notice() ) );
	}

	public function testClaimPollRetriesAnUnconfirmedTokenWhenAlreadyConnected(): void {
		update_option( CloudClient::UNCONFIRMED_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600, 'received_at' => time() ), false );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CLAIM );
		$this->redirectOf( array( $section, 'handleClaim' ) );

		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertSame( 'Bearer ' . self::TOKEN, CloudHttp::$requests[0]['args']['headers']['Authorization'] );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION, false ) );
	}

	public function testSettingsPageLoadRetriesAnUnconfirmedTokenOnlyWithConsent(): void {
		update_option( CloudClient::UNCONFIRMED_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600, 'received_at' => time() ), false );

		$this->section( array( 'cloud_token' => self::TOKEN ) )->enqueue( 'toplevel_page_' . \LingoCanvas\Admin\SettingsPage::MENU_SLUG );
		$this->assertSame( array(), CloudHttp::$requests );
		$this->assertNotFalse( get_option( CloudClient::UNCONFIRMED_OPTION, false ) );

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );
		$this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) )->enqueue( 'toplevel_page_' . \LingoCanvas\Admin\SettingsPage::MENU_SLUG );
		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION, false ) );
	}

	public function testClaimWithoutPendingClaimWhenAlreadyConnectedIsConnected(): void {
		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CLAIM );
		$this->redirectOf( array( $section, 'handleClaim' ) );

		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testClaimExpiredShowsHumanError(): void {
		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CLAIM );
		$this->redirectOf( array( $section, 'handleClaim' ) );

		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertSame( CloudException::messageFor( 'claim_expired' ), $this->notice()['message'] );
	}

	public function testCancelWithoutConsentArchivesClaimWithoutAnyRequest(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'c', 'secret' => 's', 'expires_at' => time() + 600 ), false );

		$section = $this->section();
		$this->submit( CloudSection::ACTION_CANCEL );
		$this->redirectOf( array( $section, 'handleCancel' ) );

		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION, false ) );
		$this->assertSame( array( 'c' ), array_column( get_option( CloudClient::ARCHIVE_OPTION ), 'claim_id' ) );
		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testCancelChecksPaymentOnceThenArchivesAndAllowsCheckAgain(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CANCEL );
		$this->redirectOf( array( $section, 'handleCancel' ) );

		// Ровно одна проверка оплаты; заявка не удалена, а лежит в архиве.
		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertSame( '/v1/billing/claim', (string) wp_parse_url( CloudHttp::$requests[0]['url'], PHP_URL_PATH ) );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION, false ) );
		$this->assertSame( array( 'lcc_claim_abc' ), array_column( get_option( CloudClient::ARCHIVE_OPTION ), 'claim_id' ) );
		$this->assertSame( 'info', $this->notice()['type'] );

		// В состоянии «нет заявки» появилась кнопка «Уже оплатили? Проверить ещё раз».
		$html = $section->cloudHtml();
		$this->assertStringContainsString( 'Already paid? Check again', $html );
		$this->assertStringContainsString( 'form="lingocanvas-cloud-form-claim-archived"', $html );
		$this->assertStringContainsString( CloudSection::ACTION_CLAIM_OLD, $section->formsHtml() );
		$this->assertCovered( $html );
		$this->assertCovered( $section->formsHtml() );

		// Оплата подтвердилась: проверка по архивной заявке подключает сайт.
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => self::TOKEN, 'site_id' => 1, 'account_id' => 1 ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );
		$this->submit( CloudSection::ACTION_CLAIM_OLD );
		$this->redirectOf( array( $section, 'handleClaimArchived' ) );

		$settings = new Settings();
		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertSame( self::TOKEN, $settings->cloudToken() );
		$this->assertSame( Settings::PROVIDER_CLOUD, $settings->provider() );
		$this->assertFalse( get_option( CloudClient::ARCHIVE_OPTION, false ) );
	}

	public function testCancelConnectsIfPaymentAlreadyConfirmed(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => self::TOKEN, 'site_id' => 1, 'account_id' => 1 ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_CANCEL );
		$this->redirectOf( array( $section, 'handleCancel' ) );

		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertSame( self::TOKEN, ( new Settings() )->cloudToken() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION, false ) );
		$this->assertFalse( get_option( CloudClient::ARCHIVE_OPTION, false ) );
	}

	public function testCheckAgainButtonNeedsConsentAndAnUnexpiredArchive(): void {
		update_option( CloudClient::ARCHIVE_OPTION, array( array( 'claim_id' => 'old', 'secret' => 's', 'expires_at' => time() - 5 * DAY_IN_SECONDS ) ), false );
		$this->assertStringNotContainsString( 'Already paid?', $this->section( array( 'cloud_consent_at' => 1700000000 ) )->cloudHtml() );

		update_option( CloudClient::ARCHIVE_OPTION, array( array( 'claim_id' => 'new', 'secret' => 's', 'expires_at' => time() + 500 ) ), false );
		$this->assertStringNotContainsString( 'Already paid?', $this->section()->cloudHtml() );

		// Без согласия обработчик отказывает и к Cloud не обращается.
		$section = $this->section();
		$this->submit( CloudSection::ACTION_CLAIM_OLD );
		$this->redirectOf( array( $section, 'handleClaimArchived' ) );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testCancelButtonWarnsAboutPaidPayments(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'c', 'secret' => 's', 'expires_at' => time() + 600 ), false );

		$html = $this->section( array( 'cloud_consent_at' => 1700000000 ) )->cloudHtml();

		$this->assertStringContainsString( 'If you have already paid, do not cancel', $html );
		$this->assertStringContainsString( 'data-lingocanvas-confirm=', $html );
	}

	// --- Согласие и кнопки оплаты ----------------------------------------------------------------

	public function testBillingButtonsNeedConsentAndMakeNoRequest(): void {
		$section = $this->section( array( 'cloud_token' => self::TOKEN ) );

		$this->submit( CloudSection::ACTION_PORTAL );
		$this->redirectOf( array( $section, 'handlePortal' ) );
		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringContainsString( 'agree', $this->notice()['message'] );

		$this->submit( CloudSection::ACTION_CHECKOUT, array( 'product' => 'pack_250k' ) );
		$this->redirectOf( array( $section, 'handleCheckout' ) );
		$this->assertStringContainsString( 'agree', $this->notice()['message'] );

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testConnectedWithoutConsentShowsReasonAndScriptGetsConsentFlag(): void {
		$html = $this->section( array( 'cloud_token' => self::TOKEN ) )->cloudHtml();

		$this->assertStringContainsString( 'Status is not loaded', $html );
		$this->assertStringNotContainsString( 'Loading status', $html );
	}

	// --- Параллельная регистрация ------------------------------------------------------------------

	public function testSecondConcurrentSignupWithoutClaimIsToldToWaitAndMakesNoRequest(): void {
		update_option( \LingoCanvas\Admin\CloudActions::SIGNUP_LOCK_OPTION, time() + 100, false );

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertStringContainsString( 'page=lingocanvas', $redirect->location );
		$this->assertStringContainsString( 'already in progress', $this->notice()['message'] );
		$this->assertSame( array(), CloudHttp::$requests );
		// Чужой замок не снимается.
		$this->assertNotFalse( get_option( \LingoCanvas\Admin\CloudActions::SIGNUP_LOCK_OPTION, false ) );
	}

	public function testStaleSignupLockIsTreatedAsFreeAndReleasedAfterwards(): void {
		update_option( \LingoCanvas\Admin\CloudActions::SIGNUP_LOCK_OPTION, time() - 1, false );
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://checkout.stripe.com/c/pay/xyz',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/xyz', $redirect->location );
		$this->assertFalse( get_option( \LingoCanvas\Admin\CloudActions::SIGNUP_LOCK_OPTION, false ) );
	}

	public function testSignupLockIsReleasedAfterApiError(): void {
		CloudHttp::$queue[] = CloudHttp::json( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'x' ) ) );

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertFalse( get_option( \LingoCanvas\Admin\CloudActions::SIGNUP_LOCK_OPTION, false ) );
	}

	public function testSecondSignupWithPendingClaimReusesSameCheckoutUrl(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://checkout.stripe.com/c/pay/xyz',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$first = $this->redirectOf( array( $section, 'handleSignup' ) );

		// Второй клик (вторая вкладка): новой заявки нет, ведём на тот же платёж.
		$this->submit( CloudSection::ACTION_SIGNUP );
		$second = $this->redirectOf( array( $this->section( array( 'cloud_consent_at' => 1700000000 ) ), 'handleSignup' ) );

		$this->assertSame( $first->location, $second->location );
		$this->assertContains( 'checkout.stripe.com', $second->allowedHosts );
		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertSame( 'lcc_claim_abc', get_option( CloudClient::CLAIM_OPTION )['claim_id'] );
	}

	public function testExpiredClaimLetsSignupStartANewOne(): void {
		update_option(
			CloudClient::CLAIM_OPTION,
			array( 'claim_id' => 'old', 'secret' => 's', 'expires_at' => time() - 5, 'checkout_url' => 'https://checkout.stripe.com/c/pay/old' ),
			false
		);
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_new',
				'checkout_url' => 'https://checkout.stripe.com/c/pay/new',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$this->submit( CloudSection::ACTION_SIGNUP );
		$redirect = $this->redirectOf( array( $section, 'handleSignup' ) );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/new', $redirect->location );
		$this->assertCount( 1, CloudHttp::$requests );
	}

	// --- Отключение -----------------------------------------------------------------

	public function testDisconnectRemovesTokenResetsProviderAndKeepsEverythingElse(): void {
		$languages = array( 'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ) );
		$section   = $this->section(
			array(
				'cloud_token'      => self::TOKEN,
				'provider'         => Settings::PROVIDER_CLOUD,
				'cloud_consent_at' => 1700000000,
				'languages'        => $languages,
			)
		);

		$this->submit( CloudSection::ACTION_DISCONNECT );
		$this->redirectOf( array( $section, 'handleDisconnect' ) );

		$settings = new Settings();
		$raw      = $settings->raw();

		$this->assertSame( '', $settings->cloudToken() );
		$this->assertSame( Settings::PROVIDER_OPENAI, $settings->provider() );
		$this->assertSame( $languages, $raw['languages'] );
		$this->assertSame( 1700000000, $settings->cloudConsentAt() );
		$this->assertSame( 'success', $this->notice()['type'] );
		$this->assertStringContainsString( 'not canceled', $this->notice()['message'] );
		// Подписка не отменяется: ни одного обращения к Cloud.
		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testDisconnectIsImpossibleForConstantToken(): void {
		$section = $this->section(
			array( 'cloud_token' => self::TOKEN, 'provider' => Settings::PROVIDER_CLOUD ),
			array( ProviderFactory::CONST_CLOUD_TOKEN => 'lcc_constant_token_abcdef' )
		);

		$this->submit( CloudSection::ACTION_DISCONNECT );
		$this->redirectOf( array( $section, 'handleDisconnect' ) );

		$this->assertSame( 'error', $this->notice()['type'] );
		$this->assertStringContainsString( 'wp-config.php', $this->notice()['message'] );
		$this->assertSame( self::TOKEN, ( new Settings() )->cloudToken() );
		$this->assertSame( Settings::PROVIDER_CLOUD, ( new Settings() )->provider() );
	}

	// --- Сообщение -------------------------------------------------------------------

	public function testNoticeIsShownOnceAndEscaped(): void {
		set_transient( CloudSection::NOTICE_PREFIX . '1', array( 'type' => 'evil" onload="x', 'message' => '<script>alert(1)</script>' ), 60 );

		$section = $this->section();

		ob_start();
		$section->renderNotice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );

		ob_start();
		$section->renderNotice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	// --- Разметка ----------------------------------------------------------------------

	/**
	 * Все теги и атрибуты разметки входят в список AllowedHtml::cloudSection().
	 */
	private function assertCovered( string $html ): void {
		$allowed = AllowedHtml::cloudSection();

		$this->assertNotSame( '', $html );

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		foreach ( $dom->getElementsByTagName( '*' ) as $element ) {
			$tag = strtolower( $element->tagName );

			if ( 'html' === $tag || 'body' === $tag ) {
				continue;
			}

			$this->assertArrayHasKey( $tag, $allowed, "Тег <{$tag}> не разрешён." );

			foreach ( $element->attributes ?? array() as $attribute ) {
				$this->assertArrayHasKey( $attribute->name, $allowed[ $tag ], "Атрибут {$attribute->name} у <{$tag}> не разрешён." );
			}
		}
	}

	/**
	 * @return array<string, array{array<string, mixed>, array<string, string>, bool}>
	 */
	public static function states(): array {
		return array(
			'signup, no consent'  => array( array(), array(), false ),
			'signup, consent'     => array( array( 'cloud_consent_at' => 1700000000 ), array(), false ),
			'pending'             => array( array( 'cloud_consent_at' => 1700000000 ), array(), true ),
			'connected'           => array( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000, 'provider' => 'cloud' ), array(), false ),
			'connected, no consent' => array( array( 'cloud_token' => self::TOKEN ), array(), false ),
			'token constant'      => array( array(), array( ProviderFactory::CONST_CLOUD_TOKEN => 'lcc_constant_token_abcdef' ), false ),
		);
	}

	/**
	 * @dataProvider states
	 * @param array<string, mixed>  $overrides Настройки.
	 * @param array<string, string> $constants Константы.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'states' )]
	public function testMarkupIsCoveredByAllowedHtmlAndNeverContainsToken( array $overrides, array $constants, bool $pending ): void {
		if ( $pending ) {
			update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'lcc_claim_secret_xyz', 'expires_at' => time() + 600 ), false );
		}

		$section = $this->section( $overrides, $constants );
		$all     = $section->providerHtml() . $section->cloudHtml() . $section->formsHtml();

		$this->assertCovered( $section->providerHtml() );
		$this->assertCovered( $section->cloudHtml() );
		$this->assertCovered( $section->formsHtml() );

		$this->assertStringNotContainsString( self::TOKEN, $all );
		$this->assertStringNotContainsString( 'lcc_constant_token_abcdef', $all );
		$this->assertStringNotContainsString( 'lcc_claim_secret_xyz', $all );
		$this->assertStringContainsString( 'centerai.eu/lingocanvas/terms/', $all );
		$this->assertStringContainsString( 'centerai.eu/lingocanvas/privacy/', $all );
		$this->assertStringContainsString( 'DeepSeek', $all );
		$this->assertStringContainsString( 'China', $all );
	}

	public function testSignupStateShowsConsentWithSignupFormWhenNoConsent(): void {
		$html = $this->section()->cloudHtml();

		$this->assertStringContainsString( 'Subscribe — Starter, 9 € / month (100,000 characters)', $html );
		$this->assertStringContainsString( 'form="lingocanvas-cloud-form-signup"', $html );
		$this->assertStringContainsString( 'required="required"', $html );
		// Одна галочка согласия, и она не в главной форме настроек.
		$this->assertSame( 1, substr_count( $html, 'name="cloud_consent"' ) );
		$this->assertStringContainsString( 'name="cloud_consent" value="1" required="required" form=', $html );
	}

	public function testConsentCheckboxLivesInMainFormOnceConsentIsGiven(): void {
		$html = $this->section( array( 'cloud_consent_at' => 1700000000 ) )->cloudHtml();

		// Скрытое «0» + галочка: снятие галочки и «Сохранить» обнуляют согласие.
		$this->assertSame( 2, substr_count( $html, 'name="cloud_consent"' ) );
		$this->assertStringContainsString( 'checked="checked"', $html );
		$this->assertStringNotContainsString( 'required="required"', $html );
	}

	public function testConnectedStateOffersActionsAndExplainsDisconnect(): void {
		$section = $this->section( array( 'cloud_token' => self::TOKEN, 'cloud_consent_at' => 1700000000 ) );
		$html    = $section->cloudHtml();
		$forms   = $section->formsHtml();

		$this->assertStringContainsString( 'Buy 250,000 characters — 7 €', $html );
		$this->assertStringContainsString( 'Manage subscription', $html );
		$this->assertStringContainsString( 'Subscribe again', $html );
		$this->assertStringContainsString( 'data-lingocanvas-confirm=', $html );
		$this->assertStringContainsString( 'NOT canceled', $html );
		$this->assertStringContainsString( 'value="pack_250k"', $forms );
		$this->assertStringContainsString( 'value="starter"', $forms );
		$this->assertStringContainsString( 'value="' . CloudSection::ACTION_DISCONNECT . '"', $forms );
		$this->assertStringContainsString( wp_create_nonce( CloudSection::ACTION_DISCONNECT ), $forms );
	}

	public function testConstantTokenIsShownAsWpConfigAndHasNoDisconnect(): void {
		$section = $this->section( array(), array( ProviderFactory::CONST_CLOUD_TOKEN => 'lcc_constant_token_abcdef' ) );
		$html    = $section->cloudHtml();

		$this->assertStringContainsString( 'wp-config.php', $html );
		$this->assertStringNotContainsString( 'data-lingocanvas-confirm', $html );
		$this->assertStringNotContainsString( CloudSection::ACTION_DISCONNECT, $section->formsHtml() );
	}

	public function testPendingStateOffersCheckAndCancel(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'c', 'secret' => 's', 'expires_at' => time() + 600 ), false );

		$section = $this->section( array( 'cloud_consent_at' => 1700000000 ) );
		$html    = $section->cloudHtml();

		$this->assertStringContainsString( 'Waiting for payment confirmation', $html );
		$this->assertStringContainsString( 'Check payment', $html );
		$this->assertStringContainsString( 'Cancel', $html );
		$this->assertStringContainsString( CloudSection::ACTION_CLAIM, $section->formsHtml() );
		$this->assertStringContainsString( CloudSection::ACTION_CANCEL, $section->formsHtml() );
	}

	public function testProviderRadioReflectsSavedChoice(): void {
		$cloud = $this->section( array( 'provider' => Settings::PROVIDER_CLOUD ) )->providerHtml();
		$own   = $this->section()->providerHtml();

		$this->assertMatchesRegularExpression( '/value="cloud" checked="checked"/', $cloud );
		$this->assertMatchesRegularExpression( '/value="openai" checked="checked"/', $own );
		$this->assertStringContainsString( 'Own API key (OpenAI-compatible)', $own );
		$this->assertStringContainsString( 'LingoCanvas Cloud (paid service by CenterAI)', $own );
	}
}
