<?php
/**
 * Тесты поиска официального перевода чужой строки в объекте переводов домена.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\I18n\OfficialTranslation;

/**
 * Объект в стиле классических `Translations`/`MO`: есть select_plural_form()
 * с русским правилом (три формы).
 */
final class LegacyStyleTranslations {

	/**
	 * @param array<string, string>       $entries Ключ "ctx\4msgid" или "msgid" => перевод.
	 * @param array<string, list<string>> $plurals Ключ как у $entries => формы.
	 */
	public function __construct( private array $entries, private array $plurals ) {
	}

	public function key( string $text, string $context ): string {
		return '' === $context ? $text : $context . "\4" . $text;
	}

	public function translate( $singular, $context = null ) {
		$key = $this->key( (string) $singular, (string) $context );

		return $this->entries[ $key ] ?? $singular;
	}

	public function select_plural_form( $count ) {
		$count = abs( (int) $count );

		if ( 1 === $count % 10 && 11 !== $count % 100 ) {
			return 0;
		}

		if ( $count % 10 >= 2 && $count % 10 <= 4 && ( $count % 100 < 12 || $count % 100 > 14 ) ) {
			return 1;
		}

		return 2;
	}

	public function translate_plural( $singular, $plural, $count, $context = null ) {
		$forms = $this->plurals[ $this->key( (string) $singular, (string) $context ) ] ?? null;

		if ( null === $forms ) {
			return 1 === (int) $count ? $singular : $plural;
		}

		return $forms[ $this->select_plural_form( $count ) ] ?? $singular;
	}
}

/**
 * Объект в стиле WP_Translations (WordPress 6.5+): select_plural_form() нет,
 * правило множественного числа применяется внутри translate_plural().
 */
final class ControllerStyleTranslations {

	/**
	 * @param array<string, string>       $entries Ключ "ctx\4msgid" или "msgid" => перевод.
	 * @param array<string, list<string>> $plurals Ключ как у $entries => формы.
	 */
	public function __construct( private array $entries, private array $plurals ) {
	}

	public function translate( $singular, $context = null ) {
		$key = ( '' === (string) $context ? '' : $context . "\4" ) . $singular;

		return $this->entries[ $key ] ?? $singular;
	}

	public function translate_plural( $singular, $plural, $count, $context = null ) {
		$key   = ( '' === (string) $context ? '' : $context . "\4" ) . $singular;
		$forms = $this->plurals[ $key ] ?? null;

		if ( null === $forms ) {
			return 1 === (int) $count ? $singular : $plural;
		}

		// Собственное правило файла: две формы.
		return $forms[ 1 === (int) $count ? 0 : 1 ] ?? $singular;
	}
}

/**
 * Объект в стиле NOOP_Translations: переводов нет, возвращаются оригиналы.
 */
final class NoopStyleTranslations {

	public function translate( $singular, $context = null ) {
		return $singular;
	}

	public function select_plural_form( $count ) {
		return 1 === (int) $count ? 0 : 1;
	}

	public function translate_plural( $singular, $plural, $count, $context = null ) {
		return 1 === (int) $count ? $singular : $plural;
	}
}

#[CoversClass( OfficialTranslation::class )]
final class OfficialTranslationTest extends TestCase {

	private function legacy(): LegacyStyleTranslations {
		return new LegacyStyleTranslations(
			array(
				'Save'           => 'Сохранить',
				"menu\4Save"     => 'Сохранить (меню)',
				'Same'           => 'Same',
			),
			array(
				'%d item' => array( '%d элемент', '%d элемента', '%d элементов' ),
			)
		);
	}

	private function controller(): ControllerStyleTranslations {
		return new ControllerStyleTranslations(
			array( 'Save' => 'Сохранить' ),
			array( '%d item' => array( '%d элемент', '%d элементов' ) )
		);
	}

	public function testSingularFound(): void {
		$this->assertSame( 'Сохранить', OfficialTranslation::lookup( $this->legacy(), 'Save', '', null ) );
		$this->assertSame( 'Сохранить', OfficialTranslation::lookup( $this->controller(), 'Save', '', null ) );
	}

