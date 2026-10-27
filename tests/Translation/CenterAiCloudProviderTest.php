<?php
/**
 * Тесты провайдера LingoCanvas Cloud (сеть подменена двойником CloudHttp).
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Translation;

use LingoCanvas\Settings\Settings;
use LingoCanvas\Tests\Doubles\CloudHttp;
use LingoCanvas\Translation\CenterAiCloudProvider;
use LingoCanvas\Translation\CloudClient;
use LingoCanvas\Translation\TranslationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( CenterAiCloudProvider::class )]
final class CenterAiCloudProviderTest extends TestCase {

	/**
	 * Паузы, которые запросил провайдер (в тестах не спим).
	 *
	 * @var list<int>
	 */
	private array $sleeps = array();

	protected function setUp(): void {
		CloudHttp::reset();
		$this->sleeps = array();
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		CloudHttp::reset();
		lingocanvas_test_options( array() );
	}

	private function provider(): CenterAiCloudProvider {
		return new CenterAiCloudProvider(
			new CloudClient( 'https://cloud.example.test', 'lcc_testtoken123', new Settings() ),
			function ( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		);
	}

	/**
	 * Ответ 200, переводящий все ключи запроса («T:» + текст).
	 *
	 * @return callable
	 */
	private function echoOk(): callable {
		return static function ( string $url, array $args, $body ): array {
			$out = array();

			foreach ( $body['items'] as $hash => $text ) {
				$out[ $hash ] = 'T:' . $text;
			}

			return CloudHttp::json( 200, array( 'request_id' => $body['request_id'], 'translations' => $out ) );
		};
	}

	/**
	 * @return array<string, string>
	 */
	private function items( int $count, string $text = 'Hello' ): array {
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$items[ hash( 'sha256', "k$i" ) ] = $text;
		}

		return $items;
	}

	private function context( array $glossary = array() ): TranslationContext {
		return new TranslationContext( 'text', null, null, null, $glossary, 'Deutsch' );
	}

	public function testSupportsAnyPair(): void {
		$this->assertTrue( $this->provider()->supports( 'xx', 'yy' ) );
	}

	public function testRequestBodyShapeAndHeaders(): void {
		CloudHttp::$queue[] = $this->echoOk();
		$items              = array( hash( 'sha256', 'a' ) => 'Add to cart' );

		$provider = $this->provider();
		$result   = $provider->translateBatch( $items, 'en', 'de_DE', $this->context() );

		$this->assertSame( array( hash( 'sha256', 'a' ) => 'T:Add to cart' ), $result );

		$request = CloudHttp::$requests[0];
		$this->assertSame( 'https://cloud.example.test/v1/translate', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 'Bearer lcc_testtoken123', $request['args']['headers']['Authorization'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{8,64}$/', $request['body']['request_id'] );
		$this->assertSame( $items, $request['body']['items'] );
		$this->assertSame( 'en', $request['body']['source_locale'] );
		$this->assertSame( 'de_DE', $request['body']['target_locale'] );
		$this->assertSame( 'Deutsch', $request['body']['target_label'] );
		$this->assertArrayNotHasKey( 'glossary', $request['body'] );
		$this->assertNull( $provider->lastError() );
		$this->assertNull( $provider->lastErrorCode() );
	}

	public function testSplitsByItemCount(): void {
		$batches = CenterAiCloudProvider::splitIntoBatches( $this->items( 650 ) );

		$this->assertSame( array( 300, 300, 50 ), array_map( 'count', $batches ) );
	}

	public function testSplitsByCharacters(): void {
		// 3 × 9000 = 27000 > 20000: первые две не помещаются вместе.
		$batches = CenterAiCloudProvider::splitIntoBatches( $this->items( 3, str_repeat( 'я', 9000 ) ) );

		$this->assertSame( array( 2, 1 ), array_map( 'count', $batches ) );

		foreach ( $batches as $batch ) {
			$this->assertLessThanOrEqual( 20000, array_sum( array_map( 'mb_strlen', $batch ) ) );
		}
	}

	public function testOversizedAndBlankItemsAreNotSent(): void {
		CloudHttp::$queue[] = $this->echoOk();
		$items              = array(
			'big'   => str_repeat( 'x', 10001 ),
			'blank' => '   ',
			'ok'    => 'Hello',
		);

		$result = $this->provider()->translateBatch( $items, 'en', 'de', $this->context() );

		$this->assertSame( array( 'ok' => 'T:Hello' ), $result );
		$this->assertSame( array( 'ok' => 'Hello' ), CloudHttp::$requests[0]['body']['items'] );
	}

	public function testOnlyOversizedItemsMakeNoRequestAndSetError(): void {
		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'big' => str_repeat( 'x', 10001 ) ), 'en', 'de', $this->context() );

		$this->assertSame( array(), $result );
		$this->assertSame( array(), CloudHttp::$requests );
		$this->assertSame( 'payload_too_large', $provider->lastErrorCode() );
	}

	public function testItemOfExactLimitIsSent(): void {
		CloudHttp::$queue[] = $this->echoOk();

		$result = $this->provider()->translateBatch( array( 'k' => str_repeat( 'я', 10000 ) ), 'ru', 'en', $this->context() );

		$this->assertArrayHasKey( 'k', $result );
	}

	public function testMergesSubBatchesWithDistinctRequestIds(): void {
		CloudHttp::$queue[] = $this->echoOk();
		CloudHttp::$queue[] = $this->echoOk();

		$result = $this->provider()->translateBatch( $this->items( 301 ), 'en', 'de', $this->context() );

		$this->assertCount( 301, $result );
		$this->assertCount( 2, CloudHttp::$requests );
		$this->assertNotSame( CloudHttp::$requests[0]['body']['request_id'], CloudHttp::$requests[1]['body']['request_id'] );
	}

	public function testMissingKeysAreOmittedWithoutError(): void {
		$items              = array( 'a' => 'One', 'b' => 'Two' );
		CloudHttp::$queue[] = CloudHttp::json( 200, array( 'translations' => array( 'a' => 'Eins', 'zzz' => 'foreign' ), 'missing' => array( 'b' ) ) );

		$provider = $this->provider();

		$this->assertSame( array( 'a' => 'Eins' ), $provider->translateBatch( $items, 'en', 'de', $this->context() ) );
		$this->assertNull( $provider->lastError() );
	}

	public function testGlossaryIsFilteredToTermsPresentInBatch(): void {
		CloudHttp::$queue[] = $this->echoOk();

		$this->provider()->translateBatch(
			array( 'a' => 'Add to cart now' ),
			'en',
			'de',
			$this->context( array( 'Cart' => 'Warenkorb', 'Checkout' => 'Kasse', '' => 'x', 'Blank' => ' ' ) )
		);

		$this->assertSame( array( 'Cart' => 'Warenkorb' ), CloudHttp::$requests[0]['body']['glossary'] );
	}

	public function testGlossaryLimits(): void {
		$text     = str_repeat( 'term ', 5 );
		$glossary = array(
			str_repeat( 'a', 201 ) => 'too long key',
			'term'                 => str_repeat( 'b', 201 ),
		);
		$this->assertSame( array(), CenterAiCloudProvider::filterGlossary( $glossary, array( 'k' => $text ) ) );

		// Не больше 50 записей.
		$many = array();
		$body = '';

		for ( $i = 0; $i < 80; $i++ ) {
			$many[ "t$i" ] = "v$i";
			$body         .= "t$i ";
		}

		$body = str_repeat( $body, 20 );
		$this->assertCount( 50, CenterAiCloudProvider::filterGlossary( $many, array( 'k' => $body ) ) );

		// Бюджет 2 × символы + 200: короткий текст «Hi» — 204 символа на глоссарий.
		$tight = array(
			'Hi' => str_repeat( 'c', 150 ),
			'H'  => str_repeat( 'd', 150 ),
		);
		$this->assertSame( array( 'Hi' ), array_keys( CenterAiCloudProvider::filterGlossary( $tight, array( 'k' => 'Hi' ) ) ) );

		// Потолок 2000 символов независимо от размера текста.
		$big   = array();
		$other = '';

		for ( $i = 0; $i < 20; $i++ ) {
			$big[ "w$i" ] = str_repeat( 'x', 190 );
			$other       .= "w$i ";
		}

		$picked = CenterAiCloudProvider::filterGlossary( $big, array( 'k' => str_repeat( $other, 100 ) ) );
		$sum    = 0;

		foreach ( $picked as $term => $value ) {
			$sum += mb_strlen( (string) $term ) + mb_strlen( $value );
		}

		$this->assertLessThanOrEqual( 2000, $sum );
		$this->assertNotEmpty( $picked );
	}

	/**
	 * @return array<string, array{mixed, list<int>}>
	 */
	public static function retryableFailures(): array {
		return array(
			'timeout'        => array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ), array( 2 ) ),
			'503'            => array( CloudHttp::error( 503, 'provider_unavailable' ), array( 2 ) ),
			'500'            => array( CloudHttp::error( 500, 'internal_error' ), array( 2 ) ),
			'429 retry-after' => array( CloudHttp::error( 429, 'rate_limited', array( 'Retry-After' => '4' ) ), array( 4 ) ),
			'429 at limit'   => array( CloudHttp::error( 429, 'rate_limited', array( 'Retry-After' => '10' ) ), array( 10 ) ),
			'in progress'    => array( CloudHttp::error( 409, 'request_in_progress' ), array( 2 ) ),
		);
	}

	/**
	 * @param mixed      $failure Первый ответ.
	 * @param list<int>  $firstWait Ожидаемая первая пауза.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'retryableFailures' )]
	public function testRetriesWithSameRequestId( $failure, array $firstWait ): void {
		CloudHttp::$queue[] = $failure;
		CloudHttp::$queue[] = $this->echoOk();

		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array( 'a' => 'T:Hello' ), $result );
		$this->assertCount( 2, CloudHttp::$requests );
		$this->assertSame( CloudHttp::$requests[0]['body']['request_id'], CloudHttp::$requests[1]['body']['request_id'] );
		$this->assertSame( $firstWait, $this->sleeps );
		$this->assertNull( $provider->lastError() );
	}

	public function testGivesUpAfterThreeAttemptsWithBackoff(): void {
		CloudHttp::$queue[] = CloudHttp::error( 503, 'provider_unavailable' );
		CloudHttp::$queue[] = CloudHttp::error( 503, 'provider_unavailable' );
		CloudHttp::$queue[] = CloudHttp::error( 503, 'provider_unavailable' );

		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array(), $result );
		$this->assertCount( 3, CloudHttp::$requests );
		$this->assertSame( array( 2, 5 ), $this->sleeps );
		$this->assertSame( 'provider_unavailable', $provider->lastErrorCode() );
		$this->assertStringContainsString( 'temporarily unavailable', (string) $provider->lastError() );
		$this->assertSame( 1, count( array_unique( array_map( static fn( $r ) => $r['body']['request_id'], CloudHttp::$requests ) ) ) );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function terminalFailures(): array {
		return array(
			'402'      => array( 402, 'insufficient_quota' ),
			'401'      => array( 401, 'invalid_token' ),
			'403'      => array( 403, 'account_suspended' ),
			'413'      => array( 413, 'payload_too_large' ),
			'422'      => array( 422, 'validation_error' ),
			'conflict' => array( 409, 'request_id_conflict' ),
			'retry'    => array( 409, 'retry_limit' ),
			'budget'   => array( 503, 'service_budget_exhausted' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'terminalFailures' )]
	public function testNoRetryOnTerminalErrors( int $status, string $code ): void {
		CloudHttp::$queue[] = CloudHttp::error( $status, $code, array( 'Retry-After' => '3600' ) );

		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array(), $result );
		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertSame( array(), $this->sleeps );
		$this->assertSame( $code, $provider->lastErrorCode() );
		$this->assertNotSame( '', (string) $provider->lastError() );
	}

	public function testUserMessagesForKeyCodes(): void {
		$expected = array(
			'insufficient_quota' => 'Your LingoCanvas Cloud characters are used up. Buy an extra pack or wait for the renewal.',
			'invalid_token'      => 'This site is no longer connected to LingoCanvas Cloud. Connect it again in Settings.',
			'payload_too_large'  => 'This text is too long for one request.',
		);

		foreach ( $expected as $code => $message ) {
			CloudHttp::reset();
			CloudHttp::$queue[] = CloudHttp::error( 'payload_too_large' === $code ? 413 : 402, $code );

			$provider = $this->provider();
			$provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

			$this->assertSame( $message, $provider->lastError() );
			$this->assertSame( $message, $provider->maskedLastError() );
		}
	}

	public function testTerminalErrorStopsFurtherSubBatchesAndKeepsPartialResult(): void {
		CloudHttp::$queue[] = $this->echoOk();
		CloudHttp::$queue[] = CloudHttp::error( 402, 'insufficient_quota' );

		$provider = $this->provider();
		$result   = $provider->translateBatch( $this->items( 650 ), 'en', 'de', $this->context() );

		$this->assertCount( 300, $result );
		$this->assertCount( 2, CloudHttp::$requests );
		$this->assertSame( 'insufficient_quota', $provider->lastErrorCode() );
	}

	public function testLastErrorIsResetOnNextCall(): void {
		CloudHttp::$queue[] = CloudHttp::error( 402, 'insufficient_quota' );
		CloudHttp::$queue[] = $this->echoOk();

		$provider = $this->provider();
		$provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );
		$this->assertNotNull( $provider->lastError() );

		$provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );
		$this->assertNull( $provider->lastError() );
		$this->assertNull( $provider->lastErrorCode() );
	}

	public function testTokenNeverAppearsInErrorsOrBodies(): void {
		CloudHttp::$queue[] = CloudHttp::error( 401, 'invalid_token' );

		$provider = $this->provider();
		$provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertStringNotContainsString( 'lcc_testtoken123', (string) $provider->lastError() );
		$this->assertStringNotContainsString( 'lcc_testtoken123', (string) CloudHttp::$requests[0]['args']['body'] );
	}

	// --- Идемпотентность между PHP-запросами -----------------------------------------------

	public function testSameBodyReusesStoredRequestIdAcrossProviderInstances(): void {
		// Первый «клик»: таймаут на нашей стороне во всех попытках.
		$timeout = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		CloudHttp::$queue = array( $timeout, $timeout, $timeout );

		$first = $this->provider();
		$first->translateBatch( array( 'a' => 'Hello', 'b' => 'World' ), 'en', 'de', $this->context() );

		$this->assertSame( 'timeout', $first->lastErrorCode() );
		$sentId = CloudHttp::$requests[0]['body']['request_id'];
		$stored = get_option( CenterAiCloudProvider::OPS_OPTION );
		$this->assertCount( 1, $stored );
		$this->assertSame( $sentId, array_values( $stored )[0]['request_id'] );
		$this->assertSame( 'no', $GLOBALS['lingocanvas_test_autoload'][ CenterAiCloudProvider::OPS_OPTION ] ?? 'missing' );

		// Второй «клик» (другой экземпляр, порядок ключей другой): тот же id, сервер ответит бесплатным повтором.
		CloudHttp::$requests = array();
		CloudHttp::$queue[]  = $this->echoOk();

		$second = $this->provider();
		$result = $second->translateBatch( array( 'b' => 'World', 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array( 'b' => 'T:World', 'a' => 'T:Hello' ), $result );
		$this->assertSame( $sentId, CloudHttp::$requests[0]['body']['request_id'] );
		$this->assertNull( $second->lastError() );

		// Успех не стирает запись: сразу повторный запрос после потерянного ответа тоже бесплатный повтор.
		$this->assertSame( $stored, get_option( CenterAiCloudProvider::OPS_OPTION ) );
	}

	public function testDifferentBodyOrGlossaryGetsDifferentRequestId(): void {
		CloudHttp::$queue = array( $this->echoOk(), $this->echoOk(), $this->echoOk() );

		$provider = $this->provider();
		$provider->translateBatch( array( 'a' => 'Hello cart' ), 'en', 'de', $this->context() );
		$provider->translateBatch( array( 'a' => 'Hello cart' ), 'en', 'fr', $this->context() );
		$provider->translateBatch( array( 'a' => 'Hello cart' ), 'en', 'de', $this->context( array( 'cart' => 'Warenkorb' ) ) );

		$ids = array_map( static fn( $r ) => $r['body']['request_id'], CloudHttp::$requests );
		$this->assertCount( 3, array_unique( $ids ) );
	}

	public function testFingerprintIsCanonical(): void {
		$one = CenterAiCloudProvider::fingerprint( 'en', 'de', 'Deutsch', array( 'b' => 'x', 'a' => 'y' ), array( 'T2' => 'u', 'T1' => 'v' ) );
		$two = CenterAiCloudProvider::fingerprint( 'en', 'de', 'Deutsch', array( 'a' => 'y', 'b' => 'x' ), array( 'T1' => 'v', 'T2' => 'u' ) );
		$bad = CenterAiCloudProvider::fingerprint( 'en', 'de', 'Deutsch', array( 'a' => 'y', 'b' => 'z' ), array( 'T1' => 'v', 'T2' => 'u' ) );

		$this->assertSame( $one, $two );
		$this->assertNotSame( $one, $bad );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $one );
	}

	public function testReplayLimitDropsStoredIdAndRetriesOnceWithFreshId(): void {
		CloudHttp::$queue[] = $this->echoOk();
		$this->provider()->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );
		$oldId = CloudHttp::$requests[0]['body']['request_id'];

		CloudHttp::$requests = array();
		CloudHttp::$queue    = array( CloudHttp::error( 409, 'replay_limit' ), $this->echoOk() );

		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array( 'a' => 'T:Hello' ), $result );
		$this->assertCount( 2, CloudHttp::$requests );
		$this->assertSame( $oldId, CloudHttp::$requests[0]['body']['request_id'] );
		$newId = CloudHttp::$requests[1]['body']['request_id'];
		$this->assertNotSame( $oldId, $newId );
		$this->assertNull( $provider->lastError() );
		$this->assertSame( $newId, array_values( get_option( CenterAiCloudProvider::OPS_OPTION ) )[0]['request_id'] );
	}

	public function testReplayLimitTwiceIsTerminal(): void {
		CloudHttp::$queue = array( CloudHttp::error( 409, 'replay_limit' ), CloudHttp::error( 409, 'replay_limit' ) );

		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array(), $result );
		$this->assertCount( 2, CloudHttp::$requests );
		$this->assertSame( 'replay_limit', $provider->lastErrorCode() );
	}

	public function testStoredIdsExpireAfterDayAndAreBoundedTo200(): void {
		$old   = array( 'request_id' => 'lcold1234567890', 'created' => time() - 86400 - 10 );
		$stale = array();

		for ( $i = 0; $i < 250; $i++ ) {
			$stale[ hash( 'sha256', "fp$i" ) ] = array( 'request_id' => "lcid$i-xxxxxxxx", 'created' => time() - 1000 + $i );
		}

		$fpOld = CenterAiCloudProvider::fingerprint( 'en', 'de', 'Deutsch', array( 'a' => 'Hello' ), array() );
		update_option( CenterAiCloudProvider::OPS_OPTION, array( $fpOld => $old ) + $stale, false );

		CloudHttp::$queue[] = $this->echoOk();
		$this->provider()->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		// Просроченная запись не используется: ушёл новый id.
		$this->assertNotSame( 'lcold1234567890', CloudHttp::$requests[0]['body']['request_id'] );

		$stored = get_option( CenterAiCloudProvider::OPS_OPTION );
		$this->assertCount( 200, $stored );
		$this->assertArrayHasKey( $fpOld, $stored );
		$this->assertNotSame( 'lcold1234567890', $stored[ $fpOld ]['request_id'] );
	}

	public function testStaleOpsLockDoesNotBlockAndLockIsReleased(): void {
		update_option( CenterAiCloudProvider::OPS_LOCK_OPTION, time() - 1, false );
		CloudHttp::$queue[] = $this->echoOk();

		$this->provider()->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertFalse( get_option( CenterAiCloudProvider::OPS_LOCK_OPTION, false ) );
		$this->assertCount( 1, get_option( CenterAiCloudProvider::OPS_OPTION ) );
	}

	// --- Retry-After ------------------------------------------------------------------------

	public function testLongRetryAfterStopsImmediatelyKeepsIdAndShowsBusyMessage(): void {
		CloudHttp::$queue[] = CloudHttp::error( 429, 'rate_limited', array( 'Retry-After' => '60' ) );

		$provider = $this->provider();
		$result   = $provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array(), $result );
		$this->assertCount( 1, CloudHttp::$requests );
		$this->assertSame( array(), $this->sleeps );
		$this->assertSame( 'rate_limited', $provider->lastErrorCode() );
		$this->assertSame( 'LingoCanvas Cloud is busy, try again in 60 seconds.', $provider->lastError() );
		$this->assertSame(
			CloudHttp::$requests[0]['body']['request_id'],
			array_values( get_option( CenterAiCloudProvider::OPS_OPTION ) )[0]['request_id']
		);

		// Следующий клик отправляет тот же id.
		CloudHttp::$queue[] = $this->echoOk();
		$next = $this->provider();
		$next->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );
		$this->assertSame( CloudHttp::$requests[0]['body']['request_id'], CloudHttp::$requests[1]['body']['request_id'] );
	}

	public function testServerRetryAfterIsNeverShortened(): void {
		CloudHttp::$queue[] = CloudHttp::error( 429, 'rate_limited', array( 'Retry-After' => '8' ) );
		CloudHttp::$queue[] = $this->echoOk();

		$this->provider()->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( array( 8 ), $this->sleeps );
	}

	public function testBudgetExhaustedKeepsItsOwnMessage(): void {
		CloudHttp::$queue[] = CloudHttp::error( 503, 'service_budget_exhausted', array( 'Retry-After' => '3600' ) );

		$provider = $this->provider();
		$provider->translateBatch( array( 'a' => 'Hello' ), 'en', 'de', $this->context() );

		$this->assertSame( 'service_budget_exhausted', $provider->lastErrorCode() );
		$this->assertStringContainsString( 'temporarily unavailable', (string) $provider->lastError() );
		$this->assertSame( array(), $this->sleeps );
	}

	public function testEmptyInputMakesNoRequest(): void {
		$this->assertSame( array(), $this->provider()->translateBatch( array(), 'en', 'de', $this->context() ) );
		$this->assertSame( array(), CloudHttp::$requests );
	}
}
