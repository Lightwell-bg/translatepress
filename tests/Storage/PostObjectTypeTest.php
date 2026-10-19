<?php
/**
 * Привязка мест использования строк к записи: тип `post`, а не id рубрики или автора.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rendering\Translator;
use LingoCanvas\Storage\SourceRepository;
use LingoCanvas\Support\Hash;
use LingoCanvas\Tests\Doubles\FakeWpdb;

#[CoversClass( Translator::class )]
#[CoversClass( SourceRepository::class )]
final class PostObjectTypeTest extends TestCase {

	protected function setUp(): void {
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array() );
	}

	// --- Что пишет Translator ---------------------------------------------

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rowsFor(): array {
		$translator = ( new \ReflectionClass( Translator::class ) )->newInstanceWithoutConstructor();
		$method     = new \ReflectionMethod( Translator::class, 'occurrenceRows' );

		/** @var list<array<string, mixed>> $rows */
		$rows = $method->invoke(
			$translator,
			array( 'h1' => (object) array( 'attribute' => null ) ),
			array( 'h1' => 7 ),
			Hash::of( '/en/page/' )
		);

		return $rows;
	}

	public function testSingularPostPageWritesPostTypeAndId(): void {
		lingocanvas_test_wp(
			array(
				'is_singular'    => true,
				'queried_object' => new \WP_Post( array( 'ID' => 11, 'post_type' => 'page' ) ),
			)
		);

		$rows = $this->rowsFor();

		$this->assertSame( 'post', $rows[0]['object_type'] );
		$this->assertSame( 11, $rows[0]['object_id'] );
	}

	public function testArchiveWritesNullObjectIdAndUrlType(): void {
		// На архиве рубрики get_queried_object_id() вернул бы id рубрики (5) — он не должен попасть в object_id.
		lingocanvas_test_wp(
			array(
				'is_singular'    => false,
				'queried_object' => (object) array( 'term_id' => 5 ),
			)
		);

		$rows = $this->rowsFor();

		$this->assertSame( 'url', $rows[0]['object_type'] );
		$this->assertNull( $rows[0]['object_id'] );
	}

	public function testSingularWithoutPostObjectAndEarlyRequestWriteNull(): void {
		lingocanvas_test_wp( array( 'is_singular' => true, 'queried_object' => (object) array( 'ID' => 3 ) ) );
		$this->assertNull( $this->rowsFor()[0]['object_id'] );

		lingocanvas_test_wp(
			array(
				'did_action'     => array( 'wp' => 0 ),
				'is_singular'    => true,
				'queried_object' => new \WP_Post( array( 'ID' => 11 ) ),
			)
		);
		$this->assertNull( $this->rowsFor()[0]['object_id'], 'До хука wp запрос ещё не разобран.' );
	}

	public function testUniqHashDoesNotDependOnObjectType(): void {
		lingocanvas_test_wp( array( 'is_singular' => true, 'queried_object' => new \WP_Post( array( 'ID' => 11 ) ) ) );
		$post = $this->rowsFor()[0]['uniq_hash'];

		lingocanvas_test_wp( array() );
		$url = $this->rowsFor()[0]['uniq_hash'];

		$this->assertSame( $url, $post, 'Идентичность вхождения — адрес страницы: старые строки не дублируются.' );
		$this->assertSame( Hash::ofParts( array( 7, 'url', Hash::of( '/en/page/' ), '' ) ), $post );
	}

	// --- Экран строк ---------------------------------------------------------

	public function testStringsScreenObjectScopeRequiresPostType(): void {
		$db              = new FakeWpdb();
		$GLOBALS['wpdb'] = $db;

		( new SourceRepository() )->paginate( array( 'locale' => 'en', 'scope' => SourceRepository::SCOPE_OBJECT, 'object_id' => 5 ) );

		$this->assertNotSame( array(), $db->queries );

		foreach ( $db->queries as $sql ) {
			$this->assertStringContainsString( "o2.object_type = 'post' AND o2.object_id = 5", $sql );
			$this->assertStringContainsString( "o.source_id = s.id AND o.object_type = 'post'", $sql );
		}

		$db->queries = array();

		( new SourceRepository() )->paginate( array( 'locale' => 'en', 'scope' => SourceRepository::SCOPE_GLOBAL ) );

		$this->assertStringContainsString( "o.object_type = 'post'", $db->queries[0] );
	}
}
