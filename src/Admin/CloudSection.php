<?php
/**
 * Раздел «LingoCanvas Cloud» на странице настроек: разметка и обработчики admin-post.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Rest\CloudController;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Support\Assets;
use LingoCanvas\Support\Hookable;
use LingoCanvas\Translation\CloudClient;
use LingoCanvas\Translation\CloudException;
use LingoCanvas\Translation\ProviderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Выбор провайдера перевода и всё, что связано с платным сервисом.
 *
 * Разметку строят `providerHtml()`, `cloudHtml()` и `formsHtml()`; страница
 * настроек выводит её через `wp_kses( ..., AllowedHtml::cloudSection() )`.
 * Кнопки действий лежат внутри общей таблицы настроек, но отправляют свои
 * отдельные формы (атрибут `form`): вложенные формы в HTML недопустимы.
 * Токен сайта никогда не выводится в разметку и не передаётся в JS.
 */
final class CloudSection implements Hookable {

	public const ACTION_SIGNUP     = 'lingocanvas_cloud_signup';
	public const ACTION_CLAIM      = 'lingocanvas_cloud_claim';
	public const ACTION_CANCEL     = 'lingocanvas_cloud_cancel';
	public const ACTION_CLAIM_OLD  = 'lingocanvas_cloud_claim_archived';
	public const ACTION_CHECKOUT   = 'lingocanvas_cloud_checkout';
	public const ACTION_PORTAL     = 'lingocanvas_cloud_portal';
	public const ACTION_DISCONNECT = 'lingocanvas_cloud_disconnect';

	/** Префикс transient с сообщением после действия (на пользователя). */
	public const NOTICE_PREFIX = 'lingocanvas_cloud_notice_';

	/** Якорь раздела на странице настроек. */
	public const ANCHOR = 'lingocanvas-cloud';

	/** Условия и политика конфиденциальности сервиса. */
	public const TERMS_URL   = 'https://centerai.eu/lingocanvas/terms/';
	public const PRIVACY_URL = 'https://centerai.eu/lingocanvas/privacy/';

	/**
	 * @param Settings        $settings  Настройки плагина.
	 * @param ProviderFactory $providers Токен, согласие, клиент Cloud.
	 * @param CloudActions    $actions   Действия с Cloud.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderFactory $providers,
		private readonly CloudActions $actions
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_SIGNUP, array( $this, 'handleSignup' ) );
		add_action( 'admin_post_' . self::ACTION_CLAIM, array( $this, 'handleClaim' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( $this, 'handleCancel' ) );
		add_action( 'admin_post_' . self::ACTION_CLAIM_OLD, array( $this, 'handleClaimArchived' ) );
		add_action( 'admin_post_' . self::ACTION_CHECKOUT, array( $this, 'handleCheckout' ) );
		add_action( 'admin_post_' . self::ACTION_PORTAL, array( $this, 'handlePortal' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handleDisconnect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Скрипт раздела: статус, опрос оплаты, подтверждение отключения.
	 *
	 * @param mixed $hook Идентификатор текущего экрана.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, SettingsPage::MENU_SLUG ) ) {
			return;
		}

		// Токен получен, но подтверждение не дошло до Cloud: повторяем при открытии настроек.
		$this->actions->retryPendingConfirm();

		wp_enqueue_script(
			'lingocanvas-cloud',
			Assets::url( 'assets/cloud.js' ),
			array(),
			Assets::version( 'assets/cloud.js' ),
			true
		);

		$consent = $this->settings->cloudConsentAt() > 0;
		$claim   = $this->hasToken() ? null : $this->providers->cloudClient()->pendingClaim();

		wp_localize_script(
			'lingocanvas-cloud',
			'lingocanvasCloud',
			array(
				'statusUrl'  => esc_url_raw( rest_url( CloudController::NAMESPACE . '/cloud/status' ) ),
				'claimUrl'   => esc_url_raw( rest_url( CloudController::NAMESPACE . '/cloud/claim' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'connected'  => $this->hasToken(),
				// Без согласия скрипт не обращается к Cloud: ни статус, ни опрос оплаты.
				'consent'    => $consent,
				'pending'    => null !== $claim && $consent,
				'expiresIn'  => null !== $claim ? max( 0, $claim['expires_at'] - time() ) : 0,
				'i18n'       => array(
					'loading'   => __( 'Loading status…', 'lingocanvas' ),
					'failed'    => __( 'Could not load the status.', 'lingocanvas' ),
					'checking'  => __( 'Checking the payment…', 'lingocanvas' ),
					'timeout'   => __( 'The payment has not been confirmed yet. Use "Check payment" later.', 'lingocanvas' ),
					'connected' => __( 'LingoCanvas Cloud is connected.', 'lingocanvas' ),
					'noPack'    => __( 'Extra packs can be bought only with an active subscription.', 'lingocanvas' ),
				),
			)
		);
	}

	// --- Разметка ---------------------------------------------------------------

	/**
	 * Переключатель провайдера перевода.
	 */
	public function providerHtml(): string {
		$current = $this->settings->provider();

		$radio = static fn( string $value, string $label ): string => sprintf(
			'<p><label><input type="radio" name="provider" value="%1$s"%2$s> %3$s</label></p>',
			esc_attr( $value ),
			Settings::sanitizeProvider( $current, Settings::PROVIDER_OPENAI ) === $value ? ' checked="checked"' : '',
			esc_html( $label )
		);

		return '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'AI translation provider', 'lingocanvas' ) . '</span></legend>'
			. $radio( Settings::PROVIDER_OPENAI, __( 'Own API key (OpenAI-compatible)', 'lingocanvas' ) )
			. $radio( Settings::PROVIDER_CLOUD, __( 'LingoCanvas Cloud (paid service by CenterAI)', 'lingocanvas' ) )
			. '</fieldset>';
	}

