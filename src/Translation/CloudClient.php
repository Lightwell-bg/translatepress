<?php
/**
 * HTTP-клиент LingoCanvas Cloud (CenterAI Cloud).
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Translation;

use LingoCanvas\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Тонкая обёртка над `wp_remote_request()` для API Cloud (cloud/docs/API.md).
 *
 * Токен уходит только в заголовке Authorization авторизованных вызовов. Клиент
 * ничего не пишет в лог: ни тела запросов, ни ответы, ни токен. Любая ошибка
 * превращается в `CloudException` с кодом из `{"error":{"code"}}`.
 */
final class CloudClient {

	/** Опция с ожидающим «заявочным» кодом регистрации (не автозагружаемая). */
	public const CLAIM_OPTION = 'lingocanvas_cloud_claim';

	/** Опция с отменёнными владельцем, но ещё не истёкшими заявками (не автозагружаемая). */
	public const ARCHIVE_OPTION = 'lingocanvas_cloud_claim_archive';

	/** Опция с заявкой, токен которой получен, но получение ещё не подтверждено (не автозагружаемая, с секретом). */
	public const UNCONFIRMED_OPTION = 'lingocanvas_cloud_claim_unconfirmed';

	/** Замок опроса оплаты: «владелец|срок» (не автозагружаемая). */
	public const CLAIM_LOCK_OPTION = 'lingocanvas_cloud_claim_lock';

	/** Через сколько секунд замок опроса считается упавшим (дольше самой длинной цепочки запросов). */
	private const CLAIM_LOCK_TTL = 120;

	/** Окно, в которое сервер может заново выдать токен без подтверждения (claim_recovery_hours), секунд. */
	private const RECOVERY_WINDOW = 86400;

	/** Запас хранения заявки после окна восстановления, секунд. */
	private const CONFIRM_GRACE = 172800;

	/** Таймаут подтверждения получения токена, секунд. */
	public const CONFIRM_TIMEOUT = 20;

	/** Сколько отменённых заявок хранится максимум. */
	private const ARCHIVE_MAX = 3;

	/** Таймаут запроса токена, секунд: сервер выдаёт токен один раз, терять ответ нельзя. */
	public const CLAIM_TIMEOUT = 30;

	/** Таймаут перевода, секунд. */
	public const TRANSLATE_TIMEOUT = 90;

	/** Таймаут остальных вызовов, секунд. */
	public const DEFAULT_TIMEOUT = 20;

	/** Допустимые товары для /v1/billing/checkout. */
	public const PRODUCTS = array( 'pack_250k', 'starter' );

	/**
	 * @param string   $baseUrl  Адрес API без хвостового слеша.
	 * @param string   $token    Токен сайта (пусто — до подключения).
	 * @param Settings $settings Настройки: сюда сохраняется полученный токен.
	 */
	public function __construct(
		private readonly string $baseUrl,
		private readonly string $token,
		private readonly Settings $settings
	) {
	}

	/**
	 * Начинает регистрацию: возвращает адрес оплаты в Stripe и запоминает заявку.
	 *
	 * Секрет заявки (32 случайных байта, hex) остаётся только в опции на сервере
	 * сайта; в Cloud уходит только его SHA-256.
	 *
	 * @param string      $siteName Название сайта (1–255 символов).
	 * @param string|null $email    Почта (необязательно).
	 * @return array{checkout_url: string, claim_id: string, expires_at: int}
	 * @throws CloudException При ошибке API или сети.
	 */
	public function signup( string $siteName, ?string $email = null ): array {
		$secret = bin2hex( random_bytes( 32 ) );
		$body   = array(
			'plan'                => 'starter',
			'site_name'           => mb_substr( $siteName, 0, 255 ),
			'claim_secret_sha256' => hash( 'sha256', $secret ),
		);

		if ( null !== $email && '' !== trim( $email ) ) {
			$body['email'] = trim( $email );
		}

		$data = $this->request( 'POST', '/v1/billing/signup', $body, false, self::DEFAULT_TIMEOUT );

		$checkoutUrl = self::stripeUrl( $data['checkout_url'] ?? null );
		$claimId     = (string) ( $data['claim_id'] ?? '' );

		if ( '' === $claimId ) {
			throw new CloudException( 'bad_response' );
		}

		$expiresAt = isset( $data['expires_at'] ) ? (int) strtotime( (string) $data['expires_at'] ) : 0;

		if ( $expiresAt <= 0 ) {
			$expiresAt = time() + DAY_IN_SECONDS;
		}

		// Прошлая заявка с вышедшим сроком оплаты могла быть оплачена, а ответ потерян: секрет не затираем, а убираем в архив.
		$this->archivePendingClaim();

		update_option(
			self::CLAIM_OPTION,
			array(
				'claim_id'     => $claimId,
				'secret'       => $secret,
				'expires_at'   => $expiresAt,
				// Адрес оплаты хранится, чтобы повторный клик вёл на тот же платёж, а не создавал второй.
				'checkout_url' => $checkoutUrl,
			),
			false
		);

		return array(
			'checkout_url' => $checkoutUrl,
			'claim_id'     => $claimId,
			'expires_at'   => $expiresAt,
		);
	}

