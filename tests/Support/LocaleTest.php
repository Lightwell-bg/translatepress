<?php
/**
 * Тесты валидации языковых кодов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Support\Locale;

#[CoversClass( Locale::class )]
final class LocaleTest extends TestCase {

	public function testNormalizeLowercasesAndReplacesUnderscore(): void {
		$this->assertSame( 'pt-br', Locale::normalize( ' pt_BR ' ) );
		$this->assertSame( 'ru', Locale::normalize( 'RU' ) );
	}

	#[DataProvider( 'validLocales' )]
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

	#[DataProvider( 'invalidLocales' )]
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

	/**
	 * Языки без явного региона в настройках («ru», «bg», не «ru-RU»)
	 * обязаны получить полный `xx_XX` — Facebook не принимает голый код.
	 */
	public function testOgLocaleDoublesTheLanguageCodeAsRegionByDefault(): void {
		$this->assertSame( 'ru_RU', Locale::toOgLocale( 'ru' ) );
		$this->assertSame( 'bg_BG', Locale::toOgLocale( 'bg' ) );
		$this->assertSame( 'de_DE', Locale::toOgLocale( 'de' ) );
	}

	/**
	 * Языки, где удвоение кода даёт неверную страну, — из таблицы исключений.
	 */
	public function testOgLocaleUsesExceptionTableWhereDoublingIsWrong(): void {
		$this->assertSame( 'en_US', Locale::toOgLocale( 'en' ) );
		$this->assertSame( 'zh_CN', Locale::toOgLocale( 'zh' ) );
		$this->assertSame( 'ja_JP', Locale::toOgLocale( 'ja' ) );
	}

	/**
	 * Код с уже заданным регионом использует его, а не таблицу по умолчанию.
	 */
	public function testOgLocaleKeepsExplicitRegion(): void {
		$this->assertSame( 'pt_BR', Locale::toOgLocale( 'pt-br' ) );
		$this->assertSame( 'en_GB', Locale::toOgLocale( 'en-gb' ) );
	}
}
