<?php
/**
 * Действия владельца сайта с LingoCanvas Cloud: подписка, оплата, подключение.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Settings\Settings;
use LingoCanvas\Translation\CloudException;
use LingoCanvas\Translation\ProviderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Общая логика для обработчиков admin-post и REST. Ничего не знает про HTTP-ответ
 * WordPress: возвращает простые массивы, а редиректы делает вызывающий код.
 * Сообщения для владельца всегда берутся из `CloudException::messageFor()`:
 * сырой текст сервера в интерфейс не попадает.
 */
final class CloudActions {

	/** Опция-замок регистрации: до какого момента (Unix) другой клик не создаёт вторую заявку. */
	public const SIGNUP_LOCK_OPTION = 'lingocanvas_cloud_signup_lock';

	/**
	 * @param ProviderFactory $providers Токен, адрес API и клиент Cloud.
	 * @param Settings        $settings  Настройки плагина.
	 * @param CloudStatus     $status    Кэш состояния аккаунта.
	 */
	public function __construct(
		private readonly ProviderFactory $providers,
		private readonly Settings $settings,
		private readonly CloudStatus $status
	) {
	}

	/**
	 * Название сайта для регистрации: домен из адреса сайта.
	 */
	public function siteName(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $host : 'wordpress';
	}

	/**
	 * Начинает подписку: возвращает адрес оплаты в Stripe.
	 *
	 * Согласие сохраняется здесь же: оплата — явное действие владельца, а текст
	 * о передаче данных показан рядом с кнопкой.
	 *
	 * @param string $email          Почта (необязательно, уже очищенная).
	 * @param bool   $consentPosted  В форме отмечено согласие.
	 * @return array{ok: bool, url: string, message: string}
	 */
	public function signup( string $email, bool $consentPosted ): array {
		if ( '' !== $this->providers->cloudToken() ) {
			return $this->failure( __( 'This site is already connected to LingoCanvas Cloud.', 'lingocanvas' ) );
		}

		// Заявка уже есть (двойной клик, вторая вкладка): ведём на тот же платёж, второй не создаём.
		$reuse = $this->reusePendingCheckout();

		if ( null !== $reuse ) {
			return $reuse;
		}

		if ( $this->settings->cloudConsentAt() <= 0 && ! $consentPosted ) {
			return $this->failure( __( 'Please confirm that you agree to send texts to LingoCanvas Cloud.', 'lingocanvas' ) );
		}

		if ( '' !== $email && ! is_email( $email ) ) {
			return $this->failure( __( 'Please enter a valid e-mail address or leave the field empty.', 'lingocanvas' ) );
		}

		// Одновременные клики: платёж создаёт только тот, кто взял замок.
		if ( ! $this->acquireSignupLock() ) {
			return $this->failure( __( 'Sign-up is already in progress, please wait a few seconds.', 'lingocanvas' ) );
		}

		try {
			// Пока мы брали замок, другой запрос мог уже завершить регистрацию.
			$reuse = $this->reusePendingCheckout();

			if ( null !== $reuse ) {
				return $reuse;
			}

			if ( $this->settings->cloudConsentAt() <= 0 ) {
				$this->settings->saveCloud( array( 'cloud_consent_at' => time() ) );
			}

			$data = $this->providers->cloudClient()->signup( $this->siteName(), '' !== $email ? $email : null );
		} catch ( CloudException $e ) {
			return $this->failure( $e->userMessage() );
		} finally {
			delete_option( self::SIGNUP_LOCK_OPTION );
		}

		return array(
			'ok'      => true,
			'url'     => $data['checkout_url'],
			'message' => '',
		);
	}

	/**
	 * Есть ли согласие владельца на отправку текстов в Cloud.
	 *
	 * Без него плагин не делает к Cloud никаких обращений: ни статуса, ни опроса оплаты, ни кнопок оплаты.
	 */
	public function hasConsent(): bool {
		return $this->settings->cloudConsentAt() > 0;
	}

