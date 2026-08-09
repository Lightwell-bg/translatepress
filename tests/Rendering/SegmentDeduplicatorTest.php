<?php
/**
 * Тесты выбора сегмента-представителя при совпадении uniq_hash.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Segment;
use WpMlp\Rendering\SegmentDeduplicator;

#[CoversClass( SegmentDeduplicator::class )]
final class SegmentDeduplicatorTest extends TestCase {

	/**
	 * @param string      $kind      Segment::KIND_*.
	 * @param string|null $attribute Имя атрибута.
	 */
	private function segment( string $kind, ?string $attribute, string $uniqHash ): Segment {
		return new Segment( new \stdClass(), $kind, $attribute, 'Заголовок статьи', '', '', 'source-hash', $uniqHash );
	}

	/**
	 * Порядок в документе по-прежнему решает, когда ни один из сегментов
	 * с общим хешем не является SEO-полем, — поведение не поменялось для
	 * подавляющего большинства строк сайта (обычный текст, alt, title).
	 */
	public function testFirstSegmentWinsWhenNoneAreSeoFlavored(): void {
		$first  = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );
		$second = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );

		$unique = SegmentDeduplicator::deduplicate( array( $first, $second ) );

		$this->assertSame( $first, $unique['hash-1'] );
	}

	/**
	 * Пункт 5 жалобы (аудит 64027): заголовок записи, совпадающий по тексту
	 * с og:title, встречается в разметке ПОЗЖЕ H1 — `<title>`/`og:title` в
	 * `<head>` идут раньше H1 в `<body>`, а Extractor обходит документ по
	 * порядку. Несмотря на это, представителем должен стать og:title
	 * (kind=attribute, attribute=content), а не H1 (kind=text) — иначе
	 * `SourceRepository::TYPE_SEO` перестаёт находить эту строку в
	 * «Переводе строк», хотя она и есть SEO-поле.
	 */
	public function testSeoAttributeSegmentWinsOverLaterPlainText(): void {
		$ogTitle = $this->segment( Segment::KIND_ATTRIBUTE, 'content', 'hash-1' );
		$h1      = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );

		$unique = SegmentDeduplicator::deduplicate( array( $ogTitle, $h1 ) );

		$this->assertSame( $ogTitle, $unique['hash-1'] );
	}

	/**
	 * И наоборот: если текст (H1) идёт в документе РАНЬШЕ og:title —
	 * реалистичный порядок, раз `<title>`/og:title обычно в `<head>` раньше
	 * H1 в `<body>` не всегда верно для нестандартных тем — SEO-сегмент всё
	 * равно должен победить, а не потеряться из-за «первый выигрывает».
	 */
	public function testSeoAttributeSegmentWinsEvenWhenFoundAfterPlainText(): void {
		$h1      = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );
		$ogTitle = $this->segment( Segment::KIND_ATTRIBUTE, 'content', 'hash-1' );

		$unique = SegmentDeduplicator::deduplicate( array( $h1, $ogTitle ) );

		$this->assertSame( $ogTitle, $unique['hash-1'] );
	}

	/**
	 * JSON-LD headline (kind=seo) — тоже SEO-сегмент, побеждает обычный текст
	 * так же, как meta content.
	 */
	public function testJsonLdSegmentWinsOverPlainText(): void {
		$headline = $this->segment( Segment::KIND_SEO, null, 'hash-1' );
		$title    = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );

		$unique = SegmentDeduplicator::deduplicate( array( $title, $headline ) );

		$this->assertSame( $headline, $unique['hash-1'] );
	}

	/**
	 * Среди нескольких SEO-сегментов (og:title и JSON-LD headline, оба
	 * совпадают по тексту) побеждает первый найденный — как и раньше,
	 * приоритет решает только вопрос «SEO против обычного текста», а не
	 * порядок внутри самих SEO-полей.
	 */
	public function testFirstSeoSegmentWinsAmongSeveralSeoSegments(): void {
		$ogTitle  = $this->segment( Segment::KIND_ATTRIBUTE, 'content', 'hash-1' );
		$headline = $this->segment( Segment::KIND_SEO, null, 'hash-1' );

		$unique = SegmentDeduplicator::deduplicate( array( $ogTitle, $headline ) );

		$this->assertSame( $ogTitle, $unique['hash-1'] );
	}

	/**
	 * Обычный атрибут (`alt`, `title`, `placeholder`) не считается
	 * SEO-сегментом — приоритет применяется только к `content` (meta) и
	 * полям JSON-LD, а не к любому kind=attribute, иначе никак не связанный
	 * с заголовком `alt`, случайно совпавший по тексту, стал бы неожиданно
	 * "выигрывать" у обычного текста.
	 */
	public function testOrdinaryAttributeIsNotTreatedAsSeoFlavored(): void {
		$text = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );
		$alt  = $this->segment( Segment::KIND_ATTRIBUTE, 'alt', 'hash-1' );

		$unique = SegmentDeduplicator::deduplicate( array( $text, $alt ) );

		$this->assertSame( $text, $unique['hash-1'] );
	}

	/**
	 * Разные хеши — разные записи, дедупликация друг другу не мешает.
	 */
	public function testDifferentHashesStayIndependent(): void {
		$a = $this->segment( Segment::KIND_TEXT, null, 'hash-1' );
		$b = $this->segment( Segment::KIND_SEO, null, 'hash-2' );

		$unique = SegmentDeduplicator::deduplicate( array( $a, $b ) );

		$this->assertCount( 2, $unique );
		$this->assertSame( $a, $unique['hash-1'] );
		$this->assertSame( $b, $unique['hash-2'] );
	}

	public function testEmptyListReturnsEmptyMap(): void {
		$this->assertSame( array(), SegmentDeduplicator::deduplicate( array() ) );
	}
}
