<?php
/**
 * Тесты защиты шорткодов от порчи переводом.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Support\ShortcodeGuard;

#[CoversClass( ShortcodeGuard::class )]
final class ShortcodeGuardTest extends TestCase {

	public function testDetectsShortcodeMixedWithText(): void {
		$this->assertTrue( ShortcodeGuard::containsShortcode( 'Нажмите [button url="/x"]здесь[/button], чтобы продолжить' ) );
		$this->assertTrue( ShortcodeGuard::containsShortcode( '[gallery ids="1,2,3"]' ) );
	}

	public function testPlainTextHasNoShortcode(): void {
		$this->assertFalse( ShortcodeGuard::containsShortcode( 'Обычный абзац без квадратных скобок' ) );
	}

	/**
	 * Квадратные скобки сами по себе — не шорткод: сноска `[1]` не должна
	 * false-positive'ить (имя тега обязано начинаться с буквы).
	 */
	public function testBareBracketsWithoutLetterStartAreNotAShortcode(): void {
		$this->assertFalse( ShortcodeGuard::containsShortcode( 'Текст [1] со сноской' ) );
	}

	public function testIsPreservedWhenIdentical(): void {
		$source     = 'Нажмите [button url="/x"]здесь[/button], чтобы продолжить';
		$translated = 'Click [button url="/x"]here[/button] to continue';

		$this->assertTrue( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	/**
	 * Стиль кавычек — форматирование, не изменение вызова шорткода.
	 */
	public function testIsPreservedIgnoresQuoteStyle(): void {
		$source     = "[button url='/x']здесь[/button]";
		$translated = '[button url="/x"]here[/button]';

		$this->assertTrue( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	public function testIsNotPreservedWhenShortcodeIsDropped(): void {
		$this->assertFalse(
			ShortcodeGuard::isPreserved(
				'Нажмите [button url="/x"]здесь[/button], чтобы продолжить',
				'Click here to continue'
			)
		);
	}

	public function testIsNotPreservedWhenTagNameChanges(): void {
		// Регистр имени тега не важен — это не смена шорткода.
		$this->assertTrue( ShortcodeGuard::isPreserved( '[gallery ids="1,2,3"]', '[Gallery ids="1,2,3"]' ) );

		// А вот другое имя — уже другой шорткод.
		$this->assertFalse( ShortcodeGuard::isPreserved( '[gallery ids="1,2,3"]', '[slider ids="1,2,3"]' ) );
	}

	public function testIsNotPreservedWhenOrderChanges(): void {
		$this->assertFalse(
			ShortcodeGuard::isPreserved( '[a]один[/a] и [b]два[/b]', '[b]two[/b] and [a]one[/a]' )
		);
	}

	/**
	 * Вложенность: перестановка внутреннего и внешнего тега местами — это
	 * уже другая структура, даже если оба тега на месте.
	 */
	public function testIsNotPreservedWhenNestingChanges(): void {
		$source     = '[outer][inner]текст[/inner][/outer]';
		$translated = '[inner][outer]text[/outer][/inner]';

		$this->assertFalse( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	public function testIsPreservedWhenNestingStaysTheSame(): void {
		$source     = '[outer class="x"][inner id="y"]текст[/inner][/outer]';
		$translated = '[outer class="x"][inner id="y"]text[/inner][/outer]';

		$this->assertTrue( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	/**
	 * Значение атрибута — не только его имя — обязано совпасть посимвольно.
	 */
	public function testIsNotPreservedWhenAttributeValueChanges(): void {
		$this->assertFalse(
			ShortcodeGuard::isPreserved( '[button url="/ru/contact/"]здесь[/button]', '[button url="/en/contact/"]here[/button]' )
		);
	}

	public function testIsNotPreservedWhenAttributeIsDropped(): void {
		$this->assertFalse(
			ShortcodeGuard::isPreserved( '[button url="/x" target="_blank"]здесь[/button]', '[button url="/x"]here[/button]' )
		);
	}

	public function testIsNotPreservedWhenAttributeIsAdded(): void {
		$this->assertFalse(
			ShortcodeGuard::isPreserved( '[button url="/x"]здесь[/button]', '[button url="/x" target="_blank"]here[/button]' )
		);
	}

	public function testIsNotPreservedWhenAttributeOrderChanges(): void {
		$this->assertFalse(
			ShortcodeGuard::isPreserved( '[b a="1" c="2"]текст[/b]', '[b c="2" a="1"]text[/b]' )
		);
	}

	/**
	 * Позиционные (без имени) атрибуты — WordPress их тоже поддерживает,
	 * например `[caption "подпись"]` — должны сравниваться по позиции.
	 */
	public function testComparesPositionalAttributes(): void {
		$this->assertTrue( ShortcodeGuard::isPreserved( '[caption wide]текст[/caption]', '[caption wide]text[/caption]' ) );
		$this->assertFalse( ShortcodeGuard::isPreserved( '[caption wide]текст[/caption]', '[caption narrow]text[/caption]' ) );
	}

	public function testSelfClosingMismatchIsNotPreserved(): void {
		$this->assertFalse( ShortcodeGuard::isPreserved( '[gallery ids="1,2,3" /]', '[gallery ids="1,2,3"]' ) );
	}

	public function testIsPreservedTriviallyTrueWithoutShortcodes(): void {
		$this->assertTrue( ShortcodeGuard::isPreserved( 'Привет', 'Hello' ) );
	}

	/**
	 * Число шорткодов само по себе — тоже часть последовательности:
	 * пропавший ВТОРОЙ шорткод при сохранённом первом всё равно расхождение.
	 */
	public function testIsNotPreservedWhenOneOfSeveralShortcodesIsLost(): void {
		$source     = '[a]раз[/a] текст [b]два[/b]';
		$translated = '[a]one[/a] text';

		$this->assertFalse( ShortcodeGuard::isPreserved( $source, $translated ) );
	}
}
