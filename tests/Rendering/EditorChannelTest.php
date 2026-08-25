<?php
/**
 * Тесты токена канала между панелью редактора и предпросмотром.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\EditorContext;

#[CoversClass( EditorContext::class )]
final class EditorChannelTest extends TestCase {

	protected function tearDown(): void {
		unset( $_GET[ EditorContext::QUERY_CHANNEL ] );

		parent::tearDown();
	}

	/**
	 * Значение приезжает из адресной строки, то есть от кого угодно.
	 * Наружу должно проходить только то, что могли выдать мы сами.
	 *
	 * @param string $raw      Значение параметра в запросе.
	 * @param string $expected Что должно остаться после разбора.
	 */
	#[DataProvider( 'channelValues' )]
	public function testOnlyWellFormedTokensSurvive( string $raw, string $expected ): void {
		$_GET[ EditorContext::QUERY_CHANNEL ] = $raw;

		$this->assertSame( $expected, EditorContext::channelFromRequest() );
	}

	/**
	 * @return list<array{string, string}>
	 */
	public static function channelValues(): array {
		$valid = str_repeat( 'a1B2', 8 );

		return array(
			// Такой токен и выдаёт wp_generate_password( 32, false ).
			array( $valid, $valid ),
			array( str_repeat( 'x', 16 ), str_repeat( 'x', 16 ) ),
			array( str_repeat( 'x', 64 ), str_repeat( 'x', 64 ) ),

			// Слишком короткий — подбирается перебором.
			array( str_repeat( 'x', 15 ), '' ),
			array( '', '' ),

			// Длиннее любого выданного нами: чужое значение.
			array( str_repeat( 'x', 65 ), '' ),

			/*
			 * Ничего, кроме букв и цифр: токен уходит в адрес предпросмотра,
			 * и всё, что могло бы там что-то значить — разделители запроса,
			 * кавычки, угловые скобки, — до адреса не доходит вовсе.
			 */
			array( 'abcdefghijklmnop&mlp_editor=1', '' ),
			array( 'abcdefghijklmnop"onload=alert(1)', '' ),
			array( '<script>abcdefghijklmnop</script>', '' ),
			array( 'abcdefghijklmnop/../../etc', '' ),
			array( 'abcdefghij klmnop', '' ),
		);
	}

	public function testMissingParameterIsEmpty(): void {
		unset( $_GET[ EditorContext::QUERY_CHANNEL ] );

		$this->assertSame( '', EditorContext::channelFromRequest() );
	}

	/**
	 * Предпросмотр открывается по адресу, в котором токен уже стоит:
	 * иначе его сторона не с чем будет сверять сообщения.
	 */
	public function testPreviewUrlCarriesTheToken(): void {
		$url = EditorContext::previewUrl( 'https://example.test/en/page/', 'abcDEF1234567890' );

		$this->assertStringContainsString( EditorContext::QUERY_CHANNEL . '=abcDEF1234567890', $url );
		$this->assertStringContainsString( EditorContext::QUERY_FLAG . '=1', $url );
	}

	/**
	 * Без токена адрес остаётся ровно таким, каким был до появления
	 * канала, — пустой параметр в запросе не нужен никому.
	 */
	public function testPreviewUrlWithoutTokenHasNoEmptyParameter(): void {
		$url = EditorContext::previewUrl( 'https://example.test/en/page/' );

		$this->assertStringNotContainsString( EditorContext::QUERY_CHANNEL, $url );
	}
}
