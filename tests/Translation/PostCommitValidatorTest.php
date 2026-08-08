<?php
/**
 * Тесты проверки пакета переводов перед сохранением.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Segment;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Translation\PostCommitValidator;

#[CoversClass( PostCommitValidator::class )]
final class PostCommitValidatorTest extends TestCase {

	private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const HASH_FOREIGN = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
	private const HASH_UNKNOWN = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

	/**
	 * @return array<string, array{id:int, kind:string, source_text:string}>
	 */
	private function knownSources(): array {
		return array(
			self::HASH_A       => array( 'id' => 1, 'kind' => Segment::KIND_TEXT, 'source_text' => 'Заголовок' ),
			self::HASH_B       => array( 'id' => 2, 'kind' => Segment::KIND_TEXT, 'source_text' => 'Нажмите [b url="/x"]здесь[/b]' ),
			self::HASH_FOREIGN => array( 'id' => 99, 'kind' => Segment::KIND_TEXT, 'source_text' => 'Чужая запись' ),
		);
	}

	/**
	 * belongsToPost() возвращает true для всех id, кроме 99 — имитирует
	 * строку, реально принадлежащую ДРУГОЙ записи.
	 */
	private function belongsToPost(): callable {
		return static fn( int $id ): bool => 99 !== $id;
	}

	public function testValidPackageProducesRows(): void {
		$result = PostCommitValidator::validate(
			array(
				array( 'uniq_hash' => self::HASH_A, 'translated_text' => 'Title', 'status' => TranslationStatus::APPROVED ),
			),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array( array( 'id' => 1, 'kind' => Segment::KIND_TEXT, 'text' => 'Title', 'status' => TranslationStatus::APPROVED ) ),
			$result['rows']
		);
	}

	public function testEmptyPackageFails(): void {
		$result = PostCommitValidator::validate( array(), $this->knownSources(), $this->belongsToPost() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_EMPTY ), $result['errors'] );
		$this->assertSame( array(), $result['rows'] );
	}

	public function testTooManySegmentsFails(): void {
		$segments = array_fill( 0, 3, array( 'uniq_hash' => self::HASH_A, 'translated_text' => 'x' ) );

		$result = PostCommitValidator::validate( $segments, $this->knownSources(), $this->belongsToPost(), 2 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_TOO_MANY ), $result['errors'] );
	}

	public function testInvalidHashFormatFails(): void {
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => 'not-a-hash', 'translated_text' => 'x' ) ),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_INVALID_ID ), $result['errors'] );
		$this->assertSame( array(), $result['rows'] );
	}

	public function testUnknownHashFails(): void {
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => self::HASH_UNKNOWN, 'translated_text' => 'x' ) ),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_UNKNOWN . ':' . self::HASH_UNKNOWN ), $result['errors'] );
	}

	/**
	 * Ровно защита от подмены: id, реально принадлежащий чужой записи, не
	 * должен пройти, даже если сам хеш валиден и найден в sources.
	 */
	public function testForeignSegmentFails(): void {
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => self::HASH_FOREIGN, 'translated_text' => 'x' ) ),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_FOREIGN . ':' . self::HASH_FOREIGN ), $result['errors'] );
	}

	public function testDuplicateHashInSamePackageFails(): void {
		$result = PostCommitValidator::validate(
			array(
				array( 'uniq_hash' => self::HASH_A, 'translated_text' => 'Title' ),
				array( 'uniq_hash' => self::HASH_A, 'translated_text' => 'Different title' ),
			),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_DUPLICATE . ':' . self::HASH_A ), $result['errors'] );
	}

	/**
	 * Ключевое требование: перевод, ломающий шорткод, проваливает ВЕСЬ
	 * пакет — не только свою строку. rows пуст, ни один сегмент не уходит
	 * на сохранение, даже полностью корректный HASH_A из того же пакета.
	 */
	public function testShortcodeMismatchFailsTheWholePackage(): void {
		$result = PostCommitValidator::validate(
			array(
				array( 'uniq_hash' => self::HASH_A, 'translated_text' => 'Title' ),
				array( 'uniq_hash' => self::HASH_B, 'translated_text' => 'Click here to continue' ),
			),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( PostCommitValidator::ERROR_SHORTCODE . ':' . self::HASH_B ), $result['errors'] );
		$this->assertSame( array(), $result['rows'] );
	}

	public function testShortcodePreservedPasses(): void {
		$result = PostCommitValidator::validate(
			array(
				array( 'uniq_hash' => self::HASH_B, 'translated_text' => 'Click [b url="/x"]here[/b]' ),
			),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * Пустой перевод — «ещё не переведено», а не порча шорткода: сегмент
	 * с шорткодом в исходнике можно оставить пустым без ошибки.
	 */
	public function testEmptyTranslationOfShortcodeSourceIsNotAMismatch(): void {
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => self::HASH_B, 'translated_text' => '' ) ),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( TranslationStatus::MISSING, $result['rows'][0]['status'] );
	}

	/**
	 * Несколько разных ошибок в одном пакете — все перечисляются, а не
	 * только первая: администратору нужно видеть полную картину сразу.
	 */
	public function testCollectsMultipleErrorsAtOnce(): void {
		$result = PostCommitValidator::validate(
			array(
				array( 'uniq_hash' => self::HASH_UNKNOWN, 'translated_text' => 'x' ),
				array( 'uniq_hash' => self::HASH_FOREIGN, 'translated_text' => 'x' ),
			),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertFalse( $result['ok'] );
		$this->assertCount( 2, $result['errors'] );
	}

	public function testMissingStatusDefaultsToApprovedForNonEmptyText(): void {
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => self::HASH_A, 'translated_text' => 'Title' ) ),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertSame( TranslationStatus::APPROVED, $result['rows'][0]['status'] );
	}

	public function testHtmlIsStrippedFromPlainTextSegments(): void {
		$result = PostCommitValidator::validate(
			array( array( 'uniq_hash' => self::HASH_A, 'translated_text' => '<script>alert(1)</script>Title' ) ),
			$this->knownSources(),
			$this->belongsToPost()
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Title', $result['rows'][0]['text'] );
	}
}
