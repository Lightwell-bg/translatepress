<?php
/**
 * Тесты сборки запроса и разбора ответа OpenAI.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Segment;
use WpMlp\Translation\OpenAiRequestBuilder;
use WpMlp\Translation\TranslationContext;

#[CoversClass( OpenAiRequestBuilder::class )]
final class OpenAiRequestBuilderTest extends TestCase {

	public function testPayloadCarriesModelAndItemsAsJson(): void {
		$payload = OpenAiRequestBuilder::buildPayload(
			array( 'hash1' => 'Купить' ),
			'gpt-5.6-terra',
			'ru',
			'en',
			new TranslationContext()
		);

		$this->assertSame( 'gpt-5.6-terra', $payload['model'] );
		$this->assertCount( 2, $payload['messages'] );
		$this->assertSame( 'system', $payload['messages'][0]['role'] );
		$this->assertSame( 'user', $payload['messages'][1]['role'] );
		$this->assertSame( array( 'hash1' => 'Купить' ), json_decode( $payload['messages'][1]['content'], true ) );
	}

	public function testHtmlBlockContextMentionsPreservingTags(): void {
		$payload = OpenAiRequestBuilder::buildPayload(
			array( 'hash1' => 'Читайте <b>наш</b> блог' ),
			'gpt-5.6-terra',
			'ru',
			'en',
			new TranslationContext( Segment::KIND_HTML_BLOCK )
		);

		$this->assertStringContainsString( 'HTML', $payload['messages'][0]['content'] );
	}

	public function testGlossaryIsIncludedInSystemPrompt(): void {
		$payload = OpenAiRequestBuilder::buildPayload(
			array( 'hash1' => 'Bright Store' ),
			'gpt-5.6-terra',
			'ru',
			'en',
			new TranslationContext( 'text', null, null, null, array( 'Bright Store' => 'Bright Store' ) )
		);

		$this->assertStringContainsString( 'Bright Store', $payload['messages'][0]['content'] );
	}

	public function testParseResponseExtractsMappingFromChatCompletion(): void {
		$body = wp_json_encode(
			array(
				'choices' => array(
					array( 'message' => array( 'content' => '{"hash1":"Buy","hash2":"Cat"}' ) ),
				),
			)
		);

		$result = OpenAiRequestBuilder::parseResponse( (string) $body, array( 'hash1', 'hash2' ) );

		$this->assertSame( array( 'hash1' => 'Buy', 'hash2' => 'Cat' ), $result );
	}

	/**
	 * Модель иногда оборачивает JSON в markdown-блок или добавляет пояснение —
	 * разбор должен вытащить объект из окружающего текста.
	 */
	public function testParseResponseToleratesSurroundingText(): void {
		$body = wp_json_encode(
			array(
				'choices' => array(
					array( 'message' => array( 'content' => "Here you go:\n```json\n{\"hash1\":\"Buy\"}\n```" ) ),
				),
			)
		);

		$result = OpenAiRequestBuilder::parseResponse( (string) $body, array( 'hash1' ) );

		$this->assertSame( array( 'hash1' => 'Buy' ), $result );
	}

	/**
	 * Ключи, которых не было в запросе, не должны просочиться в результат:
	 * иначе модель могла бы подменить перевод чужой строки.
	 */
	public function testParseResponseFiltersUnrequestedKeys(): void {
		$body = wp_json_encode(
			array(
				'choices' => array(
					array( 'message' => array( 'content' => '{"hash1":"Buy","injected":"evil"}' ) ),
				),
			)
		);

		$result = OpenAiRequestBuilder::parseResponse( (string) $body, array( 'hash1' ) );

		$this->assertSame( array( 'hash1' => 'Buy' ), $result );
	}

	public function testParseResponseReturnsEmptyOnMalformedBody(): void {
		$this->assertSame( array(), OpenAiRequestBuilder::parseResponse( 'not json at all', array( 'hash1' ) ) );
		$this->assertSame( array(), OpenAiRequestBuilder::parseResponse( '{}', array( 'hash1' ) ) );
	}

	public function testParseResponseDropsEmptyTranslations(): void {
		$body = wp_json_encode(
			array(
				'choices' => array(
					array( 'message' => array( 'content' => '{"hash1":"","hash2":"Cat"}' ) ),
				),
			)
		);

		$result = OpenAiRequestBuilder::parseResponse( (string) $body, array( 'hash1', 'hash2' ) );

		$this->assertSame( array( 'hash2' => 'Cat' ), $result );
	}

	public function testErrorMessageExtractsApiError(): void {
		$body = wp_json_encode( array( 'error' => array( 'message' => 'Invalid model' ) ) );

		$this->assertSame( 'Invalid model', OpenAiRequestBuilder::errorMessage( (string) $body ) );
		$this->assertNull( OpenAiRequestBuilder::errorMessage( '{"ok":true}' ) );
	}
}
