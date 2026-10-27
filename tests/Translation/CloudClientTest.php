<?php
/**
 * Тесты клиента LingoCanvas Cloud (сеть подменена двойником CloudHttp).
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Translation;

use LingoCanvas\Settings\Settings;
use LingoCanvas\Tests\Doubles\CloudHttp;
use LingoCanvas\Tests\Doubles\FakeWpdb;
use LingoCanvas\Translation\CloudClient;
use LingoCanvas\Translation\CloudException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( CloudClient::class )]
#[CoversClass( CloudException::class )]
final class CloudClientTest extends TestCase {

	private const BASE = 'https://cloud.example.test';

	/** @var mixed */
	private $savedWpdb;

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		$this->savedWpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		CloudHttp::reset();
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->savedWpdb;
		CloudHttp::reset();
		lingocanvas_test_options( array() );
	}

	private function client( string $token = 'lcc_testtoken123' ): CloudClient {
		return new CloudClient( self::BASE, $token, new Settings() );
	}

	private function signupResponse(): array {
		return CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://checkout.stripe.com/c/pay/xyz',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);
	}

	public function testSignupStoresClaimAndSendsOnlyHashOfSecret(): void {
		CloudHttp::$queue[] = $this->signupResponse();

		$result = $this->client( '' )->signup( 'example.com', 'a@b.test' );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/xyz', $result['checkout_url'] );

		$claim = get_option( CloudClient::CLAIM_OPTION );
		$this->assertSame( 'lcc_claim_abc', $claim['claim_id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $claim['secret'] );

		$request = CloudHttp::$requests[0];
		$this->assertSame( self::BASE . '/v1/billing/signup', $request['url'] );
		$this->assertSame( hash( 'sha256', $claim['secret'] ), $request['body']['claim_secret_sha256'] );
		$this->assertStringNotContainsString( $claim['secret'], (string) $request['args']['body'] );
		$this->assertSame( 'example.com', $request['body']['site_name'] );
		$this->assertSame( 'a@b.test', $request['body']['email'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
	}

	public function testSignupRejectsForeignCheckoutUrlAndStoresNothing(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			200,
			array(
				'claim_id'     => 'lcc_claim_abc',
				'checkout_url' => 'https://evil.example.test/pay',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			)
		);

		try {
			$this->client()->signup( 'example.com' );
			$this->fail( 'Ожидалось исключение bad_redirect.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'bad_redirect', $e->errorCode() );
		}

		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testClaimPendingKeepsClaim(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );
		$secret = get_option( CloudClient::CLAIM_OPTION )['secret'];

		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );

		$this->assertSame( array( 'status' => 'pending' ), $this->client()->claim() );
		$this->assertNotFalse( get_option( CloudClient::CLAIM_OPTION ) );

		$sent = CloudHttp::$requests[1];
		$this->assertSame( self::BASE . '/v1/billing/claim', $sent['url'] );
		$this->assertSame( $secret, $sent['body']['claim_secret'] );
		$this->assertSame( 'lcc_claim_abc', $sent['body']['claim_id'] );
		$this->assertArrayNotHasKey( 'Authorization', $sent['args']['headers'] );
	}

	public function testClaimSuccessSavesTokenAndDeletesClaim(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_newtoken_999', 'site_id' => 1, 'account_id' => 2, 'recovered' => false ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$this->assertSame( array( 'status' => 'connected' ), $this->client()->claim() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
		$this->assertFalse( get_option( CloudClient::CLAIM_LOCK_OPTION ) );
		$this->assertSame( 'lcc_newtoken_999', ( new Settings() )->cloudToken() );
		// Остальные настройки не потеряны.
		$this->assertArrayHasKey( 'ru', ( new Settings() )->all() );

		// Подтверждение: новый токен в заголовке, в теле только номер заявки, таймаут 20 секунд.
		$confirm = CloudHttp::$requests[2];
		$this->assertSame( self::BASE . '/v1/billing/claim/confirm', $confirm['url'] );
		$this->assertSame( 'Bearer lcc_newtoken_999', $confirm['args']['headers']['Authorization'] );
		$this->assertSame( array( 'claim_id' => 'lcc_claim_abc' ), $confirm['body'] );
		$this->assertSame( 20, $confirm['args']['timeout'] );
	}

	/**
	 * Сохраняет заявку так, как это делает signup(), и возвращает её секрет.
	 */
	private function storeClaim(): string {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client( '' )->signup( 'example.com' );

		return (string) get_option( CloudClient::CLAIM_OPTION )['secret'];
	}

	public function testLostFirstResponseIsRecoveredByTheNextPoll(): void {
		$secret = $this->storeClaim();

		// Сервер выдал токен, но ответ потерялся (таймаут).
		CloudHttp::$queue[] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30000 ms' );
		try {
			$this->client( '' )->claim();
			$this->fail( 'Ожидался таймаут.' );
		} catch ( CloudException $e ) {
			$this->assertSame( CloudException::TIMEOUT, $e->errorCode() );
		}
		$this->assertSame( 'lcc_claim_abc', get_option( CloudClient::CLAIM_OPTION )['claim_id'] );
		$this->assertSame( '', ( new Settings() )->cloudToken() );
		$this->assertFalse( get_option( CloudClient::CLAIM_LOCK_OPTION ) );

		// Следующий опрос получает ротированный токен, подтверждает его и удаляет заявку.
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_recovered_1', 'site_id' => 1, 'account_id' => 1, 'recovered' => true ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claim() );
		$this->assertSame( 'lcc_recovered_1', ( new Settings() )->cloudToken() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
		$this->assertSame( $secret, CloudHttp::$requests[2]['body']['claim_secret'] );
		$this->assertSame( 'Bearer lcc_recovered_1', CloudHttp::$requests[3]['args']['headers']['Authorization'] );
	}

	public function testRecoveredTokenOfArchivedClaimDeletesTheArchiveEntry(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_old', 'secret' => 'secretold', 'expires_at' => time() + 600 ), false );
		$this->client()->archivePendingClaim();

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_late_token_2', 'site_id' => 1, 'account_id' => 1, 'recovered' => true ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claimArchived() );
		$this->assertSame( array(), $this->client()->archivedClaims() );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
	}

	public function testConfirmFailureKeepsClaimAndRetriesLater(): void {
		$secret = $this->storeClaim();

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_tok_keep_1', 'site_id' => 1, 'account_id' => 1, 'recovered' => false ) );
		CloudHttp::$queue[] = CloudHttp::error( 500, 'internal_error' );

		// Сайт подключён, но секрет заявки сохранён до успешного подтверждения.
		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claim() );
		$this->assertSame( 'lcc_tok_keep_1', ( new Settings() )->cloudToken() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
		$kept = get_option( CloudClient::UNCONFIRMED_OPTION );
		$this->assertSame( 'lcc_claim_abc', $kept['claim_id'] );
		$this->assertSame( $secret, $kept['secret'] );
		$this->assertNull( $this->client( 'lcc_tok_keep_1' )->pendingClaim() );
		$this->assertSame( 'no', $GLOBALS['lingocanvas_test_autoload'][ CloudClient::UNCONFIRMED_OPTION ] ?? 'missing' );

		// Сеть недоступна: заявка остаётся.
		CloudHttp::$queue[] = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		$this->client( 'lcc_tok_keep_1' )->retryPendingConfirm();
		$this->assertNotFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );

		// Повтор проходит: заявка удаляется; секрет в подтверждение не уходит.
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );
		$this->client( 'lcc_tok_keep_1' )->retryPendingConfirm();
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );

		foreach ( array_slice( CloudHttp::$requests, 2 ) as $request ) {
			$this->assertSame( '/v1/billing/claim/confirm', substr( $request['url'], strlen( self::BASE ) ) );
			$this->assertArrayNotHasKey( 'claim_secret', $request['body'] );
			$this->assertStringNotContainsString( $secret, (string) $request['args']['body'] );
			$this->assertStringNotContainsString( $secret, json_encode( $request['args']['headers'] ) );
		}

		// Больше нечего подтверждать: лишних запросов нет.
		$count = count( CloudHttp::$requests );
		$this->client( 'lcc_tok_keep_1' )->retryPendingConfirm();
		$this->assertCount( $count, CloudHttp::$requests );
	}

	public function testRetryWithoutLocalTokenSendsNothing(): void {
		update_option( CloudClient::UNCONFIRMED_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sss', 'expires_at' => time() + 600, 'received_at' => time() ), false );

		$this->client( '' )->retryPendingConfirm();

		$this->assertSame( array(), CloudHttp::$requests );
		$this->assertNotFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
	}

	public function testUnconfirmedClaimIsDroppedAfterRecoveryWindowPlusGrace(): void {
		$old = time() - 4 * DAY_IN_SECONDS;
		update_option( CloudClient::UNCONFIRMED_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sss', 'expires_at' => $old, 'received_at' => $old ), false );
		( new Settings() )->saveCloud( array( 'cloud_token' => 'lcc_tok_keep_1' ) );

		$this->client( 'lcc_tok_keep_1' )->retryPendingConfirm();

		$this->assertSame( array(), CloudHttp::$requests );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
	}

	public function testConfirm401RotatedTokenReclaimsOnceThenConfirms(): void {
		$secret = $this->storeClaim();

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_tok_first_1', 'site_id' => 1, 'account_id' => 1, 'recovered' => false ) );
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_tok_second_2', 'site_id' => 1, 'account_id' => 1, 'recovered' => true ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claim() );
		$this->assertSame( 'lcc_tok_second_2', ( new Settings() )->cloudToken() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );

		$urls = array_map( static fn( array $r ): string => substr( $r['url'], strlen( self::BASE ) ), array_slice( CloudHttp::$requests, 1 ) );
		$this->assertSame( array( '/v1/billing/claim', '/v1/billing/claim/confirm', '/v1/billing/claim', '/v1/billing/claim/confirm' ), $urls );
		$this->assertSame( 'Bearer lcc_tok_second_2', CloudHttp::$requests[4]['args']['headers']['Authorization'] );
		$this->assertSame( $secret, CloudHttp::$requests[3]['body']['claim_secret'] );
	}

	public function testConfirm401TwiceDoesNotLoopAndKeepsClaim(): void {
		$this->storeClaim();

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_tok_first_1', 'site_id' => 1, 'account_id' => 1, 'recovered' => false ) );
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_tok_second_2', 'site_id' => 1, 'account_id' => 1, 'recovered' => true ) );
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );

		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claim() );
		$this->assertCount( 5, CloudHttp::$requests );
		$this->assertNotFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
	}

	public function testParallelPollerIsBlockedByTheLock(): void {
		$this->storeClaim();
		add_option( CloudClient::CLAIM_LOCK_OPTION, time() + 60, '', false );

		$this->assertSame( array( 'status' => 'pending' ), $this->client( '' )->claim() );

		$this->assertCount( 1, CloudHttp::$requests ); // только signup
		$this->assertNotFalse( get_option( CloudClient::CLAIM_LOCK_OPTION ) ); // чужой замок не снят
		$this->assertNotFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testStaleLockIsTakenOver(): void {
		$this->storeClaim();
		add_option( CloudClient::CLAIM_LOCK_OPTION, time() - 5, '', false );
		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );

		$this->assertSame( array( 'status' => 'pending' ), $this->client( '' )->claim() );

		$this->assertCount( 2, CloudHttp::$requests );
		$this->assertFalse( get_option( CloudClient::CLAIM_LOCK_OPTION ) );
	}

	public function testLockIsReleasedAfterAnError(): void {
		$this->storeClaim();
		CloudHttp::$queue[] = CloudHttp::error( 410, 'claim_expired' );

		try {
			$this->client( '' )->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'claim_expired', $e->errorCode() );
		}

		$this->assertFalse( get_option( CloudClient::CLAIM_LOCK_OPTION ) );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function terminalClaimErrors(): array {
		return array(
			'wrong secret' => array( 404, 'claim_not_found' ),
			'expired'      => array( 410, 'claim_expired' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'terminalClaimErrors' )]
	public function testClaimTerminalErrorsDeleteClaim( int $status, string $code ): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );

		CloudHttp::$queue[] = CloudHttp::error( $status, $code );

		try {
			$this->client()->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( $code, $e->errorCode() );
		}

		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testAlreadyClaimedWithoutLocalTokenKeepsClaimAndReportsClaimIdOnly(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );
		$secret = get_option( CloudClient::CLAIM_OPTION )['secret'];

		CloudHttp::$queue[] = CloudHttp::error( 409, 'already_claimed' );

		try {
			$this->client()->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'already_claimed_lost', $e->errorCode() );
			$this->assertStringContainsString( 'lcc_claim_abc', $e->userMessage() );
			$this->assertStringContainsString( 'Contact support', $e->userMessage() );
			$this->assertStringNotContainsString( $secret, $e->userMessage() );
		}

		// Заявка остаётся: по номеру поддержка сможет разобраться.
		$this->assertSame( 'lcc_claim_abc', get_option( CloudClient::CLAIM_OPTION )['claim_id'] );
	}

	public function testAlreadyClaimedWithLocalTokenDeletesClaim(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );
		( new Settings() )->saveCloud( array( 'cloud_token' => 'lcc_alreadyhere_1' ) );

		CloudHttp::$queue[] = CloudHttp::error( 409, 'already_claimed' );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'account' => array( 'id' => 1 ) ) );

		try {
			$this->client()->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'already_claimed', $e->errorCode() );
		}

		// Заявка удалена только потому, что Cloud принял локальный токен.
		$this->assertSame( 'Bearer lcc_alreadyhere_1', CloudHttp::$requests[2]['args']['headers']['Authorization'] );
		$this->assertSame( '/v1/account', substr( CloudHttp::$requests[2]['url'], strlen( self::BASE ) ) );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testAlreadyClaimedWithRejectedLocalTokenKeepsClaimAsLost(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );
		( new Settings() )->saveCloud( array( 'cloud_token' => 'lcc_stale_local_1' ) );

		CloudHttp::$queue[] = CloudHttp::error( 409, 'already_claimed' );
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );

		try {
			$this->client()->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'already_claimed_lost', $e->errorCode() );
		}

		$this->assertSame( 'lcc_claim_abc', get_option( CloudClient::CLAIM_OPTION )['claim_id'] );
	}

	public function testClaimNotExpiredLocallyIsKeptAfterOriginalExpiryWhenResponseWasLost(): void {
		// Срок заявки вышел на часы назад, но ответ с токеном мог потеряться: секрет жив до конца окна восстановления.
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec_abc', 'expires_at' => time() - 3 * HOUR_IN_SECONDS ), false );

		$this->assertNotNull( $this->client( '' )->pendingClaim() );

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_recovered_late', 'site_id' => 1, 'account_id' => 1, 'recovered' => true ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claim() );
		$this->assertSame( 'lcc_recovered_late', ( new Settings() )->cloudToken() );
		$this->assertSame( 'sec_abc', CloudHttp::$requests[0]['body']['claim_secret'] );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
	}

	public function testLateClaimExpiredAnswerDeletesTheClaim(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec_abc', 'expires_at' => time() - 3 * HOUR_IN_SECONDS ), false );
		CloudHttp::$queue[] = CloudHttp::error( 410, 'claim_expired' );

		try {
			$this->client( '' )->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'claim_expired', $e->errorCode() );
		}

		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testOtherConflictErrorsKeepTheClaim(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_abc', 'secret' => 'sec_abc', 'expires_at' => time() + 600 ), false );
		CloudHttp::$queue[] = CloudHttp::error( 409, 'site_revoked' );

		try {
			$this->client( '' )->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'site_revoked', $e->errorCode() );
		}

		$this->assertNotFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testExpiredUnconfirmedAndArchivedClaimsStayRecoverableInsideRetention(): void {
		$expired = time() - 2 * DAY_IN_SECONDS; // 24 ч восстановления + 48 ч запаса ещё не вышли.
		update_option( CloudClient::ARCHIVE_OPTION, array( array( 'claim_id' => 'arch', 'secret' => 's', 'expires_at' => $expired ) ), false );

		$this->assertSame( array( 'arch' ), array_column( $this->client()->archivedClaims(), 'claim_id' ) );
	}

	public function testPrunedArchiveIsPersistedAndEmptyArchiveOptionIsDeleted(): void {
		update_option(
			CloudClient::ARCHIVE_OPTION,
			array(
				array( 'claim_id' => 'old', 'secret' => 's', 'expires_at' => time() - 10 * DAY_IN_SECONDS ),
				array( 'claim_id' => 'live', 'secret' => 's2', 'expires_at' => time() + 600 ),
			),
			false
		);

		$this->assertSame( array( 'live' ), array_column( $this->client()->archivedClaims(), 'claim_id' ) );
		$this->assertSame( array( 'live' ), array_column( get_option( CloudClient::ARCHIVE_OPTION ), 'claim_id' ) );

		update_option( CloudClient::ARCHIVE_OPTION, array( array( 'claim_id' => 'old', 'secret' => 's', 'expires_at' => time() - 10 * DAY_IN_SECONDS ) ), false );
		$this->assertSame( array(), $this->client()->archivedClaims() );
		$this->assertFalse( get_option( CloudClient::ARCHIVE_OPTION ) );
	}

	public function testResumableRecordAndTokenAreBothOnDiskWhenConfirmationIsSent(): void {
		$secret = $this->storeClaim();
		$seen   = array();

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_order_tok_1', 'site_id' => 1, 'account_id' => 1, 'recovered' => false ) );
		CloudHttp::$queue[] = static function () use ( &$seen ): array {
			$seen = array(
				'unconfirmed' => get_option( CloudClient::UNCONFIRMED_OPTION ),
				'token'       => ( new Settings() )->cloudToken(),
				'pending'     => get_option( CloudClient::CLAIM_OPTION ),
			);

			return CloudHttp::json( 200, array( 'status' => 'confirmed' ) );
		};

		$this->client( '' )->claim();

		$this->assertSame( $secret, $seen['unconfirmed']['secret'] );
		$this->assertSame( 'lcc_claim_abc', $seen['unconfirmed']['claim_id'] );
		$this->assertGreaterThan( 0, $seen['unconfirmed']['received_at'] );
		$this->assertSame( 'lcc_order_tok_1', $seen['token'] );
		$this->assertFalse( $seen['pending'] );
		$this->assertFalse( get_option( CloudClient::UNCONFIRMED_OPTION ) );
	}

	public function testConnectedBranchResumesConfirmationForAnyLeftoverRecord(): void {
		( new Settings() )->saveCloud( array( 'cloud_token' => 'lcc_have_token_1' ) );
		update_option( CloudClient::ARCHIVE_OPTION, array( array( 'claim_id' => 'arch', 'secret' => 's', 'expires_at' => time() + 600 ) ), false );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );

		$this->client( 'lcc_have_token_1' )->retryPendingConfirm();

		$this->assertSame( '/v1/billing/claim/confirm', substr( CloudHttp::$requests[0]['url'], strlen( self::BASE ) ) );
		$this->assertSame( array( 'claim_id' => 'arch' ), CloudHttp::$requests[0]['body'] );
		$this->assertFalse( get_option( CloudClient::ARCHIVE_OPTION ) );
	}

	public function testAlreadyClaimedAfterConfirm401IsNotSuccess(): void {
		$this->storeClaim();

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_tok_first_1', 'site_id' => 1, 'account_id' => 1, 'recovered' => false ) );
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );
		CloudHttp::$queue[] = CloudHttp::error( 409, 'already_claimed' );

		try {
			$this->client( '' )->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'already_claimed_lost', $e->errorCode() );
			$this->assertStringContainsString( 'lcc_claim_abc', $e->userMessage() );
		}

		// Запись заявки с секретом остаётся для поддержки и повторов.
		$this->assertSame( 'lcc_claim_abc', get_option( CloudClient::UNCONFIRMED_OPTION )['claim_id'] );
	}

	public function testLockHasOwnerAndIsReleasedOnlyByTheOwner(): void {
		$this->storeClaim();
		$foreign = 'foreignowner00|' . ( time() + 600 );

		CloudHttp::$queue[] = static function () use ( $foreign ): array {
			// Пока идёт запрос, замок перехватил другой опрос.
			update_option( CloudClient::CLAIM_LOCK_OPTION, $foreign, false );

			return CloudHttp::json( 202, array( 'status' => 'pending' ) );
		};

		$this->client( '' )->claim();

		$this->assertSame( $foreign, get_option( CloudClient::CLAIM_LOCK_OPTION ) );
	}

	public function testLockValueContainsOwnerAndExpiryWhileHeld(): void {
		$this->storeClaim();
		$held = null;

		CloudHttp::$queue[] = static function () use ( &$held ): array {
			$held = get_option( CloudClient::CLAIM_LOCK_OPTION );

			return CloudHttp::json( 202, array( 'status' => 'pending' ) );
		};

		$this->client( '' )->claim();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}\|\d+$/', (string) $held );
		$this->assertFalse( get_option( CloudClient::CLAIM_LOCK_OPTION ) );
	}

	public function testStaleLockTakeoverLosesWhenAnotherPollerWinsTheSwap(): void {
		$this->storeClaim();
		$stale = 'deadowner000000|' . ( time() - 30 );
		$fresh = 'winnerowner0000|' . ( time() + 100 );
		add_option( CloudClient::CLAIM_LOCK_OPTION, $stale, '', false );

		// Между чтением просроченного замка и атомарной заменой замок уже занял другой опрос.
		$this->wpdb->beforeQuery = static function ( string $sql ) use ( $fresh ): void {
			if ( str_starts_with( $sql, 'UPDATE' ) ) {
				update_option( CloudClient::CLAIM_LOCK_OPTION, $fresh, false );
			}
		};

		$this->assertSame( array( 'status' => 'pending' ), $this->client( '' )->claim() );

		$this->assertCount( 1, CloudHttp::$requests ); // только signup, к Cloud опрос не ходил
		$this->assertSame( $fresh, get_option( CloudClient::CLAIM_LOCK_OPTION ) );
	}

	public function testStaleLockTakeoverUsesConditionalUpdate(): void {
		$this->storeClaim();
		add_option( CloudClient::CLAIM_LOCK_OPTION, 'deadowner000000|' . ( time() - 30 ), '', false );
		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );

		$this->client( '' )->claim();

		$this->assertStringStartsWith( 'UPDATE wp_options SET option_value =', $this->wpdb->queries[0] );
		$this->assertStringContainsString( "AND option_value = 'deadowner000000|", $this->wpdb->queries[0] );
		$this->assertStringStartsWith( 'DELETE FROM wp_options WHERE option_name =', $this->wpdb->queries[1] );
	}

	public function testPollerStartedBeforeANewerConfirmedTokenDoesNotOverwriteIt(): void {
		$this->storeClaim();

		// Ответ первого опроса задерживается; за это время сайт уже получил более новый токен.
		CloudHttp::$queue[] = static function (): array {
			( new Settings() )->saveCloud( array( 'cloud_token' => 'lcc_newer_confirmed' ) );

			return CloudHttp::json( 200, array( 'token' => 'lcc_older_delayed', 'site_id' => 1, 'account_id' => 1, 'recovered' => false ) );
		};

		$this->assertSame( array( 'status' => 'connected' ), $this->client( '' )->claim() );

		$this->assertSame( 'lcc_newer_confirmed', ( new Settings() )->cloudToken() );
		$this->assertCount( 2, CloudHttp::$requests ); // signup и claim: подтверждения старым токеном нет
	}

	public function testPollerRereadsStateAfterAcquiringTheLock(): void {
		$this->storeClaim();
		$client = $this->client( '' );

		// Другой опрос успел завершить заявку (токена нет, записи удалены) между чтением и замком.
		$this->wpdb->beforeQuery = static function ( string $sql ): void {
			unset( $sql );
			delete_option( CloudClient::CLAIM_OPTION );
		};
		add_option( CloudClient::CLAIM_LOCK_OPTION, 'deadowner000000|' . ( time() - 30 ), '', false );

		try {
			$client->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'claim_expired', $e->errorCode() );
		}

		$this->assertCount( 1, CloudHttp::$requests ); // к Cloud без записи заявки не ходили
	}

	public function testClaimUsesThirtySecondTimeout(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );
		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );

		$this->client()->claim();

		$this->assertSame( 30, CloudHttp::$requests[1]['args']['timeout'] );
	}

	public function testSignupStoresCheckoutUrlForReuse(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$client = $this->client();
		$client->signup( 'example.com' );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/xyz', $client->pendingCheckoutUrl() );
		$this->assertSame( array( 'claim_id', 'expires_at' ), array_keys( (array) $client->pendingClaim() ) );
	}

	public function testArchiveKeepsPendingClaimCheckableAndCapsAtThree(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			update_option(
				CloudClient::CLAIM_OPTION,
				array( 'claim_id' => "lcc_claim_$i", 'secret' => "secret$i", 'expires_at' => time() + 600 + $i, 'checkout_url' => 'https://checkout.stripe.com/c/pay/' . $i ),
				false
			);
			$this->client()->archivePendingClaim();
		}

		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
		$this->assertSame( array( 'lcc_claim_4', 'lcc_claim_3', 'lcc_claim_2' ), array_column( $this->client()->archivedClaims(), 'claim_id' ) );
		$this->assertArrayNotHasKey( 'secret', $this->client()->archivedClaims()[0] );
		$this->assertSame( 'no', $GLOBALS['lingocanvas_test_autoload'][ CloudClient::ARCHIVE_OPTION ] ?? 'missing' );
	}

	public function testClaimArchivedConnectsAndRemovesEntry(): void {
		update_option( CloudClient::CLAIM_OPTION, array( 'claim_id' => 'lcc_claim_old', 'secret' => 'secretold', 'expires_at' => time() + 600 ), false );
		$this->client()->archivePendingClaim();

		CloudHttp::$queue[] = CloudHttp::json( 202, array( 'status' => 'pending' ) );
		$this->assertSame( array( 'status' => 'pending' ), $this->client()->claimArchived() );
		$this->assertSame( 'secretold', CloudHttp::$requests[0]['body']['claim_secret'] );
		$this->assertCount( 1, $this->client()->archivedClaims() );

		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'token' => 'lcc_late_token_1', 'site_id' => 1, 'account_id' => 1 ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'status' => 'confirmed' ) );
		$this->assertSame( array( 'status' => 'connected' ), $this->client()->claimArchived() );
		$this->assertSame( 'lcc_late_token_1', ( new Settings() )->cloudToken() );
		$this->assertSame( array(), $this->client()->archivedClaims() );
	}

	public function testExpiredArchivedClaimsAreIgnored(): void {
		update_option( CloudClient::ARCHIVE_OPTION, array( array( 'claim_id' => 'old', 'secret' => 's', 'expires_at' => time() - 5 * DAY_IN_SECONDS ) ), false );

		$this->assertSame( array(), $this->client()->archivedClaims() );

		try {
			$this->client()->claimArchived();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'claim_expired', $e->errorCode() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testSiteLimitReachedKeepsClaim(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );

		CloudHttp::$queue[] = CloudHttp::error( 409, 'site_limit_reached' );

		$this->expectException( CloudException::class );

		try {
			$this->client()->claim();
		} finally {
			$this->assertNotFalse( get_option( CloudClient::CLAIM_OPTION ) );
		}
	}

	public function testClaimWithoutStoredClaimThrowsWithoutRequest(): void {
		try {
			$this->client()->claim();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'claim_expired', $e->errorCode() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testExpiredClaimIsDroppedAndNotSent(): void {
		update_option(
			CloudClient::CLAIM_OPTION,
			array(
				'claim_id'   => 'lcc_claim_old',
				'secret'     => str_repeat( 'a', 64 ),
				'expires_at' => time() - 5 * DAY_IN_SECONDS,
			),
			false
		);

		$this->assertNull( $this->client()->pendingClaim() );
		$this->assertFalse( get_option( CloudClient::CLAIM_OPTION ) );
	}

	public function testPendingClaimDoesNotExposeSecret(): void {
		CloudHttp::$queue[] = $this->signupResponse();
		$this->client()->signup( 'example.com' );

		$pending = $this->client()->pendingClaim();

		$this->assertSame( array( 'claim_id', 'expires_at' ), array_keys( $pending ) );
	}

	public function testAuthedCallsSendBearerAndUserAgent(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'account' => array( 'id' => 1 ) ) );

		$this->client( 'lcc_testtoken123' )->account();

		$request = CloudHttp::$requests[0];
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( self::BASE . '/v1/account', $request['url'] );
		$this->assertSame( 'Bearer lcc_testtoken123', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'LingoCanvas/' . LINGOCANVAS_VERSION . '; site.example.test', $request['args']['headers']['User-Agent'] );
		$this->assertSame( CloudClient::DEFAULT_TIMEOUT, $request['args']['timeout'] );
	}

	public function testAuthedCallWithoutTokenFailsBeforeNetwork(): void {
		try {
			$this->client( '' )->account();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'invalid_token', $e->errorCode() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testTranslateBodyShapeAndTimeout(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'translations' => array( 'h1' => 'Hallo' ) ) );

		$this->client()->translate( 'lcreq12345678', 'en', 'de_DE', 'Deutsch', array( 'h1' => 'Hello' ), array( 'Cart' => 'Warenkorb' ) );

		$request = CloudHttp::$requests[0];
		$this->assertSame( 90, $request['args']['timeout'] );
		$this->assertSame(
			array(
				'request_id'    => 'lcreq12345678',
				'source_locale' => 'en',
				'target_locale' => 'de_DE',
				'items'         => array( 'h1' => 'Hello' ),
				'target_label'  => 'Deutsch',
				'glossary'      => array( 'Cart' => 'Warenkorb' ),
			),
			$request['body']
		);
	}

	public function testErrorCarriesCodeStatusRetryAfterAndExtra(): void {
		CloudHttp::$queue[] = CloudHttp::json(
			402,
			array( 'error' => array( 'code' => 'insufficient_quota', 'message' => 'm', 'needed' => 120, 'available' => 80 ) ),
			array( 'Retry-After' => '7' )
		);

		try {
			$this->client()->account();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'insufficient_quota', $e->errorCode() );
			$this->assertSame( 402, $e->httpStatus() );
			$this->assertSame( 7, $e->retryAfter() );
			$this->assertSame( array( 'needed' => 120, 'available' => 80 ), $e->extra() );
			$this->assertStringContainsString( 'used up', $e->userMessage() );
		}
	}

	public function testWpErrorMapsToTimeoutAndNetworkError(): void {
		CloudHttp::$queue[] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 90000 ms' );
		CloudHttp::$queue[] = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );

		foreach ( array( 'timeout', 'network_error' ) as $expected ) {
			try {
				$this->client()->account();
				$this->fail( 'Ожидалось исключение.' );
			} catch ( CloudException $e ) {
				$this->assertSame( $expected, $e->errorCode() );
				$this->assertSame( 0, $e->httpStatus() );
			}
		}
	}

	public function testCheckoutAndPortalReturnStripeUrls(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'checkout_url' => 'https://checkout.stripe.com/c/pay/1' ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'url' => 'https://billing.stripe.com/p/session/2' ) );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/1', $this->client()->checkout( 'pack_250k' ) );
		$this->assertSame( 'https://billing.stripe.com/p/session/2', $this->client()->portal() );
		$this->assertSame( array( 'product' => 'pack_250k' ), CloudHttp::$requests[0]['body'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function badRedirects(): array {
		return array(
			'foreign host'    => array( 'https://evil.example.test/x' ),
			'suffix trick'    => array( 'https://notstripe.com/x' ),
			'stripe in path'  => array( 'https://evil.test/checkout.stripe.com' ),
			'plain http'      => array( 'http://checkout.stripe.com/x' ),
			'userinfo'        => array( 'https://checkout.stripe.com@evil.test/x' ),
			'javascript'      => array( 'javascript:alert(1)' ),
			'empty'           => array( '' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'badRedirects' )]
	public function testCheckoutAndPortalRejectBadUrls( string $url ): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'checkout_url' => $url, 'url' => $url ) );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'checkout_url' => $url, 'url' => $url ) );

		foreach ( array( 'checkout', 'portal' ) as $method ) {
			try {
				'checkout' === $method ? $this->client()->checkout( 'starter' ) : $this->client()->portal();
				$this->fail( 'Ожидалось исключение bad_redirect.' );
			} catch ( CloudException $e ) {
				$this->assertSame( 'bad_redirect', $e->errorCode() );
			}
		}
	}

	public function testCheckoutRejectsUnknownProductWithoutRequest(): void {
		try {
			$this->client()->checkout( 'free_money' );
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'validation_error', $e->errorCode() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testInsecureBaseUrlIsRefused(): void {
		$client = new CloudClient( 'http://cloud.example.test', 'lcc_testtoken123', new Settings() );

		try {
			$client->account();
			$this->fail( 'Ожидалось исключение.' );
		} catch ( CloudException $e ) {
			$this->assertSame( 'bad_config', $e->errorCode() );
		}

		$this->assertSame( array(), CloudHttp::$requests );
	}

	public function testLocalhostHttpIsAllowedForDevelopment(): void {
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'account' => array() ) );

		( new CloudClient( 'http://127.0.0.1:8000', 'lcc_testtoken123', new Settings() ) )->account();

		$this->assertSame( 'http://127.0.0.1:8000/v1/account', CloudHttp::$requests[0]['url'] );
	}
}
