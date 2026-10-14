<?php
/**
 * REST-список моделей OpenAI для выпадающего списка на странице настроек.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMlp\Support\Hookable;
use WpMlp\Translation\OpenAiProvider;
use WpMlp\Translation\ProviderFactory;

/**
 * `GET /wp-json/mlp/v1/openai/models[?refresh=1]`.
 *
 * Сервер сам идёт в `GET {адрес API}/models` с сохранённым ключом: ключ не
 * покидает сервер и не попадает ни в ответ, ни в лог. Удачный список кешируется
 * в transient на 12 часов; `refresh=1` пропускает кеш. Идентификаторы моделей
 * уходят простым текстом, интерфейс обязан выводить их через `textContent`.
 */
final class OpenAiModelsController implements Hookable {

	public const NAMESPACE  = 'mlp/v1';
	public const CAPABILITY = 'manage_options';
	public const CACHE_TTL  = 43200;
	public const TIMEOUT    = 10;

	/**
	 * Подстроки идентификаторов, по которым модель не годится для перевода текста.
	 *
	 * @var list<string>
	 */
	public const EXCLUDED_FRAGMENTS = array(
		'embedding',
		'tts',
		'whisper',
		'transcribe',
		'dall-e',
		'image',
		'audio',
		'realtime',
		'moderation',
		'search',
		'computer-use',
		'babbage',
		'davinci',
	);

	/**
	 * @param ProviderFactory $providers Откуда берутся ключ и адрес API.
	 */
	public function __construct( private readonly ProviderFactory $providers ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/openai/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'models' ),
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
	}

	/**
	 * Право менять настройки плагина.
	 */
	public function canManage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Отдаёт список моделей.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function models( WP_REST_Request $request ) {
		$key = trim( $this->providers->apiKey() );

		if ( '' === $key ) {
			return new WP_Error(
				'mlp_openai_no_key',
				__( 'Save the OpenAI key first; the list of models will then appear here.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$base      = rtrim( trim( $this->providers->baseUrl() ), '/' );
		$cacheKey  = 'mlp_openai_models_' . md5( $base . substr( $key, -8 ) );
		$refresh   = '' !== (string) $request->get_param( 'refresh' ) && '0' !== (string) $request->get_param( 'refresh' );
		$cached    = $refresh ? false : get_transient( $cacheKey );

		if ( is_array( $cached ) ) {
			return new WP_REST_Response( $cached );
		}

		$response = wp_remote_get(
			$base . '/models',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( __( 'Network error: ', 'wp-mlp' ) . $response->get_error_message(), $key );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$envelope = json_decode( $body, true );
			$message  = is_array( $envelope ) && is_string( $envelope['error']['message'] ?? null ) ? $envelope['error']['message'] : '';

			return $this->failure( sprintf( 'HTTP %d%s', $code, '' !== $message ? ': ' . $message : '' ), $key );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! is_array( $decoded['data'] ?? null ) ) {
			return $this->failure( __( 'The API address did not return the list of models in the expected format.', 'wp-mlp' ), $key );
		}

		$list = self::filterAndSort( $decoded['data'] );

		set_transient( $cacheKey, $list, self::CACHE_TTL );

		return new WP_REST_Response( $list );
	}

	/**
	 * Оставляет модели, пригодные для перевода текста, новые — первыми.
	 *
	 * @param array<mixed> $data Элемент `data` из ответа API.
	 * @return list<array{id: string, created: int}>
	 */
	public static function filterAndSort( array $data ): array {
		$list = array();

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || '' === trim( $item['id'] ) ) {
				continue;
			}

			$id    = trim( $item['id'] );
			$lower = strtolower( $id );
			$skip  = false;

			foreach ( self::EXCLUDED_FRAGMENTS as $fragment ) {
				if ( str_contains( $lower, $fragment ) ) {
					$skip = true;
					break;
				}
			}

			if ( $skip ) {
				continue;
			}

			$list[ $id ] = array(
				'id'      => $id,
				'created' => (int) ( $item['created'] ?? 0 ),
			);
		}

		$list = array_values( $list );

		usort(
			$list,
			static fn( array $a, array $b ): int => array( $b['created'], $a['id'] ) <=> array( $a['created'], $b['id'] )
		);

		return $list;
	}

	/**
	 * Ошибка 400 с коротким сообщением; ключ из текста вырезается на всякий случай.
	 *
	 * @param string $message Причина.
	 * @param string $key     Ключ, который не должен попасть в ответ.
	 */
	private function failure( string $message, string $key ): WP_Error {
		$message = (string) OpenAiProvider::maskKey( $message, $key );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 300 ) : substr( $message, 0, 300 );

		return new WP_Error( 'mlp_openai_models', $message, array( 'status' => 400 ) );
	}
}
