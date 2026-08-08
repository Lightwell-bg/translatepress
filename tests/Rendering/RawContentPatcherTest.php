<?php
/**
 * Тесты точечной подстановки перевода в исходную строку.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\RawContentPatcher;

#[CoversClass( RawContentPatcher::class )]
final class RawContentPatcherTest extends TestCase {

	public function testReplacesASingleMatch(): void {
		$result = RawContentPatcher::apply(
			'<p>Привет мир</p>',
			array( array( 'candidates' => array( 'Привет мир' ), 'replacement' => 'Hello world' ) )
		);

		$this->assertSame( '<p>Hello world</p>', $result );
	}

	public function testEverythingOutsideTheMatchIsCopiedVerbatim(): void {
		$original = '<div class="highlight" style="color:red" data-x="1"><p>Текст</p></div>';
		$result   = RawContentPatcher::apply(
			$original,
			array( array( 'candidates' => array( 'Текст' ), 'replacement' => 'Text' ) )
		);

		$this->assertSame(
			'<div class="highlight" style="color:red" data-x="1"><p>Text</p></div>',
			$result
		);
	}

	/**
	 * Ровно то, из-за чего DOM-пересборка не годится: самозакрывающий тег
	 * `<img .../>` не должен потерять слеш только потому, что где-то рядом
	 * меняется текст.
	 */
	public function testSelfClosingSlashSurvivesUntouched(): void {
		$original = '<p>Раньше</p><img src="a.jpg" alt="Alt" /><p>Позже</p>';
		$result   = RawContentPatcher::apply(
			$original,
			array(
				array( 'candidates' => array( 'Раньше' ), 'replacement' => 'Before' ),
				array( 'candidates' => array( 'Позже' ), 'replacement' => 'After' ),
			)
		);

		$this->assertSame( '<p>Before</p><img src="a.jpg" alt="Alt" /><p>After</p>', $result );
	}

	public function testMultiplePatchesApplyInOrder(): void {
		$result = RawContentPatcher::apply(
			'<h1>Раз</h1><p>Два</p><p>Три</p>',
			array(
				array( 'candidates' => array( 'Раз' ), 'replacement' => 'One' ),
				array( 'candidates' => array( 'Два' ), 'replacement' => 'Two' ),
				array( 'candidates' => array( 'Три' ), 'replacement' => 'Three' ),
			)
		);

		$this->assertSame( '<h1>One</h1><p>Two</p><p>Three</p>', $result );
	}

	/**
	 * Одинаковый текст в двух местах — курсор идёт только вперёд, поэтому
	 * первое вхождение достаётся первому патчу, а не оба одному и тому же.
	 */
	public function testRepeatedTextIsMatchedInOrderNotBothAtOnce(): void {
		$result = RawContentPatcher::apply(
			'<li>Подробнее</li><li>Подробнее</li>',
			array(
				array( 'candidates' => array( 'Подробнее' ), 'replacement' => 'More (1)' ),
				array( 'candidates' => array( 'Подробнее' ), 'replacement' => 'More (2)' ),
			)
		);

		$this->assertSame( '<li>More (1)</li><li>More (2)</li>', $result );
	}

	/**
	 * Патчи не в том порядке, что в тексте, — курсор не может пойти назад,
	 * поэтому второй патч не находится. Это не ошибка автора теста, а
	 * ровно то поведение, которого требует контракт: подставлять НЕКУДА.
	 */
	public function testOutOfOrderPatchesFailSafely(): void {
		$result = RawContentPatcher::apply(
			'<p>Первый</p><p>Второй</p>',
			array(
				array( 'candidates' => array( 'Второй' ), 'replacement' => 'Second' ),
				array( 'candidates' => array( 'Первый' ), 'replacement' => 'First' ),
			)
		);

		$this->assertNull( $result );
	}

	public function testMissingSearchTextReturnsNullInsteadOfGuessing(): void {
		$result = RawContentPatcher::apply(
			'<p>Текст</p>',
			array( array( 'candidates' => array( 'Совсем другое' ), 'replacement' => 'Something else' ) )
		);

		$this->assertNull( $result );
	}

	/**
	 * Второй кандидат используется, только если первый не нашёлся —
	 * например, когда исходник хранит амперсанд как `&amp;`, а не буквально.
	 */
	public function testFallsBackToTheSecondCandidateWhenTheFirstIsAbsent(): void {
		$result = RawContentPatcher::apply(
			'<p>Tom &amp; Jerry</p>',
			array( array( 'candidates' => array( 'Tom & Jerry', 'Tom &amp; Jerry' ), 'replacement' => 'Tom and Jerry' ) )
		);

		$this->assertSame( '<p>Tom and Jerry</p>', $result );
	}

	public function testEmptyPatchListReturnsOriginalUnchanged(): void {
		$this->assertSame( '<p>Как есть</p>', RawContentPatcher::apply( '<p>Как есть</p>', array() ) );
	}

	public function testEmptyCandidateStringsAreSkippedNotMatched(): void {
		// Пустая строка технически "находится" везде — это защита от
		// случайно пустого кандидата, который иначе совпал бы в позиции 0.
		$result = RawContentPatcher::apply(
			'<p>Текст</p>',
			array( array( 'candidates' => array( '' ), 'replacement' => 'ЛОВУШКА' ) )
		);

		$this->assertNull( $result );
	}
}
