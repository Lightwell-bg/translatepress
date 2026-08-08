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
	 * Квадратные скобки сами по себе — не шорткод: `[citation needed]`,
	 * смайлик `:-]` и подобное не должны false-positive'ить.
	 */
	public function testBareBracketsWithoutLetterStartAreNotAShortcode(): void {
		$this->assertFalse( ShortcodeGuard::containsShortcode( 'Текст [1] со сноской' ) );
	}

	public function testTagsListsOpeningAndClosingInOrder(): void {
		$this->assertSame(
			array( '[button url="/x"]', '[/button]' ),
			ShortcodeGuard::tags( 'Нажмите [button url="/x"]здесь[/button], чтобы продолжить' )
		);
	}

	public function testIsPreservedWhenTagsMatchExactly(): void {
		$source     = 'Нажмите [button url="/x"]здесь[/button], чтобы продолжить';
		$translated = 'Click [button url="/x"]here[/button] to continue';

		$this->assertTrue( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	/**
	 * Мелкая переформатировка атрибута (одинарные вместо двойных кавычек)
	 * не должна считаться порчей — шорткод продолжит работать.
	 */
	public function testIsPreservedIgnoresAttributeFormatting(): void {
		$source     = "[button url='/x']здесь[/button]";
		$translated = '[button url="/x"]here[/button]';

		$this->assertTrue( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	public function testIsNotPreservedWhenShortcodeIsDropped(): void {
		$source     = 'Нажмите [button url="/x"]здесь[/button], чтобы продолжить';
		$translated = 'Click here to continue';

		$this->assertFalse( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	public function testIsNotPreservedWhenTagNameChanges(): void {
		$source     = '[gallery ids="1,2,3"]';
		$translated = '[Gallery ids="1,2,3"]';

		// Имя тега сравнивается без учёта регистра — это НЕ порча.
		$this->assertTrue( ShortcodeGuard::isPreserved( $source, $translated ) );

		$translated = '[slider ids="1,2,3"]';

		// А вот подмена имени тега — уже другой шорткод, порча.
		$this->assertFalse( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	public function testIsNotPreservedWhenOrderChanges(): void {
		$source     = '[a]один[/a] и [b]два[/b]';
		$translated = '[b]two[/b] and [a]one[/a]';

		$this->assertFalse( ShortcodeGuard::isPreserved( $source, $translated ) );
	}

	public function testIsPreservedTriviallyTrueWithoutShortcodes(): void {
		$this->assertTrue( ShortcodeGuard::isPreserved( 'Привет', 'Hello' ) );
	}
}
