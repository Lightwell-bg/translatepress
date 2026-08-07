<?php
/**
 * Тесты хеширования строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\TestCase;
use WpMlp\Support\Hash;

/**
 * @covers \WpMlp\Support\Hash
 */
final class HashTest extends TestCase {

	public function testProducesLowercaseHexOf64Chars(): void {
		$hash = Hash::of( 'Привет' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	public function testSameInputGivesSameHash(): void {
		$this->assertSame( Hash::of( 'Текст' ), Hash::of( 'Текст' ) );
		$this->assertNotSame( Hash::of( 'Текст' ), Hash::of( 'текст' ) );
	}

	/**
	 * Разделитель обязателен: без него ключи ('ab','c') и ('a','bc')
	 * склеились бы в одну строку и дали одинаковый хеш.
	 */
	public function testPartsAreSeparated(): void {
		$this->assertNotSame(
			Hash::ofParts( array( 'ab', 'c' ) ),
			Hash::ofParts( array( 'a', 'bc' ) )
		);
	}

	public function testNullPartIsTreatedAsEmptyString(): void {
		$this->assertSame(
			Hash::ofParts( array( 'en', null, 0 ) ),
			Hash::ofParts( array( 'en', '', '0' ) )
		);
	}

	public function testValidationRejectsAnythingButHex(): void {
		$this->assertTrue( Hash::isValid( Hash::of( 'x' ) ) );

		$this->assertFalse( Hash::isValid( '' ) );
		$this->assertFalse( Hash::isValid( str_repeat( 'z', 64 ) ) );
		$this->assertFalse( Hash::isValid( strtoupper( Hash::of( 'x' ) ) ) );
		$this->assertFalse( Hash::isValid( "' OR 1=1 --" ) );
	}
}
