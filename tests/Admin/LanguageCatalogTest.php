<?php
/**
 * Тесты каталога языков для выбора в таблице «Языки сайта».
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Admin\LanguageCatalog;
use LingoCanvas\Admin\SettingsPage;
use LingoCanvas\Settings\Settings;

#[CoversClass( LanguageCatalog::class )]
final class LanguageCatalogTest extends TestCase {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function apiSample(): array {
		return array(
			'de_DE' => array(
				'language'     => 'de_DE',
				'native_name'  => 'Deutsch',
				'english_name' => 'German',
				'iso'          => array( 1 => 'de', 2 => 'deu' ),
			),
			'pt_BR' => array(
				'language'     => 'pt_BR',
				'native_name'  => 'Português do Brasil',
				'english_name' => 'Portuguese (Brazil)',
				'iso'          => array( 1 => 'pt', 2 => 'por' ),
			),
			'ast'   => array(
				'language'     => 'ast',
				'native_name'  => 'Asturianu',
				'english_name' => 'Asturian',
				'iso'          => array( 2 => 'ast' ),
			),
		);
	}

	public function testUsesWordPressListAndAddsEnUs(): void {
		$all = LanguageCatalog::all( fn(): array => $this->apiSample() );

		$this->assertSame( array( 'de_DE', 'pt_BR', 'ast', 'en_US' ), array_keys( $all ) );
		$this->assertSame( 'de', $all['de_DE']['code'] );
		$this->assertSame( 'Deutsch', $all['de_DE']['native'] );
		// Нет ISO 639-1: код берётся из префикса локали.
		$this->assertSame( 'ast', $all['ast']['code'] );
		$this->assertSame( 'en', $all['en_US']['code'] );
	}

	public function testFallbackListWhenApiReturnsEmpty(): void {
		$all = LanguageCatalog::all( static fn(): array => array() );

		$this->assertArrayHasKey( 'ru_RU', $all );
		$this->assertArrayHasKey( 'en_US', $all );
		$this->assertArrayHasKey( 'pt_BR', $all );
	}

	public function testFallbackListWhenApiReturnsWpError(): void {
		$all = LanguageCatalog::all( static fn() => new \WP_Error( 'http', 'offline' ) );

		$this->assertArrayHasKey( 'de_DE', $all );
		$this->assertArrayHasKey( 'en_US', $all );
	}

	public function testDefaultSourceFallsBackWithoutWordPressFunction(): void {
		$all = LanguageCatalog::all();

		$this->assertArrayHasKey( 'en_US', $all );
		$this->assertArrayHasKey( 'fr_FR', $all );
	}

	public function testOptionsExcludeExistingLanguages(): void {
		$options = LanguageCatalog::options( array( 'de_DE', 'en_US' ), fn(): array => $this->apiSample() );
		$locales = array_column( $options, 'locale' );

		$this->assertNotContains( 'de_DE', $locales );
		$this->assertNotContains( 'en_US', $locales );
		$this->assertContains( 'pt_BR', $locales );
	}

	public function testFlagIsRegionOrLanguageCountry(): void {
		$this->assertSame( 'DE', LanguageCatalog::countryFor( 'de_DE' ) );
		$this->assertSame( 'BR', LanguageCatalog::countryFor( 'pt_BR' ) );
		$this->assertSame( 'JP', LanguageCatalog::countryFor( 'ja' ) );
		$this->assertSame( 'GB', LanguageCatalog::countryFor( 'en' ) );
		$this->assertSame( "\u{1F1E9}\u{1F1EA}", LanguageCatalog::flagFor( 'de_DE' ) );
		$this->assertSame( "\u{1F1E7}\u{1F1F7}", LanguageCatalog::flagFor( 'pt_BR' ) );
		$this->assertSame( "\u{1F1E7}\u{1F1EC}", LanguageCatalog::flagFor( 'bg_BG' ) );
		$this->assertSame( "\u{1F1EF}\u{1F1F5}", LanguageCatalog::flagFor( 'ja' ) );
		$this->assertSame( "\u{1F1EC}\u{1F1F7}", LanguageCatalog::flagFor( 'el' ) );
		$this->assertSame( "\u{1F1EC}\u{1F1E7}", LanguageCatalog::flagFor( 'en' ) );
		$this->assertSame( '', LanguageCatalog::flagFor( 'ast' ) );
		$this->assertLessThanOrEqual( 16, mb_strlen( LanguageCatalog::flagFor( 'de_DE' ) ) );
	}

	public function testOptionsCarryFlag(): void {
		$options = LanguageCatalog::options( array(), fn(): array => $this->apiSample() );
		$flags   = array_column( $options, 'flag', 'locale' );

		$this->assertSame( "\u{1F1E9}\u{1F1EA}", $flags['de_DE'] );
		$this->assertSame( "\u{1F1E7}\u{1F1F7}", $flags['pt_BR'] );
		$this->assertSame( '', $flags['ast'] );
	}

	public function testRenumberAlsoRenamesFlagFileInputs(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/settings.js' );

		$this->assertStringContainsString( "'flag_file_' + index", $js );
	}

	public function testLabelShowsNativeEnglishAndLocale(): void {
		$options = LanguageCatalog::options( array(), fn(): array => $this->apiSample() );
		$labels  = array_column( $options, 'label', 'locale' );

		$this->assertSame( 'Deutsch (German) — de_DE', $labels['de_DE'] );
	}

	public function testPageRendersPickerTemplateAndNoFixedPlaceholders(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php' );

		$this->assertStringNotContainsString( 'for ( $i = 0; $i < 2', $source );
		$this->assertStringNotContainsString( 'placeholder="English"', $source );
		$this->assertStringNotContainsString( 'placeholder="en_US"', $source );
		$this->assertStringContainsString( 'id="mlp-language-row-template"', $source );
		$this->assertStringContainsString( 'mlp-language-picker-list', $source );
		$this->assertStringContainsString( "renderRow( \$index++, null, 'blank' )", $source );
		// Все текстовые поля таблицы получают атрибуты против автозаполнения.
		$this->assertSame( 5, substr_count( $source, 'autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other"' ) );
		$this->assertStringNotContainsString( 'noFill', $source );
		$this->assertStringContainsString( 'autocomplete="off"', $source );
		$this->assertStringContainsString( 'data-lpignore="true"', $source );
		$this->assertStringContainsString( 'data-1p-ignore', $source );
		$this->assertStringContainsString( 'The language pack will be offered after saving', $source );
		$this->assertTrue( class_exists( SettingsPage::class ) );
	}

	public function testSettingsAcceptManyLanguagesAndRejectDuplicates(): void {
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );

		$settings = new Settings();
		$rows     = array();

		foreach ( array( 'ru', 'en', 'de', 'fr', 'it', 'pt-br' ) as $code ) {
			$rows[] = array( 'locale' => $code, 'slug' => $code, 'label' => strtoupper( $code ), 'wp_locale' => '' );
		}

		$result = $settings->sanitize( array( 'default_locale' => 'ru', 'languages' => $rows ) );

		$this->assertCount( 6, $result['settings']['languages'] );
		$this->assertSame( array(), $result['errors'] );

		$rows[]  = array( 'locale' => 'xx', 'slug' => 'de', 'label' => 'Dup' );
		$result2 = $settings->sanitize( array( 'default_locale' => 'ru', 'languages' => $rows ) );

		$this->assertCount( 6, $result2['settings']['languages'] );
		$this->assertNotSame( array(), $result2['errors'] );

		lingocanvas_test_options( array() );
	}

	public function testUtf8CharMatchesMbChrForAllRegionalIndicators(): void {
		foreach ( range( 'A', 'Z' ) as $letter ) {
			$codePoint = 0x1F1E6 + ord( $letter ) - 65;

			$this->assertSame( 4, strlen( LanguageCatalog::utf8Char( $codePoint ) ) );

			if ( function_exists( 'mb_chr' ) ) {
				$this->assertSame( mb_chr( $codePoint, 'UTF-8' ), LanguageCatalog::utf8Char( $codePoint ), $letter );
			}
		}
	}

	public function testFlagBytesForKnownCountries(): void {
		// U+1F1E7 U+1F1EC и U+1F1F7 U+1F1FA.
		$this->assertSame( "\xF0\x9F\x87\xA7\xF0\x9F\x87\xAC", LanguageCatalog::flagFor( 'bg_BG' ) );
		$this->assertSame( "\xF0\x9F\x87\xB7\xF0\x9F\x87\xBA", LanguageCatalog::flagFor( 'ru_RU' ) );
	}

	public function testUtf8CharAcrossByteRanges(): void {
		$this->assertSame( 'A', LanguageCatalog::utf8Char( 0x41 ) );
		$this->assertSame( "\x7F", LanguageCatalog::utf8Char( 0x7F ) );
		$this->assertSame( "\xC2\x80", LanguageCatalog::utf8Char( 0x80 ) );
		$this->assertSame( "\xD0\xB6", LanguageCatalog::utf8Char( 0x436 ) );
		$this->assertSame( "\xDF\xBF", LanguageCatalog::utf8Char( 0x7FF ) );
		$this->assertSame( "\xE0\xA0\x80", LanguageCatalog::utf8Char( 0x800 ) );
		$this->assertSame( "\xE2\x82\xAC", LanguageCatalog::utf8Char( 0x20AC ) );
		$this->assertSame( "\xEF\xBF\xBF", LanguageCatalog::utf8Char( 0xFFFF ) );
		$this->assertSame( "\xF0\x90\x80\x80", LanguageCatalog::utf8Char( 0x10000 ) );
		$this->assertSame( "\xF4\x8F\xBF\xBF", LanguageCatalog::utf8Char( 0x10FFFF ) );
	}

	public function testUtf8CharRejectsSurrogatesAndOutOfRange(): void {
		$this->assertSame( '', LanguageCatalog::utf8Char( 0xD800 ) );
		$this->assertSame( '', LanguageCatalog::utf8Char( 0xDFFF ) );
		$this->assertSame( '', LanguageCatalog::utf8Char( 0x110000 ) );
		$this->assertSame( '', LanguageCatalog::utf8Char( -1 ) );
	}
}