	/**
	 * Ожидающая заявка без секрета (для интерфейса) или null, если её нет или срок её хранения вышел.
	 *
	 * Заявка живёт дольше своего `expires_at`: оплата могла пройти, а ответ потеряться.
	 *
	 * @return array{claim_id: string, expires_at: int}|null
	 */
	public function pendingClaim(): ?array {
		$claim = $this->storedClaim();

		return null === $claim ? null : array(
			'claim_id'   => $claim['claim_id'],
			'expires_at' => $claim['expires_at'],
		);
	}

	/**
	 * Забирает токен после оплаты.
	 *
	 * 202 — оплаты ещё нет (`pending`); 200 — токен сохранён в настройках,
	 * заявка удалена (`connected`). Заявку удаляют только окончательные ответы
	 * Cloud (`claim_not_found`, `claim_expired`, проверенный `already_claimed`);
	 * остальные ошибки (`site_limit_reached`, `account_suspended`, `site_revoked`,
	 * сеть) заявку оставляют: владелец может исправить причину и опросить снова.
	 *
	 * @return array{status: string}
	 * @throws CloudException При ошибке API, сети или если заявки нет.
	 */
	public function claim(): array {
		$claim = $this->storedClaim();

		if ( null === $claim ) {
			throw new CloudException( 'claim_expired', 410 );
		}

		return $this->claimWith( $claim );
	}

	/**
	 * Адрес оплаты ожидающей заявки (чтобы повторный клик вёл на тот же платёж) или null.
	 */
	public function pendingCheckoutUrl(): ?string {
		$claim = $this->storedClaim();

		if ( null === $claim || '' === $claim['checkout_url'] || $claim['expires_at'] <= time() ) {
			return null;
		}

		try {
			return self::stripeUrl( $claim['checkout_url'] );
		} catch ( CloudException $e ) {
			return null;
		}
	}

	/**
	 * Переносит ожидающую заявку в архив: она остаётся проверяемой до конца срока хранения.
	 *
	 * Владелец мог оплатить, а подтверждение ещё в пути; удалять секрет заявки нельзя,
	 * иначе оплаченный токен станет недоступен. В архиве не больше трёх новейших заявок.
	 * Исходная запись удаляется только после проверки, что архив записан.
	 */
	public function archivePendingClaim(): void {
		$claim = $this->storedClaim();

		if ( null === $claim ) {
			return;
		}

		$archive = array_filter(
			$this->storedArchive(),
			static fn( array $entry ): bool => $entry['claim_id'] !== $claim['claim_id']
		);

		array_unshift( $archive, $claim );

		update_option( self::ARCHIVE_OPTION, array_slice( array_values( $archive ), 0, self::ARCHIVE_MAX ), false );

		foreach ( $this->storedArchive() as $entry ) {
			if ( $entry['claim_id'] === $claim['claim_id'] ) {
				delete_option( self::CLAIM_OPTION );

				return;
			}
		}
	}

	/**
	 * Отменённые, но ещё хранимые заявки (без секретов): новейшая первой.
	 *
	 * @return list<array{claim_id: string, expires_at: int}>
	 */
	public function archivedClaims(): array {
		return array_map(
			static fn( array $entry ): array => array(
				'claim_id'   => $entry['claim_id'],
				'expires_at' => $entry['expires_at'],
			),
			$this->storedArchive()
		);
	}

