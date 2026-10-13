<?php
/**
 * Разовая чистка мусорных строк словаря.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Segment;
use WpMlp\Storage\StaleStringsCleanup;
use WpMlp\Support\Hash;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Подделка $wpdb: запоминает запросы и отдаёт заготовленные ответы.
 *
 * Сами SQL-условия («нет перевода», «все места использования — href»)
 * проверяются по тексту запроса; ответы выборок — заготовленные списки.
 */
final class FakeStaleWpdb {

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var list<string> */
	public array $queries = array();

	/** @var list<array<string, mixed>> Что вернёт выборка адресов (один раз). */
	public array $hrefRows = array();

	/** @var list<array<string, mixed>> Что вернёт выборка title (один раз). */
	public array $titleRows = array();

	/** @var list<array<string, mixed>> Что вернёт поиск текстовых строк по хешу. */
	public array $lookupRows = array();

	/** @var string Если запрос содержит эту подстроку, он «падает». */
	public string $failOn = '';

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

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

	/** @return int|false */
	public function query( string $sql ) {
		$this->queries[] = $sql;

		if ( $this->shouldFail( $sql ) ) {
			return false;
		}

		if ( str_starts_with( $sql, 'DELETE s FROM' ) ) {
			// Запятые есть только в списке IN (...): по ним и считаем удалённые.
			return substr_count( $sql, ',' ) + 1;
		}

		return 1;
	}

	/** @return list<array<string, mixed>>|false */
	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( $this->shouldFail( $sql ) ) {
			return false;
		}

		if ( str_contains( $sql, 'SELECT s.id, s.source_text FROM' ) ) {
			$rows           = $this->hrefRows;
			$this->hrefRows = array();

			return $rows;
		}

		if ( str_contains( $sql, 'SELECT s.id, s.source_locale' ) ) {
			$rows            = $this->titleRows;
			$this->titleRows = array();

			return $rows;
		}

		return $this->lookupRows;
	}

	private function shouldFail( string $sql ): bool {
		if ( '' !== $this->failOn && str_contains( $sql, $this->failOn ) ) {
			$this->last_error = 'boom';

			return true;
		}

		return false;
	}

	/** @return list<string> */
	public function deletes( string $prefix ): array {
		return array_values(
			array_filter( $this->queries, static fn( string $q ): bool => str_starts_with( ltrim( $q ), $prefix ) )
		);
	}
}

#[CoversClass( StaleStringsCleanup::class )]
final class StaleStringsCleanupTest extends TestCase {

	private FakeStaleWpdb $db;

