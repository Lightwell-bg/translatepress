<?php
/**
 * Провайдер перевода через OpenAI-совместимый API.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

use WpMlp\Support\Hash;

/**
 * Отправляет строки в Chat Completions и возвращает переводы (ТЗ 9.1).
 *
 * Ключ и адрес приходят из `.env` через конструктор — этот класс никогда не
 * читает `Env` сам, чтобы его можно было протестировать без реального
 * окружения и чтобы ключ проходил только через одну точку входа.
 */
final class OpenAiProvider implements ProviderInterface {

	/**
	 * Таймаут одной попытки. Вызывается синхронно по клику в админке —
	 * посетитель сайта этого не ждёт, но и админа нельзя морозить надолго.
	 */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Сколько раз повторить запрос при сетевой ошибке или 5xx.
	 */
	private const MAX_ATTEMPTS = 2;

	/**
	 * @param string $apiKey  Ключ из OPENAI_API_KEY. Пустая строка — провайдер выключен.
	 * @param string $model   Идентификатор модели из OPENAI_MODEL.
	 * @param string $baseUrl Адрес API из OPENAI_BASE_URL, без хвостового слеша.
	 */
	public function __construct(
		private readonly string $apiKey,
		private readonly string $model,
		private readonly string $baseUrl
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $sourceLocale, string $targetLocale ): bool {
		unset( $sourceLocale, $targetLocale );

		return '' !== $this->apiKey && '' !== $this->model;
	}

	/**
	 * {@inheritDoc}
	 */
	public function translateBatch(
		array $items,
		string $sourceLocale,
		string $targetLocale,
		TranslationContext $context
	): array {
		if ( array() === $items || '' === $this->apiKey ) {
			return array();
		}

		$payload = OpenAiRequestBuilder::buildPayload(
			$items,
			$this->model,
			$sourceLocale,
			$targetLocale,
			$context,
			$context->targetLanguageLabel
		);

		$body = $this->send( $payload, array_keys( $items ) );

		if ( null === $body ) {
			return array();
		}

		return OpenAiRequestBuilder::parseResponse( $body, array_keys( $items ) );
	}

	/**
	 * Отправляет запрос с одним повтором при сетевой ошибке или 5xx.
	 *
	 * @param array<string, mixed> $payload  Тело запроса.
	 * @param list<string>         $itemKeys Ключи строк — только для лога ошибки.
	 */
	private function send( array $payload, array $itemKeys ): ?string {
		// Идемпотентность на уровне шлюза: повторный клик с тем же набором
		// строк не должен тарифицироваться дважды, если шлюз это поддерживает.
		$idempotencyKey = Hash::ofParts( array_merge( array( $this->model ), $itemKeys ) );

		$attempt = 0;

		do {
			++$attempt;

			$response = wp_remote_post(
				$this->baseUrl . '/chat/completions',
				array(
					'timeout' => self::TIMEOUT_SECONDS,
					'headers' => array(
						'Authorization'   => 'Bearer ' . $this->apiKey,
						'Content-Type'    => 'application/json',
						'Idempotency-Key' => $idempotencyKey,
					),
					'body'    => wp_json_encode( $payload ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log( 'network error: ' . $response->get_error_message() );

				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				return (string) wp_remote_retrieve_body( $response );
			}

			$message = OpenAiRequestBuilder::errorMessage( (string) wp_remote_retrieve_body( $response ) );
			$this->log( sprintf( 'HTTP %d%s', $code, null !== $message ? ': ' . $message : '' ) );

			// 4xx — ошибка запроса (неверная модель, лимит и т.п.), повтор не поможет.
			if ( $code < 500 ) {
				return null;
			}
		} while ( $attempt < self::MAX_ATTEMPTS );

		return null;
	}

	/**
	 * Пишет ошибку в debug.log. Ключ в сообщение никогда не попадает.
	 *
	 * @param string $message Текст ошибки.
	 */
	private function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[wp-mlp] OpenAI: ' . $message );
	}
}
