<?php
/**
 * Тесты разбивки строк на чанки для провайдера перевода.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Translation\BatchChunker;

#[CoversClass( BatchChunker::class )]
final class BatchChunkerTest extends TestCase {

	public function testSmallBatchFitsInOneChunk(): void {
		$items  = array( 'a' => 'Привет', 'b' => 'Мир' );
		$chunks = BatchChunker::chunk( $items, 1000 );

		$this->assertSame( array( $items ), $chunks );
	}

	public function testSplitsWhenBudgetExceeded(): void {
		$items = array(
			'a' => str_repeat( 'x', 60 ),
			'b' => str_repeat( 'y', 60 ),
			'c' => str_repeat( 'z', 60 ),
		);

		$chunks = BatchChunker::chunk( $items, 130 );

		$this->assertCount( 2, $chunks );
		$this->assertSame( array( 'a' => $items['a'], 'b' => $items['b'] ), $chunks[0] );
		$this->assertSame( array( 'c' => $items['c'] ), $chunks[1] );
	}

	/**
	 * Порядок строк — контекст материала, поэтому чанки обязаны идти
	 * в исходном порядке, а не как получится после упаковки.
	 */
	public function testPreservesOriginalOrderAcrossChunks(): void {
		$items = array();

		foreach ( range( 1, 5 ) as $index ) {
			$items[ 'k' . $index ] = str_repeat( 'a', 40 );
		}

		$chunks   = BatchChunker::chunk( $items, 100 );
		$flatKeys = array();

		foreach ( $chunks as $chunk ) {
			foreach ( array_keys( $chunk ) as $key ) {
				$flatKeys[] = $key;
			}
		}

		$this->assertSame( array_keys( $items ), $flatKeys );
	}

	/**
	 * Один элемент никогда не режется пополам — даже если он сам больше
	 * бюджета, он просто едет один в своём чанке.
	 */
	public function testOversizedSingleItemGetsItsOwnChunk(): void {
		$items = array(
			'huge'  => str_repeat( 'q', 500 ),
			'small' => 'ok',
		);

		$chunks = BatchChunker::chunk( $items, 100 );

		$this->assertCount( 2, $chunks );
		$this->assertSame( array( 'huge' => $items['huge'] ), $chunks[0] );
		$this->assertSame( array( 'small' => $items['small'] ), $chunks[1] );
	}

	public function testEmptyInputYieldsNoChunks(): void {
		$this->assertSame( array(), BatchChunker::chunk( array() ) );
	}

	public function testUsesMultibyteLengthNotByteLength(): void {
		// «Привет» — 6 символов, но 12 байт в UTF-8: бюджет считается по
		// символам, иначе кириллический материал резался бы вдвое чаще латиницы.
		$items = array(
			'a' => 'Привет',
			'b' => 'Мир',
		);

		$chunks = BatchChunker::chunk( $items, 9 );

		$this->assertCount( 1, $chunks );
	}
}