	/**
	 * Результат для уже созданной заявки: тот же адрес оплаты или отказ, если адреса нет.
	 *
	 * @return array{ok: bool, url: string, message: string}|null null — заявки нет.
	 */
	private function reusePendingCheckout(): ?array {
		$client = $this->providers->cloudClient();

		$pending = $client->pendingClaim();

		// Заявка с вышедшим сроком оплаты новой оплате не мешает (её секрет остаётся в архиве).
		if ( null === $pending || $pending['expires_at'] <= time() ) {
			return null;
		}

		$url = $client->pendingCheckoutUrl();

		if ( null === $url ) {
			return $this->failure( __( 'A payment is already waiting for confirmation. Check it or cancel it first.', 'lingocanvas' ) );
		}

		return array(
			'ok'      => true,
			'url'     => $url,
			'message' => '',
		);
	}

	/**
	 * Атомарный замок регистрации: add_option возвращает false, если опция уже есть.
	 *
	 * В опции лежит момент, до которого замок действует (120 секунд); просроченный замок
	 * (упавший запрос) считается свободным.
	 */
	private function acquireSignupLock(): bool {
		$until = time() + 120;

		if ( add_option( self::SIGNUP_LOCK_OPTION, $until, '', false ) ) {
			return true;
		}

		if ( (int) get_option( self::SIGNUP_LOCK_OPTION, 0 ) > time() ) {
			return false;
		}

		delete_option( self::SIGNUP_LOCK_OPTION );

		return add_option( self::SIGNUP_LOCK_OPTION, $until, '', false );
	}

	/**
	 * Адрес оплаты пакета символов или повторной подписки.
	 *
	 * @param string $product `pack_250k` или `starter`.
	 * @return array{ok: bool, url: string, message: string}
	 */
	public function checkout( string $product ): array {
		if ( '' === $this->providers->cloudToken() ) {
			return $this->failure( CloudException::messageFor( 'invalid_token' ) );
		}

		if ( ! $this->hasConsent() ) {
			return $this->failure( CloudException::messageFor( 'consent_required' ) );
		}

		try {
			$url = $this->providers->cloudClient()->checkout( $product );
		} catch ( CloudException $e ) {
			return $this->failure( $e->userMessage() );
		}

		$this->status->forget();

		return array(
			'ok'      => true,
			'url'     => $url,
			'message' => '',
		);
	}

	/**
	 * Адрес портала Stripe (карта, отмена, счета).
	 *
	 * @return array{ok: bool, url: string, message: string}
	 */
	public function portal(): array {
		if ( '' === $this->providers->cloudToken() ) {
			return $this->failure( CloudException::messageFor( 'invalid_token' ) );
		}

		if ( ! $this->hasConsent() ) {
			return $this->failure( CloudException::messageFor( 'consent_required' ) );
		}

		try {
			$url = $this->providers->cloudClient()->portal();
		} catch ( CloudException $e ) {
			return $this->failure( $e->userMessage() );
		}

		return array(
			'ok'      => true,
			'url'     => $url,
			'message' => '',
		);
	}

	/**
	 * Забирает токен после оплаты. При успехе включает Cloud как провайдера.
	 *
	 * Если токен уже получен (параллельный опрос из другой вкладки), это тоже «подключено».
	 *
	 * @return array{status: string, message: string} `pending`, `connected`, `no_consent` или `error`.
	 */
	public function claim(): array {
		return $this->runClaim( false );
	}

	/**
	 * Проверяет оплату по отменённой, но ещё не истёкшей заявке («Уже оплатили? Проверить ещё раз»).
	 *
	 * @return array{status: string, message: string}
	 */
	public function claimArchived(): array {
		return $this->runClaim( true );
	}

	/**
	 * Повторяет подтверждение получения токена (если прошлое не удалось). Без согласия владельца ничего не делает.
	 */
	public function retryPendingConfirm(): void {
		if ( $this->hasConsent() ) {
			$this->providers->cloudClient()->retryPendingConfirm();
		}
	}

