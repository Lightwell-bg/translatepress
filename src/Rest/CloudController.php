<?php
/**
 * REST-маршруты LingoCanvas Cloud для страницы настроек.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use LingoCanvas\Admin\CloudActions;
use LingoCanvas\Admin\CloudStatus;
use LingoCanvas\Support\Hookable;
use LingoCanvas\Translation\CloudException;

defined( 'ABSPATH' ) || exit;

/**
 * `GET lingocanvas/v1/cloud/status[?refresh=1]` и `POST lingocanvas/v1/cloud/claim`.
 *
 * Оба маршрута требуют `manage_options`; nonce `wp_rest` проверяет ядро WordPress
 * (заголовок X-WP-Nonce) для запросов с cookie. Токен сайта в ответы не попадает:
 * статус отдаёт только подписи для показа, claim — только `status` и `message`.
 */
final class CloudController implements Hookable {

	public const NAMESPACE  = 'lingocanvas/v1';
	public const CAPABILITY = 'manage_options';

	/**
	 * @param CloudStatus  $status  Состояние аккаунта.
	 * @param CloudActions $actions Действия с Cloud.
	 */
	public function __construct(
		private readonly CloudStatus $status,
		private readonly CloudActions $actions
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Регистрирует маршруты.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cloud/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array(
					'refresh' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/cloud/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'claim' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
	}

	/**
	 * Право менять настройки плагина.
	 */
	public function canManage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Состояние аккаунта для показа.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ) {
		// Без согласия владельца обращений к Cloud нет совсем: отвечаем сразу, без HTTP.
		if ( ! $this->actions->hasConsent() ) {
			return new WP_REST_Response(
				array(
					'status'  => 'no_consent',
					'message' => __( 'Status is not loaded: you have not agreed to send texts to LingoCanvas Cloud. Tick the agreement above and save the settings.', 'lingocanvas' ),
				)
			);
		}

		$refresh = '' !== (string) $request->get_param( 'refresh' ) && '0' !== (string) $request->get_param( 'refresh' );

		try {
			return new WP_REST_Response( $this->status->get( $refresh ) );
		} catch ( CloudException $e ) {
			return new WP_Error(
				'lingocanvas_cloud_status',
				$e->userMessage(),
				array( 'status' => 'invalid_token' === $e->errorCode() ? 400 : 502 )
			);
		}
	}

	/**
	 * Один опрос «оплачено ли». Токен остаётся на сервере.
	 *
	 * @return WP_REST_Response
	 */
	public function claim() {
		$result = $this->actions->claim();

		return new WP_REST_Response(
			array(
				'status'  => $result['status'],
				'message' => $result['message'],
			)
		);
	}
}
