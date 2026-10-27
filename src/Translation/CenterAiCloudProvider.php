<?php
/**
 * Провайдер перевода через LingoCanvas Cloud (CenterAI Cloud).
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Отправляет строки в `POST /v1/translate` (cloud/docs/API.md).
 *
 * Пачка режется на запросы в рамках лимитов API; каждый запрос получает свой
 * `request_id`, который генерируется один раз и повторяется при ретраях (таймаут
 * на нашей стороне означает «возможно, выполнено», новый id мог бы привести к
 * двойной оплате). При окончательной ошибке дальнейшие запросы не отправляются,
 * а уже полученные переводы возвращаются (частичный результат).
 */
final class CenterAiCloudProvider implements ProviderInterface, ProviderErrorInterface {

	/** Имя провайдера в колонке `provider` таблицы переводов. */
	public const PROVIDER_NAME = 'lingocanvas_cloud';

	/** Имя модели в колонке `model` таблицы переводов. */
	public const MODEL_NAME = 'cloud';

	/** Максимум строк в одном запросе. */
	public const MAX_ITEMS = 300;

	/** Максимум символов во всех строках одного запроса. */
	public const MAX_CHARS_PER_REQUEST = 20000;

	/** Максимум символов в одной строке. */
	public const MAX_CHARS_PER_ITEM = 10000;

	/** Максимум терминов глоссария. */
	public const MAX_GLOSSARY_ENTRIES = 50;

	/** Максимум символов одной стороны термина. */
	public const MAX_GLOSSARY_SIDE = 200;

	/** Максимум символов глоссария целиком. */
	public const MAX_GLOSSARY_CHARS = 2000;

	/** Попыток на один запрос (первая + 2 повтора). */
	private const MAX_ATTEMPTS = 3;

	/** Паузы перед повторами, секунд. */
	private const BACKOFF = array( 2, 5 );

	/** Потолок паузы перед повтором, секунд: дольше ждать внутри запроса не будем. */
	private const MAX_WAIT = 10;

	/** Опция с отправленными запросами: отпечаток тела => id и время (не автозагружаемая). */
	public const OPS_OPTION = 'lingocanvas_cloud_ops';

	/** Короткая блокировка записи в OPS_OPTION. */
	public const OPS_LOCK_OPTION = 'lingocanvas_cloud_ops_lock';

	/** Сколько записей хранится максимум. */
	private const OPS_MAX = 200;

	/** Срок жизни записи, секунд (24 часа). */
	private const OPS_TTL = 86400;

	/** Причина последней неудачи для человека (без секретов). */
	private ?string $lastError = null;

	/** Код ошибки API последней неудачи. */
	private ?string $lastErrorCode = null;

	/**
	 * Пауза между попытками.
	 *
	 * @var callable(int): void
	 */
	private $sleeper;