	/**
	 * Проверяет оплату по новейшей архивной заявке (кнопка «Уже оплатили? Проверить ещё раз»).
	 *
	 * @return array{status: string}
	 * @throws CloudException Как claim().
	 */
	public function claimArchived(): array {
		$archive = $this->storedArchive();

		if ( array() === $archive ) {
			throw new CloudException( 'claim_expired', 410 );
		}

		return $this->claimWith( $archive[0] );
	}

	/**
	 * Один запрос токена по заявке (под замком опроса).
	 *
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $claim Заявка.
	 * @return array{status: string}
	 * @throws CloudException При ошибке.
	 */
	private function claimWith( array $claim ): array {
		// Один опрос за раз: параллельные вкладки не должны ротировать токен друг у друга.
		$owner = $this->acquireClaimLock();

		if ( null === $owner ) {
			return array( 'status' => 'pending' );
		}

		try {
			// Состояние могло измениться, пока мы ждали замок: читаем его заново до любого запроса.
			$fresh = $this->findRecord( $claim['claim_id'] );

			if ( null === $fresh ) {
				if ( '' !== ( new Settings() )->cloudToken() ) {
					return array( 'status' => 'connected' );
				}

				throw new CloudException( 'claim_expired', 410 );
			}

			return $this->claimLocked( $fresh );
		} finally {
			$this->releaseClaimLock( $owner );
		}
	}

	/**
	 * Запрос токена, сохранение, подтверждение получения (под замком опроса).
	 *
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $claim Заявка.
	 * @return array{status: string}
	 * @throws CloudException При ошибке.
	 */
	private function claimLocked( array $claim ): array {
		$startToken = ( new Settings() )->cloudToken();

		try {
			$data = $this->postClaim( $claim, $status );
		} catch ( CloudException $e ) {
			$this->handleClaimError( $e, $claim );
		}

		if ( 202 === $status ) {
			return array( 'status' => 'pending' );
		}

		$token = trim( (string) ( $data['token'] ?? '' ) );

		if ( '' === $token ) {
			$badResponse = CloudException::make( 'bad_response', (int) $status );

			throw $badResponse;
		}

		/*
		 * Сначала на диск ложится запись «токен получен, подтверждение не отправлено» с секретом
		 * заявки, и только потом токен. Если процесс оборвётся между шагами, секрет не потерян:
		 * следующий опрос подтвердит или заберёт токен заново (сервер разрешает это 24 часа).
		 */
		$record = $claim;

		$existing = $this->storedUnconfirmed();

		$record['received_at'] = null !== $existing && $existing['claim_id'] === $claim['claim_id'] && $existing['received_at'] > 0
			? $existing['received_at']
			: time();

		if ( ! $this->writeUnconfirmed( $record ) ) {
			$notSaved = CloudException::make( 'token_not_saved', (int) $status );

			throw $notSaved;
		}

		$saved = $this->saveToken( $token, $startToken, (int) $status );

		if ( null === $saved ) {
			// Пока шёл запрос, токен сайта заменил другой опрос: чужой, более новый токен не затираем.
			return '' !== ( new Settings() )->cloudToken() ? array( 'status' => 'connected' ) : array( 'status' => 'pending' );
		}

		// Токен сохранён и читается: исходные записи заявки больше не нужны, остаётся запись неподтверждённой.
		$this->dropSources( $claim['claim_id'] );

		/*
		 * Сервер готов выдать токен заново, пока сайт не подтвердил получение, поэтому запись
		 * заявки с секретом удаляется только после успешного подтверждения. Не вышло (сеть, 5xx) —
		 * она подтверждается при следующем опросе или открытии настроек; сайт при этом уже подключён.
		 */
		$outcome = $this->confirmWithRecovery( $record, $saved, true );

		if ( 'confirmed' === $outcome ) {
			$this->forgetClaim( $claim['claim_id'] );
		} elseif ( 'lost' === $outcome ) {
			// Cloud говорит «токен уже выдан», а наш токен он не принимает: заявку оставляем для поддержки.
			$lost = CloudException::make( 'already_claimed_lost', 409, null, array( 'claim_id' => $claim['claim_id'] ) );

			throw $lost;
		}

		return array( 'status' => 'connected' );
	}

