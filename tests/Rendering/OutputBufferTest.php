<?php
/**
 * Тесты явного парного закрытия буфера вывода.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rendering\OutputBuffer;

#[CoversClass( OutputBuffer::class )]
final class OutputBufferTest extends TestCase {

	/**
	 * Уровень буфера PHPUnit перед тестом.
	 */
	private int $baseLevel = 0;

	protected function setUp(): void {
		$this->baseLevel = ob_get_level();

		// До `wp` условные теги не вызываются (is_robots() в стабах нет).
		lingocanvas_test_wp( array( 'did_action' => array( 'wp' => 0 ) ) );

		// Внешний буфер собирает то, что наш буфер отдаст при закрытии.
		ob_start();
	}

	protected function tearDown(): void {
		lingocanvas_test_wp( array() );

		while ( ob_get_level() > $this->baseLevel ) {
			ob_end_clean();
		}
	}

	public function testFinishClosesBufferAndEmitsOutput(): void {
		$buffer = $this->buffer();

		$this->open( $buffer );
		echo 'plain text';

		$this->assertSame( $this->baseLevel + 2, ob_get_level() );

		$buffer->finish();

		$this->assertSame( $this->baseLevel + 1, ob_get_level() );
		$this->assertSame( 'plain text', ob_get_clean() );
	}

	public function testFinishIsNoopWhenNotStarted(): void {
		$buffer = $this->buffer();

		echo 'kept';
		$buffer->finish();

		$this->assertSame( $this->baseLevel + 1, ob_get_level() );
		$this->assertSame( 'kept', ob_get_clean() );
	}

	public function testFinishRunsOnlyOnce(): void {
		$buffer = $this->buffer();

		$this->open( $buffer );
		echo 'once';
		$buffer->finish();

		// Чужой буфер на том же уровне не должен быть закрыт повторным вызовом.
		ob_start();
		echo ' foreign';
		$buffer->finish();

		$this->assertSame( $this->baseLevel + 2, ob_get_level() );

		ob_end_flush();

		$this->assertSame( 'once foreign', ob_get_clean() );
	}

	public function testFinishIsNoopWhenAlreadyClosedByOthers(): void {
		$buffer = $this->buffer();

		$this->open( $buffer );
		echo 'closed elsewhere';
		ob_end_flush();

		$this->assertSame( $this->baseLevel + 1, ob_get_level() );

		$buffer->finish();

		$this->assertSame( $this->baseLevel + 1, ob_get_level() );
		$this->assertSame( 'closed elsewhere', ob_get_clean() );
	}

	public function testFinishIsNoopWhenLevelIsBelowOurs(): void {
		$buffer = $this->buffer();

		$this->open( $buffer );
		echo 'x';
		ob_end_flush();
		// Закрыт и внешний буфер: текущий уровень ниже нашего.
		$collected = (string) ob_get_clean();

		$this->assertSame( $this->baseLevel, ob_get_level() );

		$buffer->finish();

		$this->assertSame( $this->baseLevel, ob_get_level() );
		$this->assertSame( 'x', $collected );

		ob_start(); // Для tearDown.
	}

	public function testForeignBufferAboveOursIsFlushedFirst(): void {
		$buffer = $this->buffer();

		$this->open( $buffer );
		echo 'ours ';
		ob_start();
		echo 'foreign';
		ob_start( static fn( $chunk ): string => strtoupper( (string) $chunk ) );
		echo ' nested';

		$this->assertSame( $this->baseLevel + 4, ob_get_level() );

		$buffer->finish();

		$this->assertSame( $this->baseLevel + 1, ob_get_level() );
		$this->assertSame( 'ours foreign NESTED', ob_get_clean() );
	}

	private function buffer(): OutputBuffer {
		return ( new \ReflectionClass( OutputBuffer::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Открывает буфер так же, как start(), минуя проверку запроса.
	 */
	private function open( OutputBuffer $buffer ): void {
		$method = new \ReflectionMethod( $buffer, 'open' );
		$method->invoke( $buffer );
	}
}
