<?php
/**
 * Сборка запроса и разбор ответа OpenAI-совместимого API.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

use WpMlp\Rendering\Segment;
use WpMlp\Support\ShortcodeGuard;

/**
 * Чистая логика вокруг Chat Completions API, без единого сетевого вызова.
 *
 * Вынесена из `OpenAiProvider` намеренно: так payload и разбор ответа можно
 * проверить юнит-тестами без поднятия HTTP-мока. `response_format` намеренно
 * не запрашивается — не все совместимые шлюзы и модели его поддерживают,
 * а разбор ответа и так устойчив к «лишнему» тексту вокруг JSON.
 */
final class OpenAiRequestBuilder {

	/**
	 * Собирает тело запроса к `/chat/completions`.
	 *
	 * @param array<string, string> $items               Хеш строки => исходный текст.
	 * @param string                 $model               Идентификатор модели.
	 * @param string                 $sourceLocale        Исходный язык.
	 * @param string                 $targetLocale        Целевой язык.
	 * @param TranslationContext     $context             Контекст перевода.
	 * @param string|null            $targetLanguageLabel Название языка для человека, например «English».
	 * @return array<string, mixed>
	 */
	public static function buildPayload(
		array $items,
		string $model,
		string $sourceLocale,
		string $targetLocale,
		TranslationContext $context,
		?string $targetLanguageLabel = null
	): array {
		return array(
			'model'    => $model,
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => self::systemPrompt( $sourceLocale, $targetLocale, $targetLanguageLabel, $context, $items ),
				),
				array(
					'role'    => 'user',
					'content' => self::userPrompt( $items ),
				),
			),
		);
	}

	/**
	 * Инструкция модели.
	 *
	 * @param string              $sourceLocale        Исходный язык.
	 * @param string              $targetLocale        Целевой язык.
	 * @param string|null         $targetLanguageLabel Название языка для человека.
	 * @param TranslationContext  $context             Контекст перевода.
	 * @param array<string,string> $items              Хеш строки => исходный текст —
	 *                                                  только чтобы решить, упоминать
	 *                                                  ли инструкцию про шорткоды.
	 */
	private static function systemPrompt(
		string $sourceLocale,
		string $targetLocale,
		?string $targetLanguageLabel,
		TranslationContext $context,
		array $items = array()
	): string {
		$target = $targetLanguageLabel ?? $targetLocale;

		$lines = array(
			"You translate short website UI strings from {$sourceLocale} to {$target} ({$targetLocale}).",
			'You will receive a JSON object mapping an id to a source string.',
			'Reply with ONLY a JSON object mapping the same ids to their translation, nothing else — no markdown fences, no commentary.',
			'Keep the tone and length close to the original. Do not add quotes or trailing punctuation that was not in the source.',
		);

		if ( Segment::KIND_HTML_BLOCK === $context->kind ) {
			$lines[] = 'Some strings contain inline HTML tags (b, i, a, span, and similar). Preserve every tag and its attributes exactly, unchanged, and translate only the text between tags. Do not add, remove, or reorder tags.';
		}

		/*
		 * Инструкция добавляется, только когда в пачке реально есть шорткод —
		 * не на каждый запрос: лишняя инструкция про то, чего нет в этих
		 * строках, только отвлекает модель.
		 */
		if ( self::anyContainsShortcode( $items ) ) {
			$lines[] = 'Some strings contain WordPress shortcodes like [tag attr="value"]text[/tag] or a standalone [tag attr="value"]. Preserve every shortcode tag and its attributes exactly as written, in the same position, and translate only the human-readable text outside and between the tags. Do not translate, remove, or reorder the tags themselves.';
		}

		if ( array() !== $context->glossary ) {
			$pairs = array();

			foreach ( $context->glossary as $source => $translation ) {
				$pairs[] = "\"{$source}\" -> \"{$translation}\"";
			}

			$lines[] = 'Use these fixed translations whenever the term appears: ' . implode( ', ', $pairs ) . '.';
		}

		return implode( ' ', $lines );
	}

	/**
	 * Есть ли в пачке хоть одна строка, похожая на шорткод.
	 *
	 * @param array<string, string> $items Хеш строки => исходный текст.
	 */
	private static function anyContainsShortcode( array $items ): bool {
		foreach ( $items as $text ) {
			if ( ShortcodeGuard::containsShortcode( $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Полезная нагрузка от пользователя: сами строки в виде JSON.
	 *
	 * @param array<string, string> $items Хеш строки => исходный текст.
	 */
	private static function userPrompt( array $items ): string {
		return (string) wp_json_encode( $items, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Разбирает ответ API в карту переводов.
	 *
	 * Модель иногда оборачивает JSON в markdown-блок или добавляет текст
	 * вокруг него, поэтому вместо строгого `json_decode` всего тела сначала
	 * вырезается подстрока между первой `{` и последней `}`.
	 *
	 * Результат фильтруется по запрошенным хешам: даже если модель вернёт
	 * посторонний ключ, он не попадёт в перевод чужой строки.
	 *
	 * @param string        $rawBody          Тело HTTP-ответа.
	 * @param list<string>  $requestedHashes  Хеши, которые были в запросе.
	 * @return array<string, string>
	 */
	public static function parseResponse( string $rawBody, array $requestedHashes ): array {
		$envelope = json_decode( $rawBody, true );

		if ( ! is_array( $envelope ) ) {
			return array();
		}

		$content = $envelope['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return array();
		}

		$decoded = self::decodeJsonObject( $content );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$allowed = array_fill_keys( $requestedHashes, true );
		$result  = array();

		foreach ( $decoded as $hash => $text ) {
			if ( is_string( $hash ) && isset( $allowed[ $hash ] ) && is_string( $text ) ) {
				$trimmed = trim( $text );

				if ( '' !== $trimmed ) {
					$result[ $hash ] = $trimmed;
				}
			}
		}

		return $result;
	}

	/**
	 * Сообщение об ошибке API, если оно есть в ответе.
	 *
	 * @param string $rawBody Тело HTTP-ответа.
	 */
	public static function errorMessage( string $rawBody ): ?string {
		$envelope = json_decode( $rawBody, true );

		$message = $envelope['error']['message'] ?? null;

		return is_string( $message ) && '' !== $message ? $message : null;
	}

	/**
	 * Достаёт и разбирает JSON-объект из произвольного текста.
	 *
	 * @param string $text Ответ модели.
	 * @return array<string, mixed>|null
	 */
	private static function decodeJsonObject( string $text ): ?array {
		$direct = json_decode( trim( $text ), true );

		if ( is_array( $direct ) ) {
			return $direct;
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