	/**
	 * Разбирает отказ на запрос токена: выбрасывает исключение, при окончательном ответе Cloud сначала удаляя заявку.
	 *
	 * @param CloudException                                                                                   $e     Отказ.
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $claim Заявка.
	 * @return never
	 * @throws CloudException Всегда.
	 */
	private function handleClaimError( CloudException $e, array $claim ): never {
		$http = $e->httpStatus();
		$code = $e->errorCode();

		if ( 409 === $http && 'already_claimed' === $code ) {
			$local = ( new Settings() )->cloudToken();

			/*
			 * Cloud уже выдал токен. Заявку можно удалить, только если токен сайта принимается
			 * Cloud (проверено запросом); иначе сайт остался без токена, а заявка нужна поддержке.
			 * Секрет заявки в сообщение не попадает, только номер.
			 */
			if ( '' !== $local && $this->tokenAccepted( $local ) ) {
				$this->forgetClaim( $claim['claim_id'] );

				throw $e;
			}

			$lost = CloudException::make( 'already_claimed_lost', 409, null, array( 'claim_id' => $claim['claim_id'] ) );

			throw $lost;
		}

		if ( ( 404 === $http && 'claim_not_found' === $code ) || ( 410 === $http && 'claim_expired' === $code ) ) {
			$this->forgetClaim( $claim['claim_id'] );
		}

		throw $e;
	}

	/**
	 * Принимает ли Cloud этот токен (GET /v1/account).
	 *
	 * @param string $token Токен сайта.
	 * @return bool false — Cloud ответил 401.
	 * @throws CloudException При сетевой или иной ошибке: проверить не удалось.
	 */
	private function tokenAccepted( string $token ): bool {
		try {
			$this->request( 'GET', '/v1/account', null, true, self::DEFAULT_TIMEOUT, $status, $token );

			return true;
		} catch ( CloudException $e ) {
			if ( 401 === $e->httpStatus() ) {
				return false;
			}

			throw $e;
		}
	}

	/**
	 * Один POST /v1/billing/claim.
	 *
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $claim  Заявка.
	 * @param int|null                                                                                         $status HTTP-статус ответа.
	 * @return array<string, mixed>
	 * @throws CloudException При ошибке.
	 */
	private function postClaim( array $claim, ?int &$status = null ): array {
		return $this->request(
			'POST',
			'/v1/billing/claim',
			array(
				'claim_id'     => $claim['claim_id'],
				'claim_secret' => $claim['secret'],
			),
			false,
			self::CLAIM_TIMEOUT,
			$status
		);
	}

	/**
	 * Сохраняет токен из ответа и проверяет, что он прочитался из базы.
	 *
	 * Токен записывается, только если сохранённый сейчас токен всё ещё равен тому, что был
	 * у этого опроса в начале: более новый токен другого опроса не затирается.
	 *
	 * @param string $token    Токен из ответа.
	 * @param string $expected Токен сайта, каким он был до этого запроса.
	 * @param int    $status   HTTP-статус ответа.
	 * @return string|null Сохранённый токен или null, если токен сайта успели заменить.
	 * @throws CloudException `token_not_saved`.
	 */
	private function saveToken( string $token, string $expected, int $status ): ?string {
		if ( ( new Settings() )->cloudToken() !== $expected ) {
			return null;
		}

		$this->settings->saveCloud( array( 'cloud_token' => $token ) );

		if ( ( new Settings() )->cloudToken() !== $token ) {
			$notSaved = CloudException::make( 'token_not_saved', $status );

			throw $notSaved;
		}

		return $token;
	}