	/**
	 * Общая часть проверки оплаты.
	 *
	 * @param bool $archived Проверять архивную заявку, а не текущую.
	 * @return array{status: string, message: string}
	 */
	private function runClaim( bool $archived ): array {
		if ( '' !== $this->providers->cloudToken() ) {
			// Токен уже есть; если подтверждение его получения не дошло до Cloud, повторяем его здесь.
			$this->retryPendingConfirm();

			return array(
				'status'  => 'connected',
				'message' => __( 'LingoCanvas Cloud is connected.', 'lingocanvas' ),
			);
		}

		// Без согласия никаких обращений к Cloud, в том числе автоматического опроса оплаты.
		if ( ! $this->hasConsent() ) {
			return array(
				'status'  => 'no_consent',
				'message' => CloudException::messageFor( 'consent_required' ),
			);
		}

		try {
			$result = $archived ? $this->providers->cloudClient()->claimArchived() : $this->providers->cloudClient()->claim();
		} catch ( CloudException $e ) {
			// Параллельный опрос уже забрал токен: заявка удалена, но подключение есть.
			// Исключение: «токен выдан, а у сайта его нет» (already_claimed_lost) успехом не считается.
			if ( 'already_claimed_lost' !== $e->errorCode() && '' !== ( new Settings() )->cloudToken() ) {
				return array(
					'status'  => 'connected',
					'message' => __( 'LingoCanvas Cloud is connected.', 'lingocanvas' ),
				);
			}

			return array(
				'status'  => 'error',
				'message' => $e->userMessage(),
			);
		}

		if ( 'connected' !== $result['status'] ) {
			return array(
				'status'  => 'pending',
				'message' => __( 'The payment is not confirmed yet.', 'lingocanvas' ),
			);
		}

		$this->settings->saveCloud( array( 'provider' => Settings::PROVIDER_CLOUD ) );
		$this->status->forget();

		return array(
			'status'  => 'connected',
			'message' => __( 'LingoCanvas Cloud is connected.', 'lingocanvas' ),
		);
	}

	/**
	 * Отменяет ожидающую заявку на этом сайте.
	 *
	 * Сначала один раз проверяется оплата (владелец мог заплатить, а подтверждение ещё в пути):
	 * если токен выдан, сайт подключается как обычно. Иначе заявка не удаляется, а уходит в
	 * архив до своего срока, чтобы оплату можно было проверить позже.
	 *
	 * @return array{status: string, message: string} `connected` или `canceled`.
	 */
	public function cancelClaim(): array {
		$client = $this->providers->cloudClient();

		if ( null !== $client->pendingClaim() && $this->hasConsent() ) {
			$check = $this->claim();

			if ( 'connected' === $check['status'] ) {
				return $check;
			}
		}

		$client->archivePendingClaim();

		return array(
			'status'  => 'canceled',
			'message' => __( 'The pending payment was canceled on this site.', 'lingocanvas' ),
		);
	}

	/**
	 * Отключает сайт: токен удаляется только здесь, подписка в Cloud НЕ отменяется.
	 *
	 * Переводы в базе не трогаются, провайдер возвращается к своему ключу.
	 * Токен из константы wp-config.php снять из интерфейса нельзя.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function disconnect(): array {
		if ( $this->providers->isOverriddenByConstant( ProviderFactory::CONST_CLOUD_TOKEN ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The site token is set in wp-config.php. Remove LINGOCANVAS_CLOUD_TOKEN there to disconnect.', 'lingocanvas' ),
			);
		}

		$this->status->forget();
		$this->settings->saveCloud(
			array(
				'cloud_token' => '',
				'provider'    => Settings::PROVIDER_OPENAI,
			)
		);

		return array(
			'ok'      => true,
			'message' => __( 'The site is disconnected from LingoCanvas Cloud. The subscription was not canceled: use "Manage subscription" to cancel it.', 'lingocanvas' ),
		);
	}

	/**
	 * Результат-отказ.
	 *
	 * @param string $message Сообщение для владельца.
	 * @return array{ok: bool, url: string, message: string}
	 */
	private function failure( string $message ): array {
		return array(
			'ok'      => false,
			'url'     => '',
			'message' => $message,
		);
	}
}
