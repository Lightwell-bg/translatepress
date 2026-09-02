<?php
/**
 * Тесты того, что переключатель языков показывает посетителю.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Settings\Language;
use WpMlp\Settings\SwitcherDisplay;

#[CoversClass( SwitcherDisplay::class )]
#[CoversClass( Language::class )]
final class SwitcherDisplayTest extends TestCase {

	private function language(): Language {
		return new Language( 'ru', 'ru', 'Русский', Language::STATUS_PUBLISHED, true, '', 'ru_RU' );
	}

	/**
	 * Значение приходит из формы настроек, то есть от кого угодно. Всё
	 * незнакомое обязано схлопываться в режим по умолчанию, а не идти
	 * дальше в шаблоны: там его никто не проверяет второй раз.
	 *
	 * @param string $raw      Значение из формы.
	 * @param string $expected Режим, который должен получиться.
	 */
	#[DataProvider( 'untrusted' )]
	public function testUnknownModeFallsBackToLabel( string $raw, string $expected ): void {
		$this->assertSame( $expected, SwitcherDisplay::sanitize( $raw ) );
	}

	/**
	 * @return list<array{string, string}>
	 */
	public static function untrusted(): array {
		return array(
			array( SwitcherDisplay::LABEL, SwitcherDisplay::LABEL ),
			array( SwitcherDisplay::CODE, SwitcherDisplay::CODE ),
			array( SwitcherDisplay::FLAG, SwitcherDisplay::FLAG ),
			array( SwitcherDisplay::FLAG_CODE, SwitcherDisplay::FLAG_CODE ),
			array( '', SwitcherDisplay::LABEL ),
			array( 'nonsense', SwitcherDisplay::LABEL ),
			array( 'LABEL', SwitcherDisplay::LABEL ),
			array( '<script>', SwitcherDisplay::LABEL ),
		);
	}

	/**
	 * Текстовая часть подписи. У режима «только флаг» её нет вовсе —
	 * подпись собирается из одной картинки.
	 */
	public function testTextPart(): void {
		$language = $this->language();

		$this->assertSame( 'Русский', SwitcherDisplay::text( $language, SwitcherDisplay::LABEL ) );
		$this->assertSame( 'RU', SwitcherDisplay::text( $language, SwitcherDisplay::CODE ) );
		$this->assertSame( 'RU', SwitcherDisplay::text( $language, SwitcherDisplay::FLAG_CODE ) );
		$this->assertSame( '', SwitcherDisplay::text( $language, SwitcherDisplay::FLAG ) );
	}

	/**
	 * `label` флаг показывает — это в точности прежний вид переключателя
	 * (флаг впереди, за ним название). Иначе у всех, кто когда-то вписал
	 * emoji, он молча пропал бы после обновления плагина.
	 */
	public function testFlagIsHiddenOnlyInTheCodeOnlyMode(): void {
		$this->assertTrue( SwitcherDisplay::showsFlag( SwitcherDisplay::FLAG ) );
		$this->assertTrue( SwitcherDisplay::showsFlag( SwitcherDisplay::FLAG_CODE ) );
		$this->assertTrue( SwitcherDisplay::showsFlag( SwitcherDisplay::LABEL ) );
		$this->assertFalse( SwitcherDisplay::showsFlag( SwitcherDisplay::CODE ) );
	}

	/**
	 * Язык без флага в режиме «название» обязан остаться просто названием.
	 * Подстановка кода вместо отсутствующего флага дала бы «RU Русский» —
	 * то есть поменяла бы вид сайтам, которые ничего не настраивали.
	 */
	public function testMissingFlagAddsNothingWhenThereIsAlreadyText(): void {
		$language = $this->language();

		$this->assertSame( '', SwitcherDisplay::fallbackFlag( $language, SwitcherDisplay::LABEL ) );
		$this->assertSame( '', SwitcherDisplay::fallbackFlag( $language, SwitcherDisplay::FLAG_CODE ) );

		// А вот здесь текста нет вовсе, и без кода подпись осталась бы пустой.
		$this->assertSame( 'RU', SwitcherDisplay::fallbackFlag( $language, SwitcherDisplay::FLAG ) );
	}

	/**
	 * Код языка — это `RU`, а не `ru_RU` и не слаг: локаль WordPress
	 * посетителю ничего не говорит, а слаг может отличаться от кода.
	 */
	public function testCodeIsTheLanguageCodeUppercased(): void {
		$this->assertSame( 'RU', $this->language()->switcherCode() );

		$regional = new Language( 'pt-br', 'br', 'Português', Language::STATUS_PUBLISHED, false, '', 'pt_BR' );

		$this->assertSame( 'PT-BR', $regional->switcherCode() );
	}

	/**
	 * Все режимы обязаны давать посетителю хоть что-то. Язык без названия,
	 * без флага и без файла-картинки не должен превращаться в пустую
	 * ссылку, по которой нечего нажать.
	 */
	#[DataProvider( 'modes' )]
	public function testNoModeEverProducesAnEmptySwitcherEntry( string $mode ): void {
		$bare = new Language( 'ru', 'ru', '', Language::STATUS_PUBLISHED, true, '', 'ru_RU' );

		$this->assertNotSame(
			'',
			SwitcherDisplay::text( $bare, $mode ) . SwitcherDisplay::fallbackFlag( $bare, $mode )
		);
	}

	/**
	 * @return list<array{string}>
	 */
	public static function modes(): array {
		return array_map( static fn( string $mode ): array => array( $mode ), SwitcherDisplay::all() );
	}

	/**
	 * Введённый вручную emoji — запасной вариант для языка, которому не
	 * положили SVG. Если нет и его, остаётся код: пустая подпись хуже
	 * любой непустой.
	 */
	public function testFallbackFlagPrefersEmojiThenCode(): void {
		$withEmoji = new Language( 'bg', 'bg', 'Bulgarian', Language::STATUS_PUBLISHED, false, '🇧🇬', 'bg_BG' );

		// Вписанный вручную emoji идёт впереди кода в любом режиме.
		$this->assertSame( '🇧🇬', SwitcherDisplay::fallbackFlag( $withEmoji, SwitcherDisplay::LABEL ) );
		$this->assertSame( '🇧🇬', SwitcherDisplay::fallbackFlag( $withEmoji, SwitcherDisplay::FLAG ) );

		$this->assertSame( 'RU', SwitcherDisplay::fallbackFlag( $this->language(), SwitcherDisplay::FLAG ) );
	}
}
