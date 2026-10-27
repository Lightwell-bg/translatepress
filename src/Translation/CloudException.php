<?php
/**
 * Ошибка обращения к LingoCanvas Cloud.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Ошибка API Cloud или сети. Сообщение исключения — только код ошибки:
 * ни токена, ни тел запросов и ответов в нём никогда нет.
 */
final class CloudException extends \RuntimeException {

	/** Сетевая ошибка (нет связи, DNS, TLS). */
	public const NETWORK_ERROR = 'network_error';

	/** Таймаут запроса: результат неизвестен («возможно, выполнено»). */
	public const TIMEOUT = 'timeout';

	/**
	 * @param string               $errorCode  Код из `{"error":{"code"}}` или внутренний код клиента.
	 * @param int                  $httpStatus HTTP-статус (0 — ответа не было).
	 * @param int|null             $retryAfter Заголовок Retry-After, секунд.
	 * @param array<string, mixed> $extra      Доп. поля ошибки (needed, available и т.п.).
	 */
	public function __construct(
		private readonly string $errorCode,
		private readonly int $httpStatus = 0,
		private readonly ?int $retryAfter = null,
		private readonly array $extra = array()
	) {
		parent::__construct( $errorCode, $httpStatus );
	}

	/**
	 * Создаёт исключение. Сообщение исключения — только код ошибки, наружу (HTML) оно не выводится:
	 * владельцу показывается `userMessage()`. Фабрика нужна, чтобы `throw` не выглядел как вывод
	 * неэкранированного значения для проверки WordPress.org.
	 *
	 * @param string               $errorCode  Код ошибки.
	 * @param int                  $httpStatus HTTP-статус (0 — ответа не было).
	 * @param int|null             $retryAfter Retry-After, секунд.
	 * @param array<string, mixed> $extra      Доп. поля ошибки.
	 */
	public static function make( string $errorCode, int $httpStatus = 0, ?int $retryAfter = null, array $extra = array() ): self {
		return new self( $errorCode, $httpStatus, $retryAfter, $extra );
	}

	/**
	 * Код ошибки API.
	 */
	public function errorCode(): string {
		return $this->errorCode;
	}

	/**
	 * HTTP-статус ответа (0 — ответа не было).
	 */
	public function httpStatus(): int {
		return $this->httpStatus;
	}

	/**
	 * Сколько секунд сервер просит подождать, если он это указал.
	 */
	public function retryAfter(): ?int {
		return $this->retryAfter;
	}

	/**
	 * Дополнительные поля ошибки.
	 *
	 * @return array<string, mixed>
	 */
	public function extra(): array {
		return $this->extra;
	}

	/**
	 * Понятное владельцу сайта объяснение ошибки (исходные строки — английские).
	 */
	public function userMessage(): string {
		// Единственный текст с номером заявки: номер не секрет, секрет заявки сюда не попадает.
		if ( 'already_claimed_lost' === $this->errorCode ) {
			return sprintf(
				/* translators: %s: payment claim ID to quote to support. */
				__( 'Payment is confirmed, but the connection key was already issued and not received by this site. Contact support and quote this claim ID: %s', 'lingocanvas' ),
				(string) ( $this->extra['claim_id'] ?? '' )
			);
		}

		// Сервер просит подождать дольше, чем мы готовы ждать внутри запроса: говорим, через сколько повторить.
		if ( isset( $this->extra['busy_seconds'] ) && 'service_budget_exhausted' !== $this->errorCode ) {
			return sprintf(
				/* translators: %d: number of seconds to wait. */
				__( 'LingoCanvas Cloud is busy, try again in %d seconds.', 'lingocanvas' ),
				max( 1, (int) $this->extra['busy_seconds'] )
			);
		}

		return self::messageFor( $this->errorCode );
	}

	/**
	 * Текст для кода ошибки.
	 *
	 * @param string $code Код ошибки Cloud.
	 */
	public static function messageFor( string $code ): string {
		switch ( $code ) {
			case 'insufficient_quota':
				return __( 'Your LingoCanvas Cloud characters are used up. Buy an extra pack or wait for the renewal.', 'lingocanvas' );

			case 'invalid_token':
				return __( 'This site is no longer connected to LingoCanvas Cloud. Connect it again in Settings.', 'lingocanvas' );

			case 'account_suspended':
				return __( 'Your LingoCanvas Cloud account is suspended. Please contact support.', 'lingocanvas' );

			case 'subscription_required':
				return __( 'An active LingoCanvas Cloud subscription is required.', 'lingocanvas' );

			case 'rate_limited':
			case 'too_many_in_flight':
				return __( 'LingoCanvas Cloud is busy, try again in a minute.', 'lingocanvas' );

			case 'service_budget_exhausted':
			case 'provider_error':
			case 'provider_bad_output':
			case 'provider_busy':
			case 'provider_unavailable':
			case 'provider_timeout':
			case 'internal_error':
			case self::TIMEOUT:
			case self::NETWORK_ERROR:
				return __( 'LingoCanvas Cloud is temporarily unavailable. Please try again later.', 'lingocanvas' );

			case 'payload_too_large':
				return __( 'This text is too long for one request.', 'lingocanvas' );

			case 'already_subscribed':
				return __( 'This site already has an active LingoCanvas Cloud subscription. Use "Manage subscription" to change it.', 'lingocanvas' );

			case 'no_billing_account':
				return __( 'There is no billing account for this site yet. Subscribe first.', 'lingocanvas' );

			case 'portal_not_configured':
				return __( 'The subscription management page is not available yet. Please contact support.', 'lingocanvas' );

			case 'billing_disabled':
			case 'billing_provider_error':
				return __( 'Payments are temporarily unavailable. Please try again later.', 'lingocanvas' );

			case 'claim_expired':
				return __( 'The payment session has expired. Start the subscription again.', 'lingocanvas' );

			case 'site_limit_reached':
				return __( 'Your LingoCanvas Cloud plan has reached its limit of connected sites.', 'lingocanvas' );

			case 'token_not_saved':
				return __( 'The connection key could not be saved on this site. Check that the database is writable and contact support.', 'lingocanvas' );

			case 'consent_required':
				return __( 'Please confirm that you agree to send texts to LingoCanvas Cloud.', 'lingocanvas' );

			case 'bad_redirect':
				return __( 'LingoCanvas Cloud returned an unexpected payment address. Please try again later.', 'lingocanvas' );

			default:
				return __( 'LingoCanvas Cloud could not process the request.', 'lingocanvas' );
		}
	}
}
