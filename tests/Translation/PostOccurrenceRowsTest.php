<?php
/**
 * Тесты построения occurrence-строк массового перевода записи.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\PostSegment;
use WpMlp\Rendering\Segment;
use WpMlp\Translation\PostOccurrenceRows;

#[CoversClass( PostOccurrenceRows::class )]
final class PostOccurrenceRowsTest extends TestCase {

	private function segment( ?string $attribute = null ): PostSegment {
		$segment = new Segment( new \stdClass(), Segment::KIND_TEXT, $attribute, 'Общая фраза', '', '', 'source-hash', 'uniq-hash-1' );

		return new PostSegment( PostSegment::FIELD_CONTENT, $segment );
	}

	/**
	 * Ровно та ошибка из жалобы: общий абзац встречается в ДВУХ разных
	 * записях (id=1, source_id тот же — источник строк общий на весь сайт).
	 * uniq_hash occurrence-строки обязан различаться по object_id, иначе
	 * `INSERT ... ON DUPLICATE KEY UPDATE` в occurrences (уникальный индекс
	 * — ровно uniq_hash) слил бы обе записи в одну строку, и вторая запись
	 * никогда не смогла бы подтвердить владение сегментом —
	 * PostCommitValidator отклонял бы его как foreign_segment.
	 */
	public function testSamePostSegmentInDifferentPostsGetsDifferentUniqHash(): void {
		$unique = array( 'hash-1' => $this->segment() );
		$ids    = array( 'hash-1' => 501 );

		$rowsForPostA = PostOccurrenceRows::build( $unique, $ids, 501 );
		$rowsForPostB = PostOccurrenceRows::build( $unique, $ids, 777 );

		$this->assertCount( 1, $rowsForPostA );
		$this->assertCount( 1, $rowsForPostB );
		$this->assertNotSame( $rowsForPostA[0]['uniq_hash'], $rowsForPostB[0]['uniq_hash'] );
	}

	public function testRowCarriesTheRequestedPostAsObjectId(): void {
		$rows = PostOccurrenceRows::build( array( 'hash-1' => $this->segment() ), array( 'hash-1' => 501 ), 777 );

		$this->assertSame( 777, $rows[0]['object_id'] );
		$this->assertSame( 501, $rows[0]['source_id'] );
		$this->assertSame( 'post', $rows[0]['object_type'] );
		$this->assertNull( $rows[0]['url_hash'] );
	}

	public function testAttributeNameIsCarriedThrough(): void {
		$rows = PostOccurrenceRows::build( array( 'hash-1' => $this->segment( 'alt' ) ), array( 'hash-1' => 501 ), 777 );

		$this->assertSame( 'alt', $rows[0]['attribute_name'] );
	}

	/**
	 * Тот же uniq_hash строки (source_id) для ДВУХ разных атрибутов ОДНОЙ
	 * и той же записи — тоже должен различаться, иначе `<h1>` и `alt` одной
	 * записи, случайно совпавшие по тексту, слились бы в одну occurrence.
	 */
	public function testSameSourceDifferentAttributeSamePostGetsDifferentUniqHash(): void {
		$unique = array(
			'hash-text' => $this->segment( null ),
			'hash-attr' => $this->segment( 'alt' ),
		);
		$ids = array( 'hash-text' => 501, 'hash-attr' => 501 );

		$rows = PostOccurrenceRows::build( $unique, $ids, 777 );

		$this->assertCount( 2, $rows );
		$this->assertNotSame( $rows[0]['uniq_hash'], $rows[1]['uniq_hash'] );
	}

	/**
	 * Сегмент без соответствующего id (строка ещё не вставлена в sources —
	 * ошибка insertMissing() или гонка) пропускается, а не падает.
	 */
	public function testSegmentWithoutMatchingIdIsSkipped(): void {
		$rows = PostOccurrenceRows::build( array( 'hash-1' => $this->segment() ), array(), 777 );

		$this->assertSame( array(), $rows );
	}

	public function testEmptyUniqueListGivesEmptyRows(): void {
		$this->assertSame( array(), PostOccurrenceRows::build( array(), array(), 777 ) );
	}
}