	/**
	 * @param CloudClient              $client  Клиент API.
	 * @param (callable(int): void)|null $sleeper Подмена паузы (для тестов).
	 */
	public function __construct( private readonly CloudClient $client, ?callable $sleeper = null ) {
		$this->sleeper = $sleeper ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function lastError(): ?string {
		return $this->lastError;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Тексты ошибок фиксированные и секретов не содержат.
	 */
	public function maskedLastError(): ?string {
		return $this->lastError;
	}

	/**
	 * {@inheritDoc}
	 */
	public function lastErrorCode(): ?string {
		return $this->lastErrorCode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $sourceLocale, string $targetLocale ): bool {
		unset( $sourceLocale, $targetLocale );

		return true;
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
		$this->lastError     = null;
		$this->lastErrorCode = null;

		$batches = self::splitIntoBatches( $items );

		if ( array() === $batches ) {
			if ( array() !== $items ) {
				// Все строки слишком длинные или пустые — отправлять нечего.
				$this->lastErrorCode = 'payload_too_large';
				$this->lastError     = CloudException::messageFor( 'payload_too_large' );
			}

			return array();
		}

		$result = array();

		foreach ( $batches as $batch ) {
			try {
				$translations = $this->sendWithRetries( $batch, $sourceLocale, $targetLocale, $context );
			} catch ( CloudException $e ) {
				$this->lastErrorCode = $e->errorCode();
				$this->lastError     = $e->userMessage();

				// Окончательная ошибка: дальше не идём, полученное сохраняем.
				break;
			}

			$result += $translations;
		}

		return $result;
	}

	/**
	 * Режет пачку на запросы в рамках лимитов API.
	 *
	 * Пустые строки и строки длиннее лимита в запросы не попадают: API отвечает на
	 * них 422/413 для всего запроса, а вызывающий код и так считает недостающие
	 * строки непереведёнными.
	 *
	 * @param array<string, string> $items Хеш => текст.
	 * @return list<array<string, string>>
	 */
	public static function splitIntoBatches( array $items ): array {
		$batches = array();
		$current = array();
		$chars   = 0;

		foreach ( $items as $hash => $text ) {
			$hash   = (string) $hash;
			$text   = (string) $text;
			$length = mb_strlen( $text );

			if ( '' === trim( $text ) || $length > self::MAX_CHARS_PER_ITEM || '' === $hash || strlen( $hash ) > 128 ) {
				continue;
			}

			if ( array() !== $current && ( count( $current ) >= self::MAX_ITEMS || $chars + $length > self::MAX_CHARS_PER_REQUEST ) ) {
				$batches[] = $current;
				$current   = array();
				$chars     = 0;
			}

			$current[ $hash ] = $text;
			$chars           += $length;
		}

		if ( array() !== $current ) {
			$batches[] = $current;
		}

		return $batches;
	}

	/**
	 * Выбирает из глоссария термины, встречающиеся в тексте запроса, в рамках лимитов API.
	 *
	 * @param array<string, string> $glossary Термин => перевод.
	 * @param array<string, string> $batch    Строки запроса.
	 * @return array<string, string>
	 */
	public static function filterGlossary( array $glossary, array $batch ): array {
		if ( array() === $glossary ) {
			return array();
		}

		$haystack = implode( "\n", $batch );
		$budget   = min( self::MAX_GLOSSARY_CHARS, 2 * mb_strlen( $haystack ) + 200 );
		$used     = 0;
		$picked   = array();

		foreach ( $glossary as $term => $translation ) {
			$term        = trim( (string) $term );
			$translation = trim( (string) $translation );

			if ( '' === $term || '' === $translation ) {
				continue;
			}

			$size = mb_strlen( $term );
			$back = mb_strlen( $translation );

			if ( $size > self::MAX_GLOSSARY_SIDE || $back > self::MAX_GLOSSARY_SIDE || false === mb_stripos( $haystack, $term ) ) {
				continue;
			}

			if ( $used + $size + $back > $budget ) {
				continue;
			}

			$picked[ $term ] = $translation;
			$used           += $size + $back;

			if ( count( $picked ) >= self::MAX_GLOSSARY_ENTRIES ) {
				break;
			}
		}

		return $picked;
	}

	/**
	 * Отправляет один запрос с повторами под тем же `request_id`.
	 *
	 * @param array<string, string> $batch  Строки запроса.
	 * @return array<string, string> Хеш => перевод (только полученные).
	 * @throws CloudException Если все попытки исчерпаны или ошибка окончательная.
	 */
	private function sendWithRetries( array $batch, string $source, string $target, TranslationContext $context ): array {
		$glossary    = self::filterGlossary( $context->glossary, $batch );
		$fingerprint = self::fingerprint( $source, $target, $context->targetLanguageLabel, $batch, $glossary );

		// Идентификатор записывается в базу ДО обращения к сети (см. requestIdFor()).
		$requestId   = $this->requestIdFor( $fingerprint );
		$regenerated = false;

		/*
		 * До MAX_ATTEMPTS попыток по таймауту перевода плюс паузы способны
		 * превысить max_execution_time хостинга; лимит поднимается только на
		 * время этого запроса (там, где set_time_limit() отключён, вызов пропускается).
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- долгий запрос к Cloud, запускается явным действием администратора.
			set_time_limit( ( CloudClient::TRANSLATE_TIMEOUT + self::MAX_WAIT ) * self::MAX_ATTEMPTS + 10 );
		}

		$attempt = 0;

		while ( true ) {
			++$attempt;

			try {
				$response = $this->client->translate( $requestId, $source, $target, $context->targetLanguageLabel, $batch, $glossary );

				return self::extractTranslations( $response, $batch );
			} catch ( CloudException $e ) {
				/*
				 * Осознанный повторный перевод тех же строк: сервер разрешает бесплатно
				 * «проиграть» ответ дважды, затем отвечает replay_limit. Забываем старый
				 * идентификатор и один раз отправляем запрос под новым (это новое списание).
				 */
				if ( ! $regenerated && 409 === $e->httpStatus() && 'replay_limit' === $e->errorCode() ) {
					$regenerated = true;
					$requestId   = $this->renewRequestId( $fingerprint );
					--$attempt;

					continue;
				}

				if ( $attempt >= self::MAX_ATTEMPTS || ! self::isRetryable( $e ) ) {
					throw $e;
				}

				// Просьбу сервера подождать никогда не сокращаем.
				$wait = $e->retryAfter() ?? self::BACKOFF[ $attempt - 1 ];

				if ( $wait > self::MAX_WAIT ) {
					/*
					 * Долго ждать внутри запроса администратора нельзя. Останавливаемся, а
					 * идентификатор остаётся в базе: следующий клик отправит тот же запрос, и
					 * сервер ответит бесплатным повтором или «ещё выполняется».
					 */
					$busy = CloudException::make( $e->errorCode(), $e->httpStatus(), $e->retryAfter(), $e->extra() + array( 'busy_seconds' => $wait ) );

					throw $busy;
				}

				( $this->sleeper )( max( 0, $wait ) );
			}
		}
	}

