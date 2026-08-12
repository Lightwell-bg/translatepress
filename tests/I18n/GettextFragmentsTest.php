<?php
/**
 * Тесты разбора gettext-строки на куски будущих текстовых узлов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\I18n\GettextFragments;

#[CoversClass( GettextFragments::class )]
final class GettextFragmentsTest extends TestCase {

	/**
	 * Реальный случай с сайта: строка ядра со ссылками внутри превращается
	 * в три отдельных узла DOM, и ни один не равен ей целиком. Без разбора
	 * на куски все три оседали в «Контенте» как исходные строки.
	 */
	public function testCoreLoginStringYieldsItsLinkTexts(): void {
		$fragments = GettextFragments::of(
			'Logged in as %1$s. <a href="%2$s">Edit your profile</a>. <a href="%3$s">Log out?</a>'
		);

		$this->assertContains( 'Edit your profile', $fragments );
		$this->assertContains( 'Log out?', $fragments );
	}

	/**
	 * Второй реальный случай: после sprintf() плейсхолдер уезжает в
	 * отдельный элемент, а текст перед ним становится самостоятельным
	 * узлом — именно он и попадал в словарь на болгарском.
	 */
	public function testPlaceholderIsStrippedFromTheTail(): void {
		$fragments = GettextFragments::of( 'Задължителните полета са отбелязани с %s' );

		$this->assertContains( 'Задължителните полета са отбелязани с', $fragments );
	}

	/**
	 * Строка целиком тоже остаётся в наборе: у большинства строк
	 * плейсхолдеров нет вовсе, и совпадение по целому — основной путь.
	 */
	public function testPlainStringIsKeptWhole(): void {
		$this->assertSame( array( 'Add Comment' ), GettextFragments::of( 'Add Comment' ) );
	}

	/**
	 * Обрывки пунктуации между тегами переводом не являются: попади они в
	 * набор, по ним отсеялось бы постороннее содержимое страницы.
	 */
	public function testPunctuationBetweenTagsIsNotAFragment(): void {
		$fragments = GettextFragments::of( '<b>%s</b>. <i>%s</i>, <span>%s</span>' );

		$this->assertSame( array(), $fragments );
	}

	public function testEmptyStringYieldsNothing(): void {
		$this->assertSame( array(), GettextFragments::of( '' ) );
	}

	/**
	 * Повторяющийся кусок не дублируется — набор потом становится ключами
	 * массива, но лишние проходы всё равно ни к чему.
	 */
	public function testFragmentsAreUnique(): void {
		$fragments = GettextFragments::of( 'Save %1$s and Save %2$s' );

		$this->assertSame( array_unique( $fragments ), $fragments );
	}
}
