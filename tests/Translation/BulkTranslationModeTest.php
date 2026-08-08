<?php
/**
 * Тесты выбора сегментов для «Перевести весь материал с ИИ».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Translation\BulkTranslationMode;

#[CoversClass( BulkTranslationMode::class )]
final class BulkTranslationModeTest extends TestCase {

	public function testEmptyModeSkipsAlreadyTranslatedHashes(): void {
		$all      = array( 'aaa', 'bbb', 'ccc' );
		$existing = array( 'aaa' => 'Готовый перевод', 'bbb' => '' );

		$selected = BulkTranslationMode::selectForTranslation( $all, $existing, BulkTranslationMode::EMPTY );

		// aaa уже переведён — в ИИ не идёт вовсе. bbb (пустая строка) и ccc
		// (совсем нет записи) — оба нуждаются в переводе.
		$this->assertSame( array( 'bbb', 'ccc' ), $selected );
	}

	public function testEmptyModeWithNothingMissingSelectsNothing(): void {
		$all      = array( 'aaa', 'bbb' );
		$existing = array( 'aaa' => 'X', 'bbb' => 'Y' );

		$this->assertSame( array(), BulkTranslationMode::selectForTranslation( $all, $existing, BulkTranslationMode::EMPTY ) );
	}

	public function testAllModeIncludesEverythingRegardlessOfExistingTranslation(): void {
		$all      = array( 'aaa', 'bbb', 'ccc' );
		$existing = array( 'aaa' => 'Уже переведено и подтверждено' );

		$selected = BulkTranslationMode::selectForTranslation( $all, $existing, BulkTranslationMode::ALL );

		$this->assertSame( $all, $selected );
	}

	public function testUnknownModeStringDefaultsToEmptyBehaviour(): void {
		// Контроллер уже валидирует mode по enum до вызова — но сам класс
		// не должен молча «перевести всё» на постороннее значение.
		$all      = array( 'aaa' );
		$existing = array( 'aaa' => 'Переведено' );

		$this->assertSame( array(), BulkTranslationMode::selectForTranslation( $all, $existing, 'nonsense' ) );
	}

	public function testAllListsKnownModes(): void {
		$this->assertSame( array( 'empty', 'all' ), BulkTranslationMode::all() );
	}
}
