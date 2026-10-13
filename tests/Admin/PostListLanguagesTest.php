<?php
/**
 * Тесты колонки «Языки» и фильтра «Перевод» в списках записей.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\EditorLink;
use WpMlp\Admin\PostListLanguages;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Подделка $wpdb: считает запросы, на выборку отдаёт заготовленные строки.
 */
final class FakePostListWpdb {

	public string $prefix = 'wp_';

	public string $posts = 'wp_posts';

	/** @var list<string> */
	public array $queries = array();

	/** @var list<array<string, mixed>> */
	public array $rows = array();

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

	/** @return list<array<string, mixed>> */
	public function get_results( string $sql, $output = null ): array {
		unset( $output );
		$this->queries[] = $sql;

		return $this->rows;
	}
}

/**
 * Главный запрос списка: нужен только is_main_query() и get().
 */
final class FakeMainQuery extends \WP_Query {

	public function is_main_query(): bool {
		return true;
	}

	/** @param string $key Параметр. */
	public function get( $key ) {
		return 'post_type' === $key ? 'page' : '';
	}
}

#[CoversClass( PostListLanguages::class )]
final class PostListLanguagesTest extends TestCase {

	private FakePostListWpdb $db;

	protected function setUp(): void {
		$this->db = new FakePostListWpdb();
		$GLOBALS['wpdb'] = $this->db;
		unset( $GLOBALS['wp_query'], $GLOBALS['pagenow'] );
		$_GET = array();
		wp_mlp_test_request( array( 'is_admin' => true ) );
		$this->configure( true );
		wp_mlp_test_wp( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_query'], $GLOBALS['pagenow'] );
		$_GET = array();
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array() );
		wp_mlp_test_request( array() );
	}

