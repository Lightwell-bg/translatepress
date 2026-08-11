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
	 *
	 * Не меньше — крупный чанк (BatchChunker::DEFAULT_MAX_CHARS — 6000
	 * символов на входе, перевод сопоставимого объёма на выходе) под
	 * нагрузкой на стороне OpenAI иногда не укладывается в 30 секунд даже
	 * без единой ошибки: WordPress в этом случае отдаёт `cURL error 28`
	 * («0 bytes received») — то же самое сообщение, что и при настоящем
	 * обрыве связи, отличить одно от другого по тексту ошибки нельзя.
	 */
	private const TIMEOUT_SECONDS = 45;

	/**
	 * Сколько раз повторить запрос при сетевой ошибке или 5xx.
	 */
	private const MAX_ATTEMPTS = 2;

	/**
	 * Пауза перед повтором, секунд.
	 *
	 * Не в момент неудачи — до следующей попытки: мгновенный повтор той же
	 * ошибки почти всегда даёт тот же результат, а секунда-другая пауза
	 * достаточна, чтобы пережить короткий сетевой сбой, не удлиняя
	 * ожидание заметно для админа.
	 */
	private const RETRY_DELAY_SECONDS = 2;

	/**
	 * Причина последней неудачи. Без ключа и прочих секретов — этот текст
	 * уходит прямо в ответ REST и виден в админке (ТЗ 13: ключ туда не попадает).
	 */
	private ?string $lastError = null;

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
	 * Причина, по которой последний вызов `translateBatch()` не вернул перевод.
	 *
	 * Не входит в `ProviderInterface`: это диагностика конкретно для OpenAI,
	 * а не часть контракта, обязательного для любого провайдера.
	 */
	public function lastError(): ?string {
		return $this->lastError;
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
		$this->lastError = null;

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
			// $this->lastError уже заполнен внутри send().
			return array();
		}

		$result = OpenAiRequestBuilder::parseResponse( $body, array_keys( $items ) );

		if ( array() === $result ) {
			// HTTP 200, но модель не вернула то, что было запрошено: либо
			// ответила текстом вместо JSON, либо не тем набором ключей.
			$this->lastError = 'модель ответила, но её ответ не удалось разобрать';
			$this->log( $this->lastError . ': ' . substr( $body, 0, 500 ) );
		}

		return $result;
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

		/*
		 * До MAX_ATTEMPTS попыток по TIMEOUT_SECONDS каждая плюс паузы между
		 * ними в сумме способны превысить обычный max_execution_time
		 * хостинга (нередко 30 секунд по умолчанию на дешёвом тарифе) — тогда
		 * PHP оборвал бы запрос сам, отдав вместо ясного сообщения об ошибке
		 * голый HTTP 500. Лимит увеличивается с запасом только на время этого
		 * вызова, не глобально; на хостингах, где set_time_limit() отключён
		 * (safe-режимы, некоторые дешёвые тарифы), функции нет в
		 * function_exists() — вызов просто пропускается, риска фатальной
		 * ошибки это не добавляет.
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( ( self::TIMEOUT_SECONDS + self::RETRY_DELAY_SECONDS ) * self::MAX_ATTEMPTS + 10 );
		}

		$attempt = 0;

		do {
			++$attempt;

			if ( $attempt > 1 ) {
				sleep( self::RETRY_DELAY_SECONDS );
			}

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
				$this->lastError = 'сетевая ошибка: ' . $response->get_error_message();
				$this->log( $this->lastError );

				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				return (string) wp_remote_retrieve_body( $response );
			}

			$message = OpenAiRequestBuilder::errorMessage( (string) wp_remote_retrieve_body( $response ) );

			$this->lastError = sprintf( 'HTTP %d%s', $code, null !== $message ? ': ' . $message : '' );
			$this->log( $this->lastError );

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