	/**
	 * Блок Cloud: раскрытие данных, согласие и состояние (регистрация / ожидание оплаты / подключено).
	 */
	public function cloudHtml(): string {
		$hasToken = $this->hasToken();
		$claim    = $hasToken ? null : $this->providers->cloudClient()->pendingClaim();
		$consent  = $this->settings->cloudConsentAt() > 0;

		$html = '<div class="lingocanvas-cloud" id="' . esc_attr( self::ANCHOR ) . '">' . $this->disclosureHtml();

		// Пока согласия нет и сайт не подключён, галочка стоит рядом с кнопкой подписки.
		if ( $hasToken || $consent || null !== $claim ) {
			$html .= $this->consentHtml( $consent );
		}

		if ( $hasToken ) {
			$html .= $this->connectedHtml();
		} elseif ( null !== $claim ) {
			$html .= $this->pendingHtml();
		} else {
			$html .= $this->signupHtml( $consent );
			$html .= $this->archivedHtml( $consent );
		}

		return $html . '</div>';
	}

	/**
	 * Отдельные формы действий. Выводятся после главной формы настроек.
	 */
	public function formsHtml(): string {
		$hasToken = $this->hasToken();
		$claim    = $hasToken ? null : $this->providers->cloudClient()->pendingClaim();

		if ( $hasToken ) {
			$html = $this->formHtml( self::ACTION_CHECKOUT, 'checkout-pack', 'pack_250k' )
				. $this->formHtml( self::ACTION_CHECKOUT, 'checkout-starter', 'starter' )
				. $this->formHtml( self::ACTION_PORTAL, 'portal' );

			if ( ! $this->providers->isOverriddenByConstant( ProviderFactory::CONST_CLOUD_TOKEN ) ) {
				$html .= $this->formHtml( self::ACTION_DISCONNECT, 'disconnect' );
			}

			return $html;
		}

		if ( null !== $claim ) {
			return $this->formHtml( self::ACTION_CLAIM, 'claim' ) . $this->formHtml( self::ACTION_CANCEL, 'cancel' );
		}

		$html = $this->formHtml( self::ACTION_SIGNUP, 'signup' );

		if ( $this->hasArchivedClaims() ) {
			$html .= $this->formHtml( self::ACTION_CLAIM_OLD, 'claim-archived' );
		}

		return $html;
	}

	/**
	 * Есть ли отменённые, но ещё не истёкшие заявки, оплату по которым можно проверить (нужно согласие).
	 */
	private function hasArchivedClaims(): bool {
		return $this->settings->cloudConsentAt() > 0 && array() !== $this->providers->cloudClient()->archivedClaims();
	}

	/**
	 * Кнопка «Уже оплатили? Проверить ещё раз» для отменённой заявки.
	 *
	 * @param bool $consent Согласие дано (без него обращений к Cloud нет).
	 */
	private function archivedHtml( bool $consent ): string {
		if ( ! $consent || ! $this->hasArchivedClaims() ) {
			return '';
		}

		return '<p class="description">' . esc_html__( 'You canceled a payment on this site. If you had already paid, the confirmation can still be received until the payment session expires.', 'lingocanvas' ) . '</p>'
			. '<p><button type="submit" class="button" form="' . esc_attr( $this->formId( 'claim-archived' ) ) . '">'
			. esc_html__( 'Already paid? Check again', 'lingocanvas' ) . '</button></p>';
	}