	/**
	 * Подтверждает получение токена; при 401 (токен ротирован параллельным опросом) один раз забирает токен заново.
	 *
	 * Секрет заявки в подтверждение не уходит: только номер заявки и новый токен в заголовке.
	 *
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $claim        Заявка.
	 * @param string                                                                                           $token        Только что сохранённый токен.
	 * @param bool                                                                                             $allowReclaim Можно ли при 401 забрать токен заново (только для записи с полученным токеном).
	 * @return string `confirmed` (подтверждено или Cloud больше не знает заявку), `retry` (повторить позже) или `lost` (токен выдан, а у сайта его нет).
	 */
	private function confirmWithRecovery( array $claim, string $token, bool $allowReclaim ): string {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			try {
				$this->request( 'POST', '/v1/billing/claim/confirm', array( 'claim_id' => $claim['claim_id'] ), true, self::CONFIRM_TIMEOUT, $confirmStatus, $token );

				return 'confirmed';
			} catch ( CloudException $e ) {
				if ( 404 === $e->httpStatus() ) {
					return 'confirmed';
				}

				if ( 401 !== $e->httpStatus() || 1 === $attempt || ! $allowReclaim ) {
					return 'retry';
				}
			}

			// Токен ротирован: пока нет подтверждения, сервер разрешает забрать новый.
			try {
				$data     = $this->postClaim( $claim, $status );
				$newToken = trim( (string) ( $data['token'] ?? '' ) );
			} catch ( CloudException $e ) {
				$http = $e->httpStatus();
				$code = $e->errorCode();

				if ( 409 === $http && 'already_claimed' === $code ) {
					// НЕ успех: подтвердить нечем, токена у сайта нет.
					return 'lost';
				}

				if ( ( 404 === $http && 'claim_not_found' === $code ) || ( 410 === $http && 'claim_expired' === $code ) ) {
					return 'confirmed';
				}

				return 'retry';
			}

			if ( '' === $newToken ) {
				return 'retry';
			}

			$saved = $this->saveToken( $newToken, $token, (int) $status );

			if ( null === $saved ) {
				return 'retry';
			}

			$token = $saved;
		}