	/**
	 * Отпечаток тела запроса: SHA-256 от канонического JSON (строки и глоссарий отсортированы по ключу).
	 *
	 * @param string               $source   Исходный язык.
	 * @param string               $target   Целевой язык.
	 * @param string|null          $label    Название целевого языка.
	 * @param array<string,string> $items    Хеш => текст.
	 * @param array<string,string> $glossary Термин => перевод.
	 */
	public static function fingerprint( string $source, string $target, ?string $label, array $items, array $glossary ): string {
		ksort( $items, SORT_STRING );
		ksort( $glossary, SORT_STRING );

		$json = wp_json_encode(
			array(
				'source'   => $source,
				'target'   => $target,
				'label'    => $label,
				'items'    => $items,
				'glossary' => $glossary,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return hash( 'sha256', (string) $json );
	}

	/**
	 * Идентификатор запроса для этого тела: сохранённый, если такой запрос уже уходил, иначе новый.
	 *
	 * Сохранение переживает отдельные PHP-запросы: таймаут на нашей стороне означает «возможно,
	 * выполнено», и повторный клик владельца обязан отправить тот же id, а не оплатить второй раз.
	 * Запись делается до HTTP-вызова, чтобы уже отправленный id не потерялся.
	 *
	 * @param string $fingerprint Отпечаток тела.
	 */
	private function requestIdFor( string $fingerprint ): string {
		$ops = self::loadOps();

		if ( isset( $ops[ $fingerprint ] ) ) {
			return $ops[ $fingerprint ]['request_id'];
		}

		return $this->storeNewId( $fingerprint );
	}

	/**
	 * Заменяет идентификатор новым (после replay_limit).
	 *
	 * @param string $fingerprint Отпечаток тела.
	 */
	private function renewRequestId( string $fingerprint ): string {
		return $this->storeNewId( $fingerprint );
	}

	/**
	 * Генерирует id и записывает его под отпечатком.
	 *
	 * Короткая блокировка (add_option) сужает гонку двух одновременных запросов; без неё
	 * побеждает последняя запись, а после записи id перечитывается, чтобы оба запроса
	 * сошлись на одном значении.
	 *
	 * @param string $fingerprint Отпечаток тела.
	 */
	private function storeNewId( string $fingerprint ): string {
		$id     = 'lc' . bin2hex( random_bytes( 12 ) );
		$locked = self::lockOps();

		$ops                 = self::loadOps();
		$ops[ $fingerprint ] = array(
			'request_id' => $id,
			'created'    => time(),
		);

		// Не больше OPS_MAX новейших записей.
		uasort( $ops, static fn( array $a, array $b ): int => $b['created'] <=> $a['created'] );
		update_option( self::OPS_OPTION, array_slice( $ops, 0, self::OPS_MAX, true ), false );

		if ( $locked ) {
			delete_option( self::OPS_LOCK_OPTION );
		}

		$stored = self::loadOps();

		return $stored[ $fingerprint ]['request_id'] ?? $id;
	}

	/**
	 * Записи об отправленных запросах без просроченных и повреждённых.
	 *
	 * @return array<string, array{request_id: string, created: int}>
	 */
	private static function loadOps(): array {
		$stored = get_option( self::OPS_OPTION, array() );
		$result = array();

		foreach ( is_array( $stored ) ? $stored : array() as $fingerprint => $entry ) {
			if (
				is_string( $fingerprint ) && is_array( $entry )
				&& is_string( $entry['request_id'] ?? null ) && '' !== $entry['request_id']
				&& (int) ( $entry['created'] ?? 0 ) > time() - self::OPS_TTL
			) {
				$result[ $fingerprint ] = array(
					'request_id' => $entry['request_id'],
					'created'    => (int) $entry['created'],
				);
			}
		}

		return $result;
	}

	/**
	 * Пытается взять короткую блокировку записи (до 0,5 с). Протухшая блокировка считается свободной.
	 *
	 * @return bool Блокировка взята (её надо снять).
	 */
	private static function lockOps(): bool {
		for ( $try = 0; $try < 10; $try++ ) {
			if ( add_option( self::OPS_LOCK_OPTION, time() + 5, '', false ) ) {
				return true;
			}

			if ( (int) get_option( self::OPS_LOCK_OPTION, 0 ) <= time() ) {
				delete_option( self::OPS_LOCK_OPTION );

				continue;
			}

			usleep( 50000 );
		}

		// Не получилось: пишем без блокировки, побеждает последняя запись.
		return false;
	}

	/**
	 * Можно ли повторить запрос с тем же `request_id`.
	 *
	 * @param CloudException $e Ошибка.
	 */
	private static function isRetryable( CloudException $e ): bool {
		$code   = $e->errorCode();
		$status = $e->httpStatus();

		if ( CloudException::TIMEOUT === $code || CloudException::NETWORK_ERROR === $code ) {
			return true;
		}

		if ( 429 === $status || ( 409 === $status && 'request_in_progress' === $code ) ) {
			return true;
		}

		// Дневной бюджет сервиса исчерпан: Retry-After час, долбить бессмысленно.
		return $status >= 500 && 'service_budget_exhausted' !== $code;
	}

	/**
	 * Берёт из ответа только запрошенные ключи с непустым текстом (`missing` просто опускается).
	 *
	 * @param array<string, mixed>  $response Ответ API.
	 * @param array<string, string> $batch    Отправленные строки.
	 * @return array<string, string>
	 */
	private static function extractTranslations( array $response, array $batch ): array {
		$translations = is_array( $response['translations'] ?? null ) ? $response['translations'] : array();
		$result       = array();

		foreach ( $translations as $hash => $text ) {
			$hash = (string) $hash;

			if ( isset( $batch[ $hash ] ) && is_string( $text ) && '' !== trim( $text ) ) {
				$result[ $hash ] = $text;
			}
		}

		return $result;
	}
}
