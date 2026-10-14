<?php
/**
 * Привязка мест использования строк к записи: тип `post`, а не id рубрики или автора.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Translator;
use WpMlp\Storage\OccurrenceObjectTypeMigration;
use WpMlp\Storage\SourceRepository;
use WpMlp\Support\Hash;
use WpMlp\Tests\Doubles\FakeWpdb;

/**
 * Подделка $wpdb для миграции: ответы get_var() берутся из очереди.
 */
final class FakeMigrationWpdb {

	public string $prefix = 'wp_';

	public string $posts = 'wp_posts';

	public string $last_error = '';

	/** @var list<string> */
	public array $queries = array();

	/** @var list<mixed> Очередь ответов get_var() (верхняя граница пачки). */
	public array $vars = array();

	/** @var string Если запрос содержит эту подстроку, он «падает». */
	public string $failOn = '';

	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdi]/',
			static function ( array $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';

				if ( '%i' === $m[0] ) {
					return (string) $value; // Идентификатор без кавычек, как в FakeWpdb.
				}

				return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
			},
			$sql
		);
	}

	/** @return mixed */
	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( $this->shouldFail( $sql ) ) {
			return false;
		}

		return array_shift( $this->vars );
	}

	/** @return int|false */
	public function query( string $sql ) {
		$this->queries[] = $sql;

		return $this->shouldFail( $sql ) ? false : 1;
	}

	private function shouldFail( string $sql ): bool {
		if ( '' !== $this->failOn && str_contains( $sql, $this->failOn ) ) {
			$this->last_error = 'boom';

			return true;
		}

		return false;
	}
}

#[CoversClass( OccurrenceObjectTypeMigration::class )]
#[CoversClass( Translator::class )]
#[CoversClass( SourceRepository::class )]
final class PostObjectTypeTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array() );
	}

	private function option( string $name ): mixed {
		return wp_mlp_test_options()[ $name ] ?? null;
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
		wp_mlp_test_wp(
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
		wp_mlp_test_wp(
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
		wp_mlp_test_wp( array( 'is_singular' => true, 'queried_object' => (object) array( 'ID' => 3 ) ) );
		$this->assertNull( $this->rowsFor()[0]['object_id'] );

		wp_mlp_test_wp(
			array(
				'did_action'     => array( 'wp' => 0 ),
				'is_singular'    => true,
				'queried_object' => new \WP_Post( array( 'ID' => 11 ) ),
			)
		);
		$this->assertNull( $this->rowsFor()[0]['object_id'], 'До хука wp запрос ещё не разобран.' );
	}

	public function testUniqHashDoesNotDependOnObjectType(): void {
		wp_mlp_test_wp( array( 'is_singular' => true, 'queried_object' => new \WP_Post( array( 'ID' => 11 ) ) ) );
		$post = $this->rowsFor()[0]['uniq_hash'];

		wp_mlp_test_wp( array() );
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

	// --- Миграция старых данных ----------------------------------------------

	private function migrationDb(): FakeMigrationWpdb {
		$db              = new FakeMigrationWpdb();
		$GLOBALS['wpdb'] = $db;

		return $db;
	}

	public function testBatchRetagsPublishedPostsAndNullsEverythingElse(): void {
		$db       = $this->migrationDb();
		$db->vars = array( '40' );

		$result = ( new OccurrenceObjectTypeMigration() )->run();

		$this->assertSame( array( 'done' => false, 'error' => false ), $result );
		$this->assertCount( 3, $db->queries );

		$this->assertStringContainsString( 'SELECT MAX(b.id)', $db->queries[0] );
		$this->assertStringContainsString( 'WHERE id > 0', $db->queries[0] );

		$retag = $db->queries[1];

		$this->assertStringContainsString( "SET o.object_type = 'post'", $retag );
		$this->assertStringContainsString( 'o.id > 0 AND o.id <= 40', $retag );
		$this->assertStringContainsString( "o.object_type = 'url' AND o.object_id IS NOT NULL", $retag );
		$this->assertStringContainsString( "p.post_status = 'publish'", $retag );
		$this->assertStringContainsString( "p.post_type IN ('post','page')", $retag );
		$this->assertStringNotContainsString( 'attachment', $retag );

		$clear = $db->queries[2];

		$this->assertStringContainsString( 'SET object_id = NULL', $clear );
		$this->assertStringContainsString( "object_type = 'url' AND object_id IS NOT NULL", $clear );
		$this->assertStringContainsString( 'id > 0 AND id <= 40', $clear );

		$this->assertSame( 40, $this->option( OccurrenceObjectTypeMigration::CURSOR_OPTION ) );
	}

	public function testMigrationFinishesIsIdempotentAndReleasesLock(): void {
		$db       = $this->migrationDb();
		$db->vars = array( '40', null );

		OccurrenceObjectTypeMigration::maybeRun();

		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::OPTION ) );
		$this->assertSame( 40, $this->option( OccurrenceObjectTypeMigration::CURSOR_OPTION ) );
		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::LOCK_OPTION ) );

		OccurrenceObjectTypeMigration::maybeRun();

		$this->assertSame( OccurrenceObjectTypeMigration::VERSION, $this->option( OccurrenceObjectTypeMigration::OPTION ) );
		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::CURSOR_OPTION ) );

		$count = count( $db->queries );

		OccurrenceObjectTypeMigration::maybeRun();

		$this->assertCount( $count, $db->queries, 'Завершённая миграция больше не трогает БД.' );
	}

	public function testRerunOverMigratedDataChangesNothingMore(): void {
		// Курсор уже у конца таблицы: следующей пачки нет — только признак «готово», без UPDATE.
		$db       = $this->migrationDb();
		$db->vars = array( null );
		wp_mlp_test_options( array( OccurrenceObjectTypeMigration::CURSOR_OPTION => 40 ) );

		$result = ( new OccurrenceObjectTypeMigration() )->run();

		$this->assertSame( array( 'done' => true, 'error' => false ), $result );
		$this->assertCount( 1, $db->queries );
		$this->assertStringContainsString( 'WHERE id > 40', $db->queries[0] );
	}

	public function testDbErrorKeepsCursorAndVersionAndReleasesLock(): void {
		$db         = $this->migrationDb();
		$db->vars   = array( '40' );
		$db->failOn = 'SET object_id = NULL';

		OccurrenceObjectTypeMigration::maybeRun();

		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::OPTION ) );
		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::CURSOR_OPTION ), 'Курсор не сдвигается после ошибки.' );
		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::LOCK_OPTION ) );
	}

	public function testFreshLockStopsParallelRunButStaleOneIsTaken(): void {
		$db = $this->migrationDb();

		wp_mlp_test_options( array( OccurrenceObjectTypeMigration::LOCK_OPTION => time() ) );
		OccurrenceObjectTypeMigration::maybeRun();

		$this->assertSame( array(), $db->queries );
		$this->assertNotNull( $this->option( OccurrenceObjectTypeMigration::LOCK_OPTION ) );

		wp_mlp_test_options( array( OccurrenceObjectTypeMigration::LOCK_OPTION => time() - 600 ) );
		OccurrenceObjectTypeMigration::maybeRun();

		$this->assertNotSame( array(), $db->queries );
		$this->assertNull( $this->option( OccurrenceObjectTypeMigration::LOCK_OPTION ) );
	}
}