	public function testSingularMissingReturnsNull(): void {
		$this->assertNull( OfficialTranslation::lookup( $this->legacy(), 'Missing', '', null ) );
		$this->assertNull( OfficialTranslation::lookup( $this->controller(), 'Missing', '', null ) );
	}

	public function testTranslationEqualToMsgidReturnsNull(): void {
		$this->assertNull( OfficialTranslation::lookup( $this->legacy(), 'Same', '', null ) );
	}

	public function testContextEntryFoundOnlyWithRightContext(): void {
		$this->assertSame( 'Сохранить (меню)', OfficialTranslation::lookup( $this->legacy(), 'Save', 'menu', null ) );
		$this->assertSame( 'Сохранить', OfficialTranslation::lookup( $this->legacy(), 'Save', '', null ) );
		$this->assertNull( OfficialTranslation::lookup( $this->legacy(), 'Save', 'other', null ) );
	}

	public function testPluralKeysMapToRussianFormsWithSelectPluralForm(): void {
		$t = $this->legacy();

		$this->assertSame( '%d элемент', OfficialTranslation::lookup( $t, '%d item', '', 0 ) );
		$this->assertSame( '%d элемента', OfficialTranslation::lookup( $t, '%d item', '', 1 ) );
		$this->assertSame( '%d элементов', OfficialTranslation::lookup( $t, '%d item', '', 2 ) );
		$this->assertNull( OfficialTranslation::lookup( $t, '%d item', '', 3 ) );
	}

	public function testSampleNumberWithSelectPluralForm(): void {
		$t = $this->legacy();

		$this->assertSame( 1, OfficialTranslation::sampleNumber( $t, 0 ) );
		$this->assertSame( 2, OfficialTranslation::sampleNumber( $t, 1 ) );
		$this->assertSame( 0, OfficialTranslation::sampleNumber( $t, 2 ) );
		$this->assertNull( OfficialTranslation::sampleNumber( $t, 3 ) );
	}

	public function testPluralWithoutSelectPluralFormUsesEnglishRule(): void {
		$t = $this->controller();

		$this->assertSame( 1, OfficialTranslation::sampleNumber( $t, 0 ) );
		$this->assertSame( 2, OfficialTranslation::sampleNumber( $t, 1 ) );
		$this->assertNull( OfficialTranslation::sampleNumber( $t, 2 ) );

		$this->assertSame( '%d элемент', OfficialTranslation::lookup( $t, '%d item', '', 0 ) );
		$this->assertSame( '%d элементов', OfficialTranslation::lookup( $t, '%d item', '', 1 ) );
		$this->assertNull( OfficialTranslation::lookup( $t, '%d item', '', 2 ) );
	}

	public function testNegativeKeyReturnsNull(): void {
		$this->assertNull( OfficialTranslation::lookup( $this->legacy(), '%d item', '', -1 ) );
		$this->assertNull( OfficialTranslation::lookup( $this->controller(), '%d item', '', -1 ) );
		$this->assertNull( OfficialTranslation::sampleNumber( $this->legacy(), -1 ) );
	}

	public function testEmptyMsgidReturnsNull(): void {
		$this->assertNull( OfficialTranslation::lookup( $this->legacy(), '', '', null ) );
		$this->assertNull( OfficialTranslation::lookup( $this->legacy(), '', '', 0 ) );
	}

	public function testNoopTranslationsGiveNull(): void {
		$t = new NoopStyleTranslations();

		$this->assertNull( OfficialTranslation::lookup( $t, 'Save', '', null ) );
		$this->assertNull( OfficialTranslation::lookup( $t, 'Save', 'menu', null ) );
		$this->assertNull( OfficialTranslation::lookup( $t, '%d item', '', 0 ) );
		$this->assertNull( OfficialTranslation::lookup( $t, '%d item', '', 1 ) );
	}
}