		return 'retry';
	}

	/**
	 * Записывает запись «токен получен, подтверждение не отправлено» и проверяет, что она читается.
	 *
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $record Заявка с моментом получения.
	 */
	private function writeUnconfirmed( array $record ): bool {
		update_option( self::UNCONFIRMED_OPTION, $record, false );

		$stored = get_option( self::UNCONFIRMED_OPTION, array() );

		return is_array( $stored )
			&& (string) ( $stored['claim_id'] ?? '' ) === $record['claim_id']
			&& (string) ( $stored['secret'] ?? '' ) === $record['secret'];
	}

	/**
	 * Повторяет подтверждение получения токена, если прошлая попытка не удалась.
	 *
	 * Вызывается при опросе оплаты и при открытии настроек Cloud (только с согласием владельца).
	 * Работает со всеми сохранёнными записями заявок (неподтверждённые, ожидающие, архивные), если токен уже есть.
	 * Запись, срок хранения которой вышел, удаляется.
	 */
	public function retryPendingConfirm(): void {
		if ( array() === $this->resumableRecords() ) {
			return;
		}

		if ( '' === ( new Settings() )->cloudToken() ) {
			return;
		}

		$owner = $this->acquireClaimLock();

		if ( null === $owner ) {
			return;
		}

		try {
			// Под замком читаем токен и записи заново: их мог изменить другой опрос.
			foreach ( $this->resumableRecords() as $record ) {
				$token = ( new Settings() )->cloudToken();

				if ( '' === $token ) {
					return;
				}

				// Токен без полученной записи (ожидающая/архивная заявка) заново не выдаётся: только пробуем подтвердить.
				if ( 'confirmed' === $this->confirmWithRecovery( $record, $token, $record['received_at'] > 0 ) ) {
					$this->forgetClaim( $record['claim_id'] );
				}
			}
		} finally {
			$this->releaseClaimLock( $owner );
		}
	}

	/**
	 * Все хранимые записи заявок без повторов: неподтверждённая первой.
	 *
	 * @return list<array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int}>
	 */
	private function resumableRecords(): array {
		$records = array();
		$unconf  = $this->storedUnconfirmed();
		$pending = $this->storedClaim();

		foreach ( array_merge( null === $unconf ? array() : array( $unconf ), null === $pending ? array() : array( $pending ), $this->storedArchive() ) as $record ) {
			if ( ! isset( $records[ $record['claim_id'] ] ) ) {
				$records[ $record['claim_id'] ] = $record;
			}
		}

		return array_values( $records );
	}

	/**
	 * Запись заявки по номеру из любой опции; приоритет у той, где известен момент получения токена.
	 *
	 * @param string $claimId Номер заявки.
	 * @return array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int}|null
	 */
	private function findRecord( string $claimId ): ?array {
		$found = null;

		foreach ( $this->resumableRecords() as $record ) {
			if ( $record['claim_id'] === $claimId && ( null === $found || $record['received_at'] > $found['received_at'] ) ) {
				$found = $record;
			}
		}

		return $found;
	}

	/**
	 * Атомарный замок с владельцем: add_option возвращает false, если опция уже есть.
	 *
	 * В опции лежит «владелец|момент, до которого замок действует». Просроченный замок (упавший запрос)
	 * перехватывается атомарной заменой `UPDATE ... WHERE option_value = <старое значение>`: из двух
	 * претендентов выигрывает ровно один. Замок со старым форматом (одно число) читается как ничей.
	 *
	 * @return string|null Значение замка этого опроса (для освобождения) или null, если замок занят.
	 */
	private function acquireClaimLock(): ?string {
		global $wpdb;

		$now   = time();
		$value = bin2hex( random_bytes( 8 ) ) . '|' . ( $now + self::CLAIM_LOCK_TTL );

		$this->flushLockCache();

		if ( add_option( self::CLAIM_LOCK_OPTION, $value, '', false ) ) {
			return $value;
		}

		$this->flushLockCache();

		$current = get_option( self::CLAIM_LOCK_OPTION, false );

		if ( false === $current ) {
			return add_option( self::CLAIM_LOCK_OPTION, $value, '', false ) ? $value : null;
		}

		$parts = explode( '|', (string) $current );

		if ( (int) end( $parts ) > $now ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- атомарная замена замка, API опций такого не умеет.
		$rows = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$value,
				self::CLAIM_LOCK_OPTION,
				(string) $current
			)
		);

		$this->flushLockCache();

		return 1 === (int) $rows ? $value : null;
	}

	/**
	 * Снимает замок, только если он всё ещё наш (иначе его уже перехватил другой опрос).
	 *
	 * @param string $owner Значение, которое вернул acquireClaimLock().
	 */
	private function releaseClaimLock( string $owner ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- условное снятие замка по владельцу.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::CLAIM_LOCK_OPTION,
				$owner
			)
		);

		$this->flushLockCache();
	}

	/**
	 * Сбрасывает кэш опции замка: запись идёт в базу мимо API опций, читать нужно свежее.
	 */
	private function flushLockCache(): void {
		wp_cache_delete( self::CLAIM_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Удаляет исходные записи заявки (ожидающую и архивные), оставляя запись неподтверждённой.
	 *
	 * @param string $claimId Номер заявки.
	 */
	private function dropSources( string $claimId ): void {
		$current = get_option( self::CLAIM_OPTION, array() );

		if ( is_array( $current ) && (string) ( $current['claim_id'] ?? '' ) === $claimId ) {
			delete_option( self::CLAIM_OPTION );
		}

		$archive = $this->storedArchive();
		$kept    = array_values( array_filter( $archive, static fn( array $entry ): bool => $entry['claim_id'] !== $claimId ) );

		if ( count( $kept ) !== count( $archive ) ) {
			if ( array() === $kept ) {
				delete_option( self::ARCHIVE_OPTION );
			} else {
				update_option( self::ARCHIVE_OPTION, $kept, false );
			}
		}
	}

	/**
	 * Удаляет заявку с этим номером из текущей опции, из архива и из неподтверждённых.
	 *
	 * @param string $claimId Номер заявки.
	 */
	private function forgetClaim( string $claimId ): void {
		$unconfirmed = get_option( self::UNCONFIRMED_OPTION, array() );

		if ( is_array( $unconfirmed ) && (string) ( $unconfirmed['claim_id'] ?? '' ) === $claimId ) {
			delete_option( self::UNCONFIRMED_OPTION );
		}

		$this->dropSources( $claimId );
	}

	/**
	 * Можно ли ещё хранить заявку: до max(expires_at, получение токена) + окно восстановления + запас.
	 *
	 * Заявка с потерянным ответом остаётся восстановимой после своего `expires_at`, поэтому
	 * локальный срок сам по себе её не удаляет: окончательно решает ответ Cloud.
	 *
	 * @param array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int} $claim Заявка.
	 */
	private static function isRetained( array $claim ): bool {
		return time() <= max( $claim['expires_at'], $claim['received_at'] ) + self::RECOVERY_WINDOW + self::CONFIRM_GRACE;
	}

	/**
	 * Запись неподтверждённой заявки; повреждённая или с вышедшим сроком хранения удаляется.
	 *
	 * @return array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int}|null
	 */
	private function storedUnconfirmed(): ?array {
		$stored = get_option( self::UNCONFIRMED_OPTION, array() );

		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}

		$claim = self::normalizeClaim( $stored );

		if ( null === $claim || ! self::isRetained( $claim ) ) {
			delete_option( self::UNCONFIRMED_OPTION );

			return null;
		}

		return $claim;
	}

	/**
	 * Архив отменённых заявок без повреждённых записей и записей с вышедшим сроком хранения.
	 *
	 * Очищенный список записывается обратно (пустой — опция удаляется), иначе секреты лежали бы вечно.
	 *
	 * @return list<array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int}>
	 */
	private function storedArchive(): array {
		$stored = get_option( self::ARCHIVE_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$result  = array();
		$changed = false;

		foreach ( $stored as $entry ) {
			$claim = is_array( $entry ) ? self::normalizeClaim( $entry ) : null;

			if ( null !== $claim && self::isRetained( $claim ) && count( $result ) < self::ARCHIVE_MAX ) {
				$result[] = $claim;
			} else {
				$changed = true;
			}
		}

		if ( $changed ) {
			if ( array() === $result ) {
				delete_option( self::ARCHIVE_OPTION );
			} else {
				update_option( self::ARCHIVE_OPTION, $result, false );
			}
		}

		return $result;
	}

	/**
	 * Приводит запись заявки к единому виду или возвращает null, если она повреждена.
	 *
	 * @param array<mixed> $claim Запись из опции.
	 * @return array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int}|null
	 */
	private static function normalizeClaim( array $claim ): ?array {
		$claimId = (string) ( $claim['claim_id'] ?? '' );
		$secret  = (string) ( $claim['secret'] ?? '' );

		if ( '' === $claimId || '' === $secret ) {
			return null;
		}

		return array(
			'claim_id'     => $claimId,
			'secret'       => $secret,
			'expires_at'   => (int) ( $claim['expires_at'] ?? 0 ),
			'checkout_url' => (string) ( $claim['checkout_url'] ?? '' ),
			'received_at'  => (int) ( $claim['received_at'] ?? 0 ),
		);
	}

	/**
	 * Состояние аккаунта (тариф, остаток символов, подписка).
	 *
	 * @return array<string, mixed>
	 * @throws CloudException При ошибке API или сети.
	 */
	public function account(): array {
		return $this->request( 'GET', '/v1/account', null, true, self::DEFAULT_TIMEOUT );
	}

	/**
	 * Переводит пачку строк.
	 *
	 * @param string               $requestId   Идентификатор запроса: генерируется один раз и повторяется при ретраях.
	 * @param string               $source      Исходный язык.
	 * @param string               $target      Целевой язык.
	 * @param string|null          $label       Название целевого языка для человека.
	 * @param array<string,string> $items       Хеш => текст.
	 * @param array<string,string> $glossary    Термин => обязательный перевод.
	 * @return array<string, mixed> Ответ API (translations, missing, quota …).
	 * @throws CloudException При ошибке API или сети.
	 */
	public function translate( string $requestId, string $source, string $target, ?string $label, array $items, array $glossary = array() ): array {
		$body = array(
			'request_id'    => $requestId,
			'source_locale' => $source,
			'target_locale' => $target,
			'items'         => (object) $items,
		);

		if ( null !== $label && '' !== trim( $label ) ) {
			$body['target_label'] = mb_substr( $label, 0, 64 );
		}

		if ( array() !== $glossary ) {
			$body['glossary'] = (object) $glossary;
		}

		return $this->request( 'POST', '/v1/translate', $body, true, self::TRANSLATE_TIMEOUT );
	}

	/**
	 * Адрес оплаты пакета символов или подписки.
	 *
	 * @param string $product `pack_250k` или `starter`.
	 * @throws CloudException При ошибке API, сети или недопустимом адресе.
	 */
	public function checkout( string $product ): string {
		if ( ! in_array( $product, self::PRODUCTS, true ) ) {
			throw new CloudException( 'validation_error', 422 );
		}

		$data = $this->request( 'POST', '/v1/billing/checkout', array( 'product' => $product ), true, self::DEFAULT_TIMEOUT );

		return self::stripeUrl( $data['checkout_url'] ?? null );
	}

	/**
	 * Адрес портала Stripe (карта, отмена, счета).
	 *
	 * @throws CloudException При ошибке API, сети или недопустимом адресе.
	 */
	public function portal(): string {
		$data = $this->request( 'POST', '/v1/billing/portal', new \stdClass(), true, self::DEFAULT_TIMEOUT );

		return self::stripeUrl( $data['url'] ?? null );
	}

	/**
	 * Пропускает только https-адреса на stripe.com и его поддомены.
	 *
	 * @param mixed $url Адрес из ответа API.
	 * @throws CloudException `bad_redirect`, если адрес не подходит.
	 */
	public static function stripeUrl( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			throw new CloudException( 'bad_redirect' );
		}

		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';

		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ! empty( $parts['user'] )
			|| ! empty( $parts['pass'] )
			|| ( 'stripe.com' !== $host && ! str_ends_with( $host, '.stripe.com' ) )
		) {
			throw new CloudException( 'bad_redirect' );
		}

		return $url;
	}

	/**
	 * Сохранённая заявка; повреждённая или с вышедшим сроком хранения удаляется.
	 *
	 * @return array{claim_id: string, secret: string, expires_at: int, checkout_url: string, received_at: int}|null
	 */
	private function storedClaim(): ?array {
		$stored = get_option( self::CLAIM_OPTION, array() );

		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}

		$claim = self::normalizeClaim( $stored );

		if ( null === $claim || ! self::isRetained( $claim ) ) {
			delete_option( self::CLAIM_OPTION );

			return null;
		}

		return $claim;
	}

	/**
	 * Выполняет запрос и разбирает JSON-ответ.
	 *
	 * @param string                    $method  HTTP-метод.
	 * @param string                    $path    Путь, начинается с «/».
	 * @param array<string, mixed>|object|null $body Тело (для GET — null).
	 * @param bool                      $auth    Нужен ли Bearer-токен.
	 * @param int                       $timeout Таймаут, секунд.
	 * @param int|null                  $status  Сюда пишется HTTP-статус успешного ответа.
	 * @param string|null               $bearer  Токен вместо токена клиента (только что полученный).
	 * @return array<string, mixed>
	 * @throws CloudException При любой ошибке.
	 */
	private function request( string $method, string $path, array|object|null $body, bool $auth, int $timeout, ?int &$status = null, ?string $bearer = null ): array {
		if ( '' === Settings::sanitizeCloudApiUrl( $this->baseUrl ) ) {
			throw new CloudException( 'bad_config' );
		}

		$token = $bearer ?? $this->token;

		if ( $auth && '' === $token ) {
			throw new CloudException( 'invalid_token', 401 );
		}

		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'LingoCanvas/' . ( defined( 'LINGOCANVAS_VERSION' ) ? (string) LINGOCANVAS_VERSION : '0' ) . '; ' . $this->homeHost(),
		);

		if ( $auth ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( rtrim( $this->baseUrl, '/' ) . $path, $args );

		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );
			$timedOut = str_contains( $message, 'timed out' ) || str_contains( $message, 'curl error 28' ) || str_contains( $message, 'timeout' );

			$networkError = CloudException::make( $timedOut ? CloudException::TIMEOUT : CloudException::NETWORK_ERROR );

			throw $networkError;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : null;

		if ( $status < 200 || $status >= 300 ) {
			$error      = is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();
			$code       = is_string( $error['code'] ?? null ) && '' !== $error['code'] ? $error['code'] : 'http_' . $status;
			$retryAfter = wp_remote_retrieve_header( $response, 'retry-after' );
			$extra      = array_diff_key( $error, array_flip( array( 'code', 'message' ) ) );

			$apiError = CloudException::make(
				$code,
				$status,
				is_string( $retryAfter ) && ctype_digit( $retryAfter ) ? (int) $retryAfter : null,
				$extra
			);

			throw $apiError;
		}

		if ( null === $decoded ) {
			$badBody = CloudException::make( 'bad_response', $status );

			throw $badBody;
		}

		return $decoded;
	}

	/**
	 * Домен сайта для User-Agent.
	 */
	private function homeHost(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? $host : '';
	}
}