	protected function setUp(): void {
		$this->db        = new FakeStaleWpdb();
		$GLOBALS['wpdb'] = $this->db;

		wp_mlp_test_options( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		wp_mlp_test_options( array() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function href( int $id, string $text ): array {
		return array( 'id' => $id, 'source_text' => $text );
	}

	private function option( string $name ) {
		return wp_mlp_test_options()[ $name ] ?? null;
	}

	public function testHrefSelectionIsLimitedToEditorArgsAndUntranslatedRows(): void {
		$this->db->hrefRows = array(
			$this->href( 7, '/blog/page/2/?mlp_editor=1&mlp_nonce=abc' ),
			$this->href( 8, '/x/?mlp_channel=zz' ),
		);

		$result = ( new StaleStringsCleanup() )->run();

		$this->assertSame( 2, $result['deleted'] );

		$select = $this->db->queries[0];

		$this->assertStringContainsString( "s.kind = 'attribute'", $select );
		$this->assertStringContainsString( "mlp\\_editor=", $select );
		$this->assertStringContainsString( "mlp\\_nonce=", $select );
		$this->assertStringContainsString( "mlp\\_channel=", $select );
		// Только строки, у которых места использования целиком href.
		$this->assertStringContainsString( "oa.attribute_name = 'href'", $select );
		$this->assertStringContainsString( "ob.attribute_name <> 'href'", $select );
		// Любой непустой перевод защищает строку.
		$this->assertStringContainsString( "tr.translated_text <> ''", $select );
	}

	public function testFragmentOnlyHrefIsKept(): void {
		$this->db->hrefRows = array(
			$this->href( 7, '/docs/#mlp_editor=1' ),
			$this->href( 8, '/blog/?mlp_editor=1' ),
		);

		$result = ( new StaleStringsCleanup() )->run();

		$this->assertSame( 1, $result['deleted'] );

		$sources = $this->db->deletes( 'DELETE s FROM wp_mlp_sources' );

		$this->assertCount( 1, $sources );
		$this->assertStringContainsString( 'IN (8)', $sources[0] );
		// Курсор уходит за обе строки, чтобы сохранённая не выбиралась снова.
		$this->assertSame( 'href:8', $this->option( StaleStringsCleanup::CURSOR_OPTION ) );
	}

	public function testDeleteRepeatsTheNoTranslationGuard(): void {
		$this->db->hrefRows = array( $this->href( 7, '/a/?mlp_editor=1' ) );

		( new StaleStringsCleanup() )->run();

		$occurrences = $this->db->deletes( 'DELETE o FROM wp_mlp_occurrences' );
		$sources     = $this->db->deletes( 'DELETE s FROM wp_mlp_sources' );

		$this->assertCount( 1, $occurrences );
		$this->assertCount( 1, $sources );
		$this->assertStringContainsString( 'NOT EXISTS (SELECT 1 FROM wp_mlp_translations t WHERE t.source_id = o.source_id)', $occurrences[0] );
		$this->assertStringContainsString( 'NOT EXISTS (SELECT 1 FROM wp_mlp_translations t WHERE t.source_id = s.id)', $sources[0] );

		// Из переводов уходят только пустые записи, а не сам перевод.
		$translations = $this->db->deletes( 'DELETE FROM wp_mlp_translations' );

		$this->assertCount( 1, $translations );
		$this->assertStringContainsString( "translated_text IS NULL OR translated_text = ''", $translations[0] );
	}

	public function testNumberedTitleIsDeletedOnlyOnExactTextSourceMatchInSameLocale(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::CURSOR_OPTION => 'title:0' ) );

		$this->db->titleRows = array(
			array( 'id' => 10, 'source_locale' => 'ru_RU', 'source_text' => 'MCP простыми словами 10' ),
			array( 'id' => 11, 'source_locale' => 'ru_RU', 'source_text' => 'Windows 11' ),
			array( 'id' => 12, 'source_locale' => 'en_US', 'source_text' => 'MCP простыми словами 3' ),
		);
		// Нашлись: точное совпадение (ru_RU), «почти» совпадение (регистр) и другой язык (en_US не тот).
		$this->db->lookupRows = array(
			array( 'source_locale' => 'ru_RU', 'source_text' => 'MCP простыми словами' ),
			array( 'source_locale' => 'ru_RU', 'source_text' => 'Windows' . ' ' ),
			array( 'source_locale' => 'de_DE', 'source_text' => 'MCP простыми словами' ),
		);

		$result = ( new StaleStringsCleanup() )->run();

		$this->assertSame( 1, $result['deleted'] );

		$sources = $this->db->deletes( 'DELETE s FROM wp_mlp_sources' );

		$this->assertCount( 1, $sources );
		$this->assertStringContainsString( 'IN (10)', $sources[0] );

		$select = $this->db->deletes( 'SELECT s.id, s.source_locale' );

		// Места использования необязательны: старые строки могли остаться без них.
		$this->assertStringNotContainsString( "oa.attribute_name = 'title'", $select[0] );
		$this->assertStringContainsString( "ob.attribute_name <> 'title'", $select[0] );
		$this->assertStringContainsString( "tr.translated_text <> ''", $select[0] );

		// Поиск: один запрос на пачку, по уникальному uniq_hash текстового сегмента.
		$lookups = $this->db->deletes( 'SELECT s.source_locale, s.source_text' );

		$this->assertCount( 1, $lookups );
		$this->assertStringContainsString( 's.uniq_hash IN (', $lookups[0] );
		$this->assertStringNotContainsString( 's.kind', $lookups[0] );
		$this->assertStringContainsString( "UNHEX('" . $this->textUniqHash( 'ru_RU', 'MCP простыми словами' ) . "')", $lookups[0] );
		$this->assertStringContainsString( "UNHEX('" . $this->textUniqHash( 'en_US', 'MCP простыми словами' ) . "')", $lookups[0] );
	}

	private function textUniqHash( string $locale, string $text, string $kind = Segment::KIND_TEXT ): string {
		return Hash::ofParts( array( $locale, $kind, Hash::of( $text ), Hash::of( '' ), '', '', '' ) );
	}

	public function testTitleIsDeletedWhenMatchingRowIsOgTitleStoredAsAttribute(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::CURSOR_OPTION => 'title:0' ) );

