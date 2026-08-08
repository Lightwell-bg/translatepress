<?php
/**
 * Тесты слепка переведённых строк записи.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Storage\PostTranslationSnapshot;

#[CoversClass( PostTranslationSnapshot::class )]
final class PostTranslationSnapshotTest extends TestCase {

	protected function tearDown(): void {
		wp_mlp_test_postmeta( array() );
	}

	public function testHashesForUnknownPostIsEmpty(): void {
		$snapshot = new PostTranslationSnapshot();

		$this->assertSame( array(), $snapshot->hashesFor( 42, 'en' ) );
	}

	public function testSaveThenHashesForRoundTrips(): void {
		$snapshot = new PostTranslationSnapshot();
		$hashes   = array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) );

		$snapshot->save( 42, 'en', $hashes );

		$this->assertSame( $hashes, $snapshot->hashesFor( 42, 'en' ) );
	}

	/**
	 * Слепки на разных языках и разных записях не должны пересекаться.
	 */
	public function testSnapshotsAreScopedByPostAndLocale(): void {
		$snapshot = new PostTranslationSnapshot();
		$hashEn   = str_repeat( 'a', 64 );
		$hashBg   = str_repeat( 'b', 64 );

		$snapshot->save( 42, 'en', array( $hashEn ) );
		$snapshot->save( 42, 'bg', array( $hashBg ) );
		$snapshot->save( 7, 'en', array( $hashBg ) );

		$this->assertSame( array( $hashEn ), $snapshot->hashesFor( 42, 'en' ) );
		$this->assertSame( array( $hashBg ), $snapshot->hashesFor( 42, 'bg' ) );
		$this->assertSame( array( $hashBg ), $snapshot->hashesFor( 7, 'en' ) );
	}

	/**
	 * Строка, отсутствующая в прошлом слепке, — новая или изменившаяся:
	 * ровно то, что должно попасть под «требует обновления».
	 */
	public function testChangedFindsHashesMissingFromThePreviousSnapshot(): void {
		$previous = array( 'aaa', 'bbb' );
		$current  = array( 'aaa', 'ccc' );

		$changed = PostTranslationSnapshot::changed( $current, $previous );

		$this->assertSame( array( 'ccc' => true ), $changed );
	}

	/**
	 * Ничего не поменялось — значит требующих обновления строк нет.
	 */
	public function testChangedIsEmptyWhenNothingIsNew(): void {
		$this->assertSame( array(), PostTranslationSnapshot::changed( array( 'aaa' ), array( 'aaa', 'bbb' ) ) );
	}

	public function testChangedOnFirstEverTranslationTreatsEverythingAsChanged(): void {
		$current = array( 'aaa', 'bbb' );

		$this->assertSame(
			array( 'aaa' => true, 'bbb' => true ),
			PostTranslationSnapshot::changed( $current, array() )
		);
	}
}