	private function configure( bool $withSecondary ): void {
		$languages = array(
			'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
		);

		if ( $withSecondary ) {
			$languages['en'] = array( 'locale' => 'en', 'slug' => 'en', 'label' => '<b>English</b>', 'status' => 'published' );
			$languages['de'] = array( 'locale' => 'de', 'slug' => 'de', 'label' => 'Deutsch', 'status' => 'draft' );
		}

		wp_mlp_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => $languages,
				),
			)
		);
	}

	private function service(): PostListLanguages {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );

		return new PostListLanguages( $settings, new EditorLink( $settings, $urls ) );
	}

	/**
	 * @param array<int, string> $statuses id => статус записи.
	 */
	private function posts( array $statuses ): void {
		$posts = array();

		foreach ( $statuses as $id => $status ) {
			$posts[ $id ] = new \WP_Post(
				array(
					'ID'          => $id,
					'post_status' => $status,
					'post_title'  => 'T' . $id,
					'post_name'   => 'p' . $id,
					'post_type'   => 'page',
				)
			);
		}

		wp_mlp_test_wp( array( 'posts' => $posts ) );
		$GLOBALS['wp_query'] = (object) array( 'posts' => array_values( $posts ) );
	}

	private function cell( PostListLanguages $service, int $id ): string {
		ob_start();
		$service->renderColumn( PostListLanguages::COLUMN, $id );

		return (string) ob_get_clean();
	}

	public function testColumnAddedBeforeDateOnlyWithSecondaryLanguage(): void {
		$columns = $this->service()->addColumn( array( 'title' => 'T', 'date' => 'D' ), 'page' );

		$this->assertSame( array( 'title', PostListLanguages::COLUMN, 'date' ), array_keys( $columns ) );

		$this->configure( false );
		$this->assertSame( array( 'title' => 'T' ), $this->service()->addColumn( array( 'title' => 'T' ), 'page' ) );
	}

	public function testColumnNotAddedForAttachmentOrWithoutCapability(): void {
		$this->assertArrayNotHasKey( PostListLanguages::COLUMN, $this->service()->addColumn( array( 'title' => 'T' ), 'attachment' ) );

		wp_mlp_test_wp( array( 'caps' => array( 'manage_options' => false ) ) );
		$this->assertArrayNotHasKey( PostListLanguages::COLUMN, $this->service()->addColumn( array( 'title' => 'T' ), 'page' ) );
	}

	public function testClassify(): void {
		$this->assertSame( array( 'never', 0 ), PostListLanguages::classify( 0, 0 ) );
		$this->assertSame( array( 'none', 0 ), PostListLanguages::classify( 5, 0 ) );
		$this->assertSame( array( 'partial', 60 ), PostListLanguages::classify( 5, 3 ) );
		$this->assertSame( array( 'partial', 99 ), PostListLanguages::classify( 1000, 999 ) );
		$this->assertSame( array( 'full', 100 ), PostListLanguages::classify( 5, 5 ) );
	}

	public function testStatusesRenderedPerLanguage(): void {
		$this->posts( array( 1 => 'publish', 2 => 'publish', 3 => 'publish', 4 => 'publish', 5 => 'draft' ) );
		// Колонки: done_0 — en, done_1 — de.
		$this->db->rows = array(
			array( 'object_id' => 1, 'total' => 5, 'done_0' => 5, 'done_1' => 3 ),
			array( 'object_id' => 2, 'total' => 4, 'done_0' => 0, 'done_1' => 0 ),
		);

		$service = $this->service();

		$first = $this->cell( $service, 1 );
		$this->assertStringContainsString( '✅', $first );
		$this->assertStringContainsString( '60%', $first );
		$this->assertStringContainsString( '(черн.)', $first );

		$this->assertStringContainsString( 'нет перевода', $this->cell( $service, 2 ) );
		$this->assertStringContainsString( 'строк пока нет', $this->cell( $service, 3 ) );
		$this->assertStringContainsString( '—', $this->cell( $service, 5 ) );
		$this->assertStringNotContainsString( '<a ', $this->cell( $service, 5 ) );
	}

	public function testOneBatchedQueryForManyRows(): void {
		$this->posts( array( 1 => 'publish', 2 => 'publish', 3 => 'publish', 4 => 'draft' ) );

		$service = $this->service();

		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->cell( $service, $id );
		}

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( 'IN (1,2,3)', $this->db->queries[0] );
		$this->assertStringContainsString( "target_locale = 'en'", $this->db->queries[0] );
		$this->assertStringContainsString( "target_locale = 'de'", $this->db->queries[0] );
	}

	public function testNoQueryWhenNothingOpenable(): void {
		$this->posts( array( 1 => 'draft' ) );

		$this->cell( $this->service(), 1 );

		$this->assertSame( array(), $this->db->queries );
	}

	public function testSinglePostPrimingWithoutMainQuery(): void {
		$this->posts( array( 7 => 'publish' ) );
		unset( $GLOBALS['wp_query'] );

		$this->cell( $this->service(), 7 );

		$this->assertCount( 1, $this->db->queries );
		$this->assertStringContainsString( 'IN (7)', $this->db->queries[0] );
	}

	public function testLinksPointToEditorWithLanguageAndStrings(): void {
		$this->posts( array( 1 => 'publish' ) );

		$html = $this->cell( $this->service(), 1 );

		$this->assertStringContainsString( 'mlp_locale=en', $html );
		$this->assertStringContainsString( 'mlp_locale=de', $html );
		$this->assertStringContainsString( 'mlp_path=%2Fp1%2F', $html );
		$this->assertStringContainsString( 'page=wp-mlp-strings', $html );
		$this->assertMatchesRegularExpression( '/mlp_scope=object(%3A|:)1/', $html );
	}

	public function testLanguageLabelEscaped(): void {
		$this->posts( array( 1 => 'publish' ) );

		$html = $this->cell( $this->service(), 1 );

		$this->assertStringNotContainsString( '<b>English', $html );
		$this->assertStringContainsString( '&lt;b&gt;English', $html );
	}

	public function testFilterIgnoresUnknownLocale(): void {
		$GLOBALS['pagenow']                       = 'edit.php';
		$_GET[ PostListLanguages::FILTER_PARAM ] = "xx' OR 1=1 --";

		$clauses = $this->service()->filterClauses( array( 'where' => ' AND 1=1' ), new FakeMainQuery() );

		$this->assertSame( ' AND 1=1', $clauses['where'] );
	}

	public function testFilterAddsPreparedConditionForKnownLocale(): void {
		$GLOBALS['pagenow']                       = 'edit.php';
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'en';

		$clauses = $this->service()->filterClauses( array( 'where' => ' AND 1=1' ), new FakeMainQuery() );

		$this->assertStringStartsWith( ' AND 1=1 AND (NOT EXISTS', $clauses['where'] );
		$this->assertStringContainsString( "t.target_locale = 'en'", $clauses['where'] );
		$this->assertStringContainsString( 'wp_posts.ID', $clauses['where'] );
		$this->assertStringContainsString( 'a2.object_id <> a.object_id', $clauses['where'] );
	}

	public function testLinkTargetsExcludedFromFilterAndCounts(): void {
		$GLOBALS['pagenow']                       = 'edit.php';
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'en';

		$where = $this->service()->filterClauses( array( 'where' => '' ), new FakeMainQuery() )['where'];

		$this->assertStringContainsString( "ls.kind = 'attribute'", $where );
		$this->assertStringContainsString( "lo.attribute_name = 'href'", $where );
		$this->assertStringContainsString( 'ls.id = a.source_id', $where );

		$this->posts( array( 1 => 'publish' ) );
		$this->cell( $this->service(), 1 );

		$this->assertStringContainsString( "lo.attribute_name = 'href'", $this->db->queries[0] );
		$this->assertStringContainsString( 'ls.id = o.source_id', $this->db->queries[0] );
	}

	public function testPostWithOnlyTranslatedTextIsFullDespiteLinks(): void {
		// SQL уже отфильтровал href: приходят только текстовые строки, все переведены.
		$this->posts( array( 1 => 'publish' ) );
		$this->db->rows = array( array( 'object_id' => 1, 'total' => 3, 'done_0' => 3, 'done_1' => 3 ) );

		$this->assertStringNotContainsString( '%', strip_tags( $this->cell( $this->service(), 1 ) ) );
	}

	public function testDraftLanguageHasNoEditorLink(): void {
		$this->posts( array( 1 => 'publish' ) );

		$html = $this->cell( $this->service(), 1 );

		// Редактор — ровно одна ссылка (en); у черновика de есть только ссылка на строки.
		$this->assertSame( 1, substr_count( $html, 'mlp_path=' ) );
		$this->assertStringContainsString( 'mlp_locale=de', $html );
		$this->assertStringContainsString( '(черн.)', $html );
	}

	public function testSeoLinkShownOnlyWhenSeoStringsMissing(): void {
		$this->posts( array( 1 => 'publish' ) );
		// Колонки: done_0/seo_0 — en, done_1/seo_1 — de.
		$this->db->rows = array( array( 'object_id' => 1, 'total' => 5, 'done_0' => 3, 'seo_0' => 2, 'done_1' => 5, 'seo_1' => 0 ) );

		$html = $this->cell( $this->service(), 1 );

		$this->assertSame( 1, substr_count( $html, 'SEO: ' ) );
		$this->assertStringContainsString( 'SEO: 2', $html );
		$this->assertStringContainsString( 'mlp_tab=seo', $html );
		$this->assertStringContainsString( 'seo_0', $this->db->queries[0] );
		$this->assertStringContainsString( "ss.kind = 'seo'", $this->db->queries[0] );
		$this->assertStringContainsString( "sc.attribute_name = 'content'", $this->db->queries[0] );
	}

	public function testUniquenessUsesNotExistsOnOtherObject(): void {
		$GLOBALS['pagenow']                       = 'edit.php';
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'en';

		$where = $this->service()->filterClauses( array( 'where' => '' ), new FakeMainQuery() )['where'];
		$this->assertStringContainsString( 'a2.object_id <> a.object_id', $where );
		$this->assertStringNotContainsString( 'COUNT(DISTINCT', $where );

		$this->posts( array( 1 => 'publish' ) );
		$this->cell( $this->service(), 1 );
		$this->assertStringContainsString( 'o2.object_id <> o.object_id', $this->db->queries[0] );
		$this->assertStringNotContainsString( 'COUNT(DISTINCT', $this->db->queries[0] );
	}

	public function testOccurrencesSchemaHasObjectIdKey(): void {
		$method = new \ReflectionMethod( \WpMlp\Storage\Schema::class, 'statements' );
		$sql = implode( "\n", $method->invoke( null, '' ) );

		$this->assertStringContainsString( 'KEY object_id (object_id)', $sql );
		$this->assertStringContainsString( 'KEY source_object (source_id,object_id)', $sql );
		$this->assertGreaterThanOrEqual( 2, \WpMlp\Storage\Schema::VERSION );
	}

	public function testFilterOnlyOnEditScreen(): void {
		$GLOBALS['pagenow']                       = 'index.php';
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'en';

		$clauses = $this->service()->filterClauses( array( 'where' => '' ), new FakeMainQuery() );

		$this->assertSame( '', $clauses['where'] );
	}

	public function testFilterDropdownListsSecondaryLanguages(): void {
		$_GET[ PostListLanguages::FILTER_PARAM ] = 'de';

		ob_start();
		$this->service()->renderFilter( 'page', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="mlp_untranslated"', $html );
		$this->assertStringContainsString( 'value="en"', $html );
		$this->assertStringContainsString( 'value="de" selected="selected"', $html );
		$this->assertStringNotContainsString( 'value="ru"', $html );
		$this->assertStringContainsString( '&lt;b&gt;English', $html );
	}

	public function testFilterDropdownHiddenWithoutSecondary(): void {
		$this->configure( false );

		ob_start();
		$this->service()->renderFilter( 'page', 'top' );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
