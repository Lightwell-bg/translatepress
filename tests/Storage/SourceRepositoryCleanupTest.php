<?php
/**
 * Чистка исходных строк без перевода: SQL и партии.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Storage\SourceRepository;

/**
 * Подделка $wpdb: запоминает запросы, отдаёт заготовленные ответы.
 */
final class FakeCleanupWpdb {

	public string $prefix = 'wp_';

	/** @var list<string> */
	public array $queries = array();

	/** @var list<list<int>> Пачки id, которые «нашлись» по очереди. */
	public array $batches = array();

	public int $count = 0;

	public int $rows_affected = 0;

	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';

				return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
			},
			$sql
		);
	}

	public function query( string $sql ): int {
		$this->queries[] = $sql;

		if ( str_starts_with( $sql, 'DELETE s FROM wp_mlp_sources' ) ) {
			$this->rows_affected = substr_count( $sql, ',' ) + 1;
		}

		return $this->rows_affected;
	}

	/** @return list<int> */
	public function get_col( string $sql ): array {
		$this->queries[] = $sql;

		return array_shift( $this->batches ) ?? array();
	}

	public function get_var( string $sql ): string {
		$this->queries[] = $sql;

		return (string) $this->count;
	}
}

#[CoversClass( SourceRepository::class )]
final class SourceRepositoryCleanupTest extends TestCase {

	private FakeCleanupWpdb $db;

	protected function setUp(): void {
		$this->db      = new FakeCleanupWpdb();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		wp_mlp_test_options( array() );
	}

	public function testDeletesOnlySourcesWithoutAnyTranslationInBatches(): void {
		$this->db->batches = array( range( 1, 500 ), array( 501, 502 ) );

		$deleted = ( new SourceRepository() )->deleteUntranslated( array( SourceRepository::TYPE_TEXT ) );

		$this->assertSame( 502, $deleted );

		$select = array_values( array_filter( $this->db->queries, static fn( string $q ): bool => str_starts_with( ltrim( $q ), 'SELECT s.id' ) ) );

		$this->assertCount( 2, $select );
		$this->assertStringContainsString( 'NOT EXISTS (SELECT 1 FROM wp_mlp_translations t WHERE t.source_id = s.id)', $select[0] );
		$this->assertStringContainsString( 'LIMIT 500', $select[0] );

		$occurrences = array_values( array_filter( $this->db->queries, static fn( string $q ): bool => str_starts_with( $q, 'DELETE o FROM wp_mlp_occurrences' ) ) );
		$this->assertCount( 2, $occurrences );
		$this->assertStringContainsString( 'NOT EXISTS (SELECT 1 FROM wp_mlp_translations t WHERE t.source_id = o.source_id)', $occurrences[0] );

		$sources = array_values( array_filter( $this->db->queries, static fn( string $q ): bool => str_starts_with( $q, 'DELETE s FROM wp_mlp_sources' ) ) );
		$this->assertCount( 2, $sources );
		$this->assertStringContainsString( 'NOT EXISTS (SELECT 1 FROM wp_mlp_translations t WHERE t.source_id = s.id)', $sources[0] );
	}

	public function testNothingToDeleteStopsAtOnce(): void {
		$this->assertSame( 0, ( new SourceRepository() )->deleteUntranslated( array( SourceRepository::TYPE_TEXT ) ) );
	}

	public function testEmptyKindsTouchNothing(): void {
		$this->assertSame( 0, ( new SourceRepository() )->deleteUntranslated( array() ) );
		$this->assertSame( array(), $this->db->queries );
	}

	public function testBlockHashCacheIsFlushed(): void {
		wp_mlp_test_options( array( SourceRepository::BLOCKS_OPTION => array( 'abc' => true ) ) );

		( new SourceRepository() )->deleteUntranslated( array( SourceRepository::TYPE_BLOCK ) );

		$this->assertArrayNotHasKey( SourceRepository::BLOCKS_OPTION, wp_mlp_test_options() );
	}

	public function testCountUsesNonEmptyTranslationCheck(): void {
		$this->db->count = 42;

		$count = ( new SourceRepository() )->countUntranslated( array( SourceRepository::TYPE_LINK ) );

		$this->assertSame( 42, $count );
		$this->assertStringContainsString( "t.translated_text <> ''", $this->db->queries[0] );
		$this->assertStringContainsString( "'href'", $this->db->queries[0] );
	}
}