		$this->db->titleRows  = array(
			array( 'id' => 20, 'source_locale' => 'ru_RU', 'source_text' => 'ego lite: браузер для AI-агентов и веб-автоматизации 6' ),
		);
		// Строка из og:title: колонка kind = attribute, но uniq_hash как у текста.
		$this->db->lookupRows = array(
			array( 'id' => 5, 'source_locale' => 'ru_RU', 'source_text' => 'ego lite: браузер для AI-агентов и веб-автоматизации' ),
		);

		$result = ( new StaleStringsCleanup() )->run();

		$this->assertSame( 1, $result['deleted'] );
		$this->assertStringContainsString( 'IN (20)', $this->db->deletes( 'DELETE s FROM wp_mlp_sources' )[0] );
	}

	public function testTitleKeptWhenOnlyAttributeHashedRowWithSameTextExists(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::CURSOR_OPTION => 'title:0' ) );

		$this->db->titleRows = array(
			array( 'id' => 21, 'source_locale' => 'ru_RU', 'source_text' => 'Фото 10' ),
		);
		// aria-label «Фото» с другим uniq_hash (KIND_ATTRIBUTE) база не вернёт.
		$this->db->lookupRows = array();

		$result = ( new StaleStringsCleanup() )->run();

		$this->assertSame( 0, $result['deleted'] );

		$lookups = $this->db->deletes( 'SELECT s.source_locale, s.source_text' );

		$this->assertStringContainsString( $this->textUniqHash( 'ru_RU', 'Фото' ), $lookups[0] );
		$this->assertStringNotContainsString( $this->textUniqHash( 'ru_RU', 'Фото', Segment::KIND_ATTRIBUTE ), $lookups[0] );
	}

	public function testCandidateRowItselfDoesNotCountAsTheMatch(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::CURSOR_OPTION => 'title:0' ) );

		$this->db->titleRows  = array(
			array( 'id' => 22, 'source_locale' => 'ru_RU', 'source_text' => 'Фото 10' ),
		);
		$this->db->lookupRows = array(
			array( 'id' => 22, 'source_locale' => 'ru_RU', 'source_text' => 'Фото' ),
		);

		$this->assertSame( 0, ( new StaleStringsCleanup() )->run()['deleted'] );
	}

	public function testSiteThatFinishedV1RerunsUnderV2(): void {
		wp_mlp_test_options(
			array(
				StaleStringsCleanup::OPTION => 1,
				'mlp_cleanup_cursor'        => 'title:999',
			)
		);

		$this->db->hrefRows = array( $this->href( 7, '/a/?mlp_editor=1' ) );

		StaleStringsCleanup::maybeRun();

		// Работа выполнена с нуля: старый курсор v1 проигнорирован.
		$this->assertNotSame( array(), $this->db->queries );
		$this->assertSame( 'href:7', $this->option( StaleStringsCleanup::CURSOR_OPTION ) );

		StaleStringsCleanup::maybeRun();
		StaleStringsCleanup::maybeRun();

		$this->assertSame( 2, $this->option( StaleStringsCleanup::OPTION ) );
		$this->assertNull( $this->option( 'mlp_cleanup_cursor' ) );
	}

	public function testNumberedTitleKeptWhenNoTextSourceFound(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::CURSOR_OPTION => 'title:0' ) );

		$this->db->titleRows = array(
			array( 'id' => 10, 'source_locale' => 'ru_RU', 'source_text' => 'Фото 10' ),
		);
		// Строки атрибута/gettext SQL отсечёт по kind; в ответе пусто.
		$this->db->lookupRows = array();

		$result = ( new StaleStringsCleanup() )->run();

		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( array(), $this->db->deletes( 'DELETE' ) );
	}

	public function testNothingFoundMovesFromHrefToTitleThenFinishes(): void {
		$cleanup = new StaleStringsCleanup();

		$first = $cleanup->run();

		$this->assertFalse( $first['done'] );
		$this->assertSame( 'title:0', $this->option( StaleStringsCleanup::CURSOR_OPTION ) );

		$second = $cleanup->run();

		$this->assertTrue( $second['done'] );
		$this->assertFalse( $second['error'] );
		$this->assertSame( array(), $this->db->deletes( 'DELETE' ) );
	}

	public function testRunsOnlyOneBatchPerCallAndWritesVersionOnEmptyRun(): void {
		$this->db->hrefRows = array( $this->href( 7, '/a/?mlp_editor=1' ) );

		StaleStringsCleanup::maybeRun();

		// Пачка обработана, но кандидаты могли остаться: версии нет.
		$this->assertNull( $this->option( StaleStringsCleanup::OPTION ) );
		$this->assertSame( 'href:7', $this->option( StaleStringsCleanup::CURSOR_OPTION ) );
		$this->assertNull( $this->option( StaleStringsCleanup::LOCK_OPTION ) );

		// Адресов больше нет — переход к заголовкам, всё ещё без версии.
		StaleStringsCleanup::maybeRun();
		$this->assertNull( $this->option( StaleStringsCleanup::OPTION ) );

		// Пустая выборка заголовков — конец.
		StaleStringsCleanup::maybeRun();
		$this->assertSame( StaleStringsCleanup::VERSION, $this->option( StaleStringsCleanup::OPTION ) );
		$this->assertNull( $this->option( StaleStringsCleanup::CURSOR_OPTION ) );

		// Дальше вызовы ничего не делают.
		$count = count( $this->db->queries );
		StaleStringsCleanup::maybeRun();
		$this->assertCount( $count, $this->db->queries );
	}

	public function testSqlErrorDoesNotWriteVersion(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::CURSOR_OPTION => 'title:0' ) );

		$this->db->failOn = 'SELECT s.id, s.source_locale';

		StaleStringsCleanup::maybeRun();

		$this->assertNull( $this->option( StaleStringsCleanup::OPTION ) );
		$this->assertNull( $this->option( StaleStringsCleanup::LOCK_OPTION ) );
	}

	public function testDeleteErrorStopsAndDoesNotWriteVersion(): void {
		$this->db->hrefRows = array( $this->href( 7, '/a/?mlp_editor=1' ) );
		$this->db->failOn   = 'DELETE o FROM';

		StaleStringsCleanup::maybeRun();

		$this->assertNull( $this->option( StaleStringsCleanup::OPTION ) );
		// Курсор не сдвинут: пачка повторится.
		$this->assertNull( $this->option( StaleStringsCleanup::CURSOR_OPTION ) );
		$this->assertCount( 0, $this->db->deletes( 'DELETE s FROM' ) );
	}

	public function testHeldLockSkipsWork(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::LOCK_OPTION => time() ) );

		StaleStringsCleanup::maybeRun();

		$this->assertSame( array(), $this->db->queries );
		// Чужой замок не снимается.
		$this->assertNotNull( $this->option( StaleStringsCleanup::LOCK_OPTION ) );
	}

	public function testStaleLockIsTakenOver(): void {
		wp_mlp_test_options( array( StaleStringsCleanup::LOCK_OPTION => time() - 600 ) );

		$this->db->hrefRows = array( $this->href( 7, '/a/?mlp_editor=1' ) );

		StaleStringsCleanup::maybeRun();

		$this->assertNotSame( array(), $this->db->queries );
		$this->assertNull( $this->option( StaleStringsCleanup::LOCK_OPTION ) );
	}
}
