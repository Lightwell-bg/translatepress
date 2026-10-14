<?php
/**
 * REST: ручное включение записей на дополнительных языках.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMlp\Settings\Settings;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;

/**
 * Два маршрута для экрана управления видимостью записей:
 *
 * - `POST /wp-json/mlp/v1/posts/visibility` — тело `{ids: [int…], locale, visible: bool}`.
 *   Включает или выключает записи на языке. Право: `edit_post` на КАЖДУЮ
 *   запись из списка; если не хватает хотя бы на одну, отклоняется весь
 *   запрос целиком (ничего не применяется частично). Не более 200 записей.
 * - `POST /wp-json/mlp/v1/posts/visibility/enable-translated` — тело
 *   `{locale}`. Включает все записи, переведённые полностью. Право:
 *   `manage_options`.
 *
 * Подлинность запроса (nonce `wp_rest`, заголовок `X-WP-Nonce`) проверяет
 * само ядро WordPress для cookie-аутентификации. Язык принимается только из
 * настроенных дополнительных языков; всё остальное — 400.
 */
final class PostVisibilityController implements Hookable {

	public const NAMESPACE  = 'mlp/v1';
	public const CAPABILITY = 'manage_options';

	/**
	 * Максимум записей в одном запросе.
	 */
	public const MAX_IDS = 200;

	/**
	 * @param Settings               $settings   Список языков.
	 * @param PostLanguageVisibility $visibility Хранилище флагов.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly PostLanguageVisibility $visibility
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
			'/posts/visibility',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'setVisibility' ),
				'permission_callback' => array( $this, 'canEditPosts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/visibility/enable-translated',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enableTranslated' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
	}

	/**
	 * Право менять настройки плагина (массовое включение).
	 */
	public function canManage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Право управлять плагином (`manage_options`) И править КАЖДУЮ запись запроса.
	 *
	 * Если список записей некорректен, право не решает ничего: достаточно
	 * общего права `edit_posts`, а подробную причину отказа (400) даст сам
	 * обработчик. Так посторонний не узнает по ответу, какие id существуют.
	 *
	 * @param WP_REST_Request $request Запрос.
	 */
	public function canEditPosts( WP_REST_Request $request ): bool {
		// Как везде в плагине: и право управлять плагином, и право править запись.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		$ids = self::parseIds( $request->get_param( 'ids' ) );

		if ( null === $ids ) {
			return current_user_can( 'edit_posts' );
		}

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Включает или выключает записи на языке.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function setVisibility( WP_REST_Request $request ) {
		$ids = self::parseIds( $request->get_param( 'ids' ) );

		if ( null === $ids ) {
			return new WP_Error(
				'mlp_invalid_ids',
				/* translators: %d: maximum number of posts per request */
				sprintf( /* translators: %d: maximum number of ids */ __( 'Pass an ids list: from 1 to %d positive integers.', 'wp-mlp' ), self::MAX_IDS ),
				array( 'status' => 400 )
			);
		}

		$locale = $this->secondaryLocale( $request->get_param( 'locale' ) );

		if ( null === $locale ) {
			return $this->unknownLocale();
		}

		$visible = self::parseBool( $request->get_param( 'visible' ) );

		if ( null === $visible ) {
			return new WP_Error( 'mlp_invalid_visible', __( 'The visible parameter must be true or false.', 'wp-mlp' ), array( 'status' => 400 ) );
		}

		foreach ( $ids as $id ) {
			if ( ! $this->visibility->isEligiblePost( $id ) ) {
				return new WP_Error(
					'mlp_invalid_post',
					/* translators: %d: post ID */
					sprintf( /* translators: %d: post ID */ __( 'Post %d was not found or its type cannot be enabled for a language.', 'wp-mlp' ), $id ),
					array( 'status' => 400 )
				);
			}
		}

		$changed = $this->visibility->setMany( $ids, $locale, $visible );

		$states = array();

		foreach ( $this->visibility->states( $ids, $locale ) as $id => $state ) {
			$states[] = array(
				'id'      => $id,
				'visible' => $state,
			);
		}

		return new WP_REST_Response(
			array(
				'locale'       => $locale,
				'visible'      => $visible,
				'changed'      => $changed,
				'states'       => $states,
				'visibleCount' => $this->visibility->count( $locale ),
			)
		);
	}

	/**
	 * Включает все записи, переведённые на язык полностью.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enableTranslated( WP_REST_Request $request ) {
		$locale = $this->secondaryLocale( $request->get_param( 'locale' ) );

		if ( null === $locale ) {
			return $this->unknownLocale();
		}

		$enabled = $this->visibility->enableFullyTranslated( $locale );

		return new WP_REST_Response(
			array(
				'locale'       => $locale,
				'enabled'      => $enabled,
				'visibleCount' => $this->visibility->count( $locale ),
			)
		);
	}

	/**
	 * Разбирает список записей. Чистая функция.
	 *
	 * Строго: массив из 1–200 целых положительных чисел (или их строковой
	 * записи из цифр). Дробные, булевы, пустые строки и вложенные значения —
	 * отказ. Повторы схлопываются.
	 *
	 * @param mixed $raw Значение параметра.
	 * @return list<int>|null null — список некорректен.
	 */
	public static function parseIds( $raw ): ?array {
		if ( ! is_array( $raw ) || array() === $raw || count( $raw ) > self::MAX_IDS ) {
			return null;
		}

		$ids = array();

		foreach ( $raw as $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/', $value ) ) {
				$value = (int) $value;
			}

			if ( ! is_int( $value ) || $value <= 0 ) {
				return null;
			}

			$ids[ $value ] = $value;
		}

		return array_values( $ids );
	}

	/**
	 * Разбирает булево значение. Чистая функция.
	 *
	 * @param mixed $raw Значение параметра.
	 * @return bool|null null — не булево.
	 */
	public static function parseBool( $raw ): ?bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}

		if ( 1 === $raw || '1' === $raw || 'true' === $raw ) {
			return true;
		}

		if ( 0 === $raw || '0' === $raw || 'false' === $raw ) {
			return false;
		}

		return null;
	}

	/**
	 * Код настроенного дополнительного языка или null.
	 *
	 * @param mixed $raw Значение параметра.
	 */
	private function secondaryLocale( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$locale = Locale::normalize( $raw );

		return isset( $this->settings->secondary()[ $locale ] ) ? $locale : null;
	}

	/**
	 * Ошибка «язык не настроен».
	 */
	private function unknownLocale(): WP_Error {
		return new WP_Error( 'mlp_unknown_locale', __( 'This secondary language is not in the settings.', 'wp-mlp' ), array( 'status' => 400 ) );
	}
}
