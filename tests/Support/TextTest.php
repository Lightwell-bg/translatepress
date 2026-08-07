<?php
/**
 * Тесты нормализации и отбора строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Support\Text;

#[CoversClass( Text::class )]
final class TextTest extends TestCase {

	public function testNormalizeCollapsesWhitespaceAndTrims(): void {
		$this->assertSame( 'Привет мир', Text::normalize( "\n   Привет\n\t мир  " ) );
	}

	/**
	 * Неразрывный пробел визуально неотличим от обычного: без нормализации
	 * одна и та же фраза попала бы в словарь дважды.
	 */
	public function testNormalizeReplacesNonBreakingSpace(): void {
		$this->assertSame( 'Привет мир', Text::normalize( "Привет\u{00A0}мир" ) );
		$this->assertSame( Text::normalize( 'Привет мир' ), Text::normalize( "Привет\u{00A0}мир" ) );
	}

	public function testSplitEdgesKeepsSurroundingWhitespace(): void {
		$this->assertSame( array( "\n  ", 'Привет', ' ' ), Text::splitEdges( "\n  Привет " ) );
		$this->assertSame( array( '', 'Привет', '' ), Text::splitEdges( 'Привет' ) );
	}

	#[DataProvider( 'translatable' )]
	public function testAcceptsRealPhrases( string $text ): void {
		$this->assertTrue( Text::isTranslatable( $text ), $text );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function translatable(): array {
		return array(
			array( 'Привет' ),
			array( 'Read more' ),
			array( '10 кг' ),
			array( 'Скидка 20% на всё' ),
			array( 'Мы в Москве' ),
		);
	}

	#[DataProvider( 'notTranslatable' )]
	public function testRejectsNonPhrases( string $text ): void {
		$this->assertFalse( Text::isTranslatable( $text ), $text );
	}

	/**
	 * Перевод этих строк ничего не даст, а иногда и сломает страницу.
	 *
	 * @return list<array{string}>
	 */
	public static function notTranslatable(): array {
		return array(
			array( '' ),
			array( '2024' ),
			array( '19,90 ₽' ),
			array( '—' ),
			array( '•' ),
			array( '50%' ),
			array( 'https://example.com/page' ),
			array( '//cdn.example.com/a.js' ),
			array( 'mailto:info@example.com' ),
			array( 'tel:+79990000000' ),
			array( 'style.css' ),
			array( 'example.com' ),
			array( '{{title}}' ),
			array( '{count}' ),
			array( '%1$s' ),
			array( '[contact-form-7 id="42"]' ),
		);
	}
}