	/**
	 * Выводит сообщение о результате последнего действия (один раз).
	 */
	public function renderNotice(): void {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		$type = in_array( $notice['type'] ?? '', array( 'success', 'error', 'info', 'warning' ), true ) ? (string) $notice['type'] : 'info';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( (string) ( $notice['message'] ?? '' ) )
		);
	}

	/**
	 * Текст о передаче данных: показывается до любого действия.
	 */
	private function disclosureHtml(): string {
		$terms   = '<a href="' . esc_url( self::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'lingocanvas' ) . '</a>';
		$privacy = '<a href="' . esc_url( self::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'lingocanvas' ) . '</a>';

		return '<p class="description">' . sprintf(
			/* translators: 1: link "Terms", 2: link "Privacy Policy". */
			esc_html__( 'The texts you translate, the source and target language, the site name and an optional e-mail address are sent to LingoCanvas Cloud (cloud.centerai.eu, operated by CenterAI). The translation is performed by DeepSeek, whose servers are in China. Read the %1$s and the %2$s. Manual translation and your own API key remain free and work without registration.', 'lingocanvas' ),
			$terms,
			$privacy
		) . '</p>';
	}

	/**
	 * Галочка согласия внутри главной формы (её можно снять и сохранить).
	 *
	 * @param bool $checked Согласие уже дано.
	 */
	private function consentHtml( bool $checked ): string {
		return '<p><input type="hidden" name="cloud_consent" value="0"><label><input type="checkbox" name="cloud_consent" value="1"'
			. ( $checked ? ' checked="checked"' : '' ) . '> '
			. esc_html__( 'I agree to send texts for translation to LingoCanvas Cloud', 'lingocanvas' )
			. '</label></p>';
	}

	/**
	 * Подписка: согласие (если ещё нет), почта и кнопка.
	 *
	 * @param bool $consent Согласие уже дано.
	 */
	private function signupHtml( bool $consent ): string {
		$form = $this->formId( 'signup' );
		$html = '<div class="lingocanvas-cloud-signup">';

		if ( ! $consent ) {
			$html .= '<p><label><input type="checkbox" name="cloud_consent" value="1" required="required" form="' . esc_attr( $form ) . '"> '
				. esc_html__( 'I agree to send texts for translation to LingoCanvas Cloud', 'lingocanvas' )
				. '</label></p>';
		}

		$html .= '<p><label for="lingocanvas-cloud-email">' . esc_html__( 'E-mail (optional)', 'lingocanvas' ) . '</label><br>'
			. '<input type="email" name="cloud_email" id="lingocanvas-cloud-email" class="regular-text" autocomplete="email" form="' . esc_attr( $form ) . '"></p>'
			. '<p class="description">' . esc_html__( 'You will be redirected to Stripe to pay. After the payment, come back to this page: the site connects automatically.', 'lingocanvas' ) . '</p>'
			. '<p><button type="submit" class="button button-primary" form="' . esc_attr( $form ) . '">'
			. esc_html__( 'Subscribe — Starter, 9 € / month (100,000 characters)', 'lingocanvas' )
			. '</button></p></div>';

		return $html;
	}

	/**
	 * Ожидание оплаты.
	 */
	private function pendingHtml(): string {
		return '<div class="notice notice-info inline lingocanvas-cloud-pending"><p><span class="spinner is-active"></span> '
			. esc_html__( 'Waiting for payment confirmation…', 'lingocanvas' )
			. ' <span class="lingocanvas-cloud-poll-msg" role="status" aria-live="polite"></span></p></div>'
			. '<p><button type="submit" class="button button-primary" form="' . esc_attr( $this->formId( 'claim' ) ) . '">'
			. esc_html__( 'Check payment', 'lingocanvas' ) . '</button> '
			. '<button type="submit" class="button" form="' . esc_attr( $this->formId( 'cancel' ) ) . '" data-lingocanvas-confirm="'
			. esc_attr__( 'If you have already paid, do not cancel — the confirmation can take a minute.', 'lingocanvas' ) . '">'
			. esc_html__( 'Cancel', 'lingocanvas' ) . '</button></p>';
	}

	/**
	 * Подключённый сайт: панель состояния и кнопки.
	 */
	private function connectedHtml(): string {
		$byConstant = $this->providers->isOverriddenByConstant( ProviderFactory::CONST_CLOUD_TOKEN );

		$field = static fn( string $name, string $label ): string => sprintf(
			'<li><strong>%1$s</strong> <span data-lingocanvas-field="%2$s"></span></li>',
			esc_html( $label ),
			esc_attr( $name )
		);

		$html = '<p class="description">' . esc_html(
			$byConstant
				? __( 'This site is connected to LingoCanvas Cloud. The site token is set in wp-config.php.', 'lingocanvas' )
				: __( 'This site is connected to LingoCanvas Cloud.', 'lingocanvas' )
		) . '</p>';

		$html .= '<div class="lingocanvas-cloud-status" data-lingocanvas-cloud-status="1">'
			. '<p class="lingocanvas-cloud-status-msg" role="status" aria-live="polite">'
			. ( $this->settings->cloudConsentAt() > 0
				? esc_html__( 'Loading status…', 'lingocanvas' )
				: esc_html__( 'Status is not loaded: you have not agreed to send texts to LingoCanvas Cloud. Tick the agreement above and save the settings.', 'lingocanvas' ) )
			. '</p>'
			. '<ul class="lingocanvas-cloud-fields" hidden="hidden">'
			. $field( 'plan', _x( 'Plan:', 'cloud status', 'lingocanvas' ) )
			. $field( 'subscription', _x( 'Subscription:', 'cloud status', 'lingocanvas' ) )
			. $field( 'renewal', _x( 'Billing period:', 'cloud status', 'lingocanvas' ) )
			. $field( 'monthly_left', _x( 'Characters left this month:', 'cloud status', 'lingocanvas' ) )
			. $field( 'packs_left', _x( 'Extra pack characters:', 'cloud status', 'lingocanvas' ) )
			. $field( 'sites', _x( 'Sites:', 'cloud status', 'lingocanvas' ) )
			. '</ul>'
			. '<p class="description lingocanvas-cloud-packs-note" data-lingocanvas-field="packs_note" hidden="hidden"></p>'
			. '</div>';

		$html .= '<p class="lingocanvas-cloud-buttons">'
			. '<button type="button" class="button" data-lingocanvas-cloud-refresh="1">' . esc_html__( 'Refresh', 'lingocanvas' ) . '</button> '
			. '<button type="submit" class="button" data-lingocanvas-cloud-pack="1" form="' . esc_attr( $this->formId( 'checkout-pack' ) ) . '">'
			. esc_html__( 'Buy 250,000 characters — 7 €', 'lingocanvas' ) . '</button> '
			. '<button type="submit" class="button button-primary" hidden="hidden" data-lingocanvas-cloud-resubscribe="1" form="' . esc_attr( $this->formId( 'checkout-starter' ) ) . '">'
			. esc_html__( 'Subscribe again', 'lingocanvas' ) . '</button> '
			. '<button type="submit" class="button" form="' . esc_attr( $this->formId( 'portal' ) ) . '">'
			. esc_html__( 'Manage subscription', 'lingocanvas' ) . '</button>'
			. '</p>'
			. '<p class="description lingocanvas-cloud-pack-note" data-lingocanvas-cloud-pack-note="1" hidden="hidden"></p>';

		if ( $byConstant ) {
			$html .= '<p class="description">' . esc_html__( 'To disconnect this site, remove LINGOCANVAS_CLOUD_TOKEN from wp-config.php.', 'lingocanvas' ) . '</p>';

			return $html;
		}

		return $html . '<p><button type="submit" class="button button-link-delete" form="' . esc_attr( $this->formId( 'disconnect' ) ) . '" data-lingocanvas-confirm="'
			. esc_attr__( 'Disconnect this site from LingoCanvas Cloud? Your subscription will NOT be canceled.', 'lingocanvas' ) . '">'
			. esc_html__( 'Disconnect', 'lingocanvas' ) . '</button></p>'
			. '<p class="description">' . esc_html__( 'Disconnecting only removes the connection from this site: your subscription is NOT canceled and keeps being billed. To cancel it, use "Manage subscription". Translations already saved stay in place, and AI translation switches back to your own API key.', 'lingocanvas' ) . '</p>';
	}

	/**
	 * Пустая форма действия: кнопки подключаются к ней атрибутом `form`.
	 *
	 * @param string $action  Действие admin-post.
	 * @param string $suffix  Окончание id формы.
	 * @param string $product Товар (для оплаты) или пустая строка.
	 */
	private function formHtml( string $action, string $suffix, string $product = '' ): string {
		return '<form id="' . esc_attr( $this->formId( $suffix ) ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">'
			. ( '' !== $product ? '<input type="hidden" name="product" value="' . esc_attr( $product ) . '">' : '' )
			. '</form>';
	}

	/**
	 * Идентификатор формы действия.
	 *
	 * @param string $suffix Окончание.
	 */
	private function formId( string $suffix ): string {
		return 'lingocanvas-cloud-form-' . $suffix;
	}

	/**
	 * Есть ли токен сайта (константа или настройки). Сам токен не читается наружу.
	 */
	private function hasToken(): bool {
		return '' !== $this->providers->cloudToken();
	}

	// --- Обработчики admin-post --------------------------------------------------

	/**
	 * Подписка: уходит в Stripe.
	 */
	public function handleSignup(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_SIGNUP );

		$email = isset( $_POST['cloud_email'] ) && is_scalar( $_POST['cloud_email'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST['cloud_email'] ) ) )
			: '';

		$consentValue = isset( $_POST['cloud_consent'] ) && is_scalar( $_POST['cloud_consent'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cloud_consent'] ) )
			: '';

		$this->finish( $this->actions->signup( $email, '' !== $consentValue && '0' !== $consentValue ) );
	}

	/**
	 * Кнопка «Проверить оплату».
	 */
	public function handleClaim(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_CLAIM );

		$this->backWithClaimResult( $this->actions->claim() );
	}

	/**
	 * Кнопка «Уже оплатили? Проверить ещё раз» у отменённой заявки.
	 */
	public function handleClaimArchived(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_CLAIM_OLD );

		$this->backWithClaimResult( $this->actions->claimArchived() );
	}

	/**
	 * Кнопка «Отмена» у ожидающей оплаты: сначала одна проверка оплаты, затем заявка уходит в архив.
	 */
	public function handleCancel(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_CANCEL );

		$result = $this->actions->cancelClaim();

		$this->back( 'connected' === $result['status'] ? 'success' : 'info', $result['message'] );
	}

	/**
	 * Возвращает на страницу настроек с итогом проверки оплаты.
	 *
	 * @param array{status: string, message: string} $result Результат CloudActions::claim().
	 */
	private function backWithClaimResult( array $result ): void {
		$types = array(
			'connected'  => 'success',
			'pending'    => 'info',
			'no_consent' => 'error',
			'error'      => 'error',
		);

		$this->back( $types[ $result['status'] ] ?? 'error', $result['message'] );
	}

	/**
	 * Покупка пакета символов или повторная подписка.
	 */
	public function handleCheckout(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_CHECKOUT );

		$product = isset( $_POST['product'] ) && is_scalar( $_POST['product'] )
			? sanitize_key( wp_unslash( (string) $_POST['product'] ) )
			: '';

		$this->finish( $this->actions->checkout( $product ) );
	}

	/**
	 * Управление подпиской на портале Stripe.
	 */
	public function handlePortal(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_PORTAL );

		$this->finish( $this->actions->portal() );
	}

	/**
	 * Отключение сайта от Cloud.
	 */
	public function handleDisconnect(): void {
		$this->denyUnlessAdmin();
		check_admin_referer( self::ACTION_DISCONNECT );

		$result = $this->actions->disconnect();

		$this->back( $result['ok'] ? 'success' : 'error', $result['message'] );
	}

	/**
	 * Останавливает запрос, если у пользователя нет права менять настройки.
	 */
	private function denyUnlessAdmin(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'lingocanvas' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Завершает действие: уходит на Stripe или возвращается с сообщением об ошибке.
	 *
	 * @param array{ok: bool, url: string, message: string} $result Результат действия.
	 */
	private function finish( array $result ): void {
		if ( ! $result['ok'] ) {
			$this->back( 'error', $result['message'] );

			return;
		}

		try {
			$url = CloudClient::stripeUrl( $result['url'] );
		} catch ( CloudException $e ) {
			$this->back( 'error', $e->userMessage() );

			return;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// Узел Stripe разрешается только для этого одного редиректа.
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) use ( $host ) {
				$hosts   = is_array( $hosts ) ? $hosts : array();
				$hosts[] = $host;

				return $hosts;
			}
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Возвращает на страницу настроек с сообщением.
	 *
	 * @param string $type    success, error, info или warning.
	 * @param string $message Текст для владельца сайта (никогда не сырой ответ сервера).
	 */
	private function back( string $type, string $message ): void {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			2 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( array( 'page' => SettingsPage::MENU_SLUG ), admin_url( 'admin.php' ) ) . '#' . self::ANCHOR );
		exit;
	}
}
