<?php
/**
 * Тесты валидации языковых кодов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\TestCase;
use WpMlp\Support\Locale;

/**
 * @covers \WpMlp\Support\Locale
 */
final class LocaleTest extends TestCase {

	public function testNormalizeLowercasesAndReplacesUnderscore(): void {
		$this->assertSame( 'pt-br', Locale::normalize( ' pt_BR ' ) );
		$this->assertSame( 'ru', Locale::normalize( 'RU' ) );
	}

	/**
	 * @dataProvider validLocales
	 */
	public function testAcceptsValidLocales( string $locale ): void {
		$this->assertTrue( Locale::isValid( $locale ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function validLocales(): array {
		return array(
			array( 'ru' ),
			array( 'en' ),
			array( 'pt-br' ),
			array( 'pt_BR' ),
			array( 'zh-hans-cn' ),
		);
	}

	/**
	 * @dataProvider invalidLocales
	 */
	public function testRejectsInvalidLocales( string $locale ): void {
		$this->assertFalse( Locale::isValid( $locale ) );
	}

	/**
	 * Отклоняем всё, что могло бы утечь в SQL, ключ кэша или путь.
	 *
	 * @return list<array{string}>
	 */
	public static function invalidLocales(): array {
		return array(
			array( '' ),
			array( 'e' ),
			array( 'en;DROP TABLE wp_posts' ),
			array( '../en' ),
			array( 'en/ru' ),
			array( 'русский' ),
			array( 'abcdefghijklmnopqrstuvwxyz' ),
			array( '1en' ),
		);
	}

	public function testSlugValidation(): void {
		$this->assertTrue( Locale::isValidSlug( 'en' ) );
		$this->assertTrue( Locale::isValidSlug( 'pt-br' ) );
		$this->assertTrue( Locale::isValidSlug( 'en2' ) );

		$this->assertFalse( Locale::isValidSlug( '' ) );
		$this->assertFalse( Locale::isValidSlug( 'en/' ) );
		$this->assertFalse( Locale::isValidSlug( 'en ru' ) );
		$this->assertFalse( Locale::isValidSlug( '-en' ) );
		$this->assertFalse( Locale::isValidSlug( 'ЕН' ) );
	}

	public function testBcp47FormatsSubtags(): void {
		$this->assertSame( 'ru', Locale::toBcp47( 'ru' ) );
		$this->assertSame( 'pt-BR', Locale::toBcp47( 'pt_br' ) );
		$this->assertSame( 'zh-Hans-CN', Locale::toBcp47( 'zh-hans-cn' ) );
	}
}
