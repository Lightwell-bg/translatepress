<?php
/**
 * Тесты ручного включения записей на дополнительных языках.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Storage\PostStringScope;
use WpMlp\Storage\TranslationCache;
use WpMlp\Tests\Doubles\FakeWpdb;

#[CoversClass( PostLanguageVisibility::class )]
#[CoversClass( PostStringScope::class )]
#[CoversClass( OccurrenceRepository::class )]
final class PostLanguageVisibilityTest extends TestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;

		wp_mlp_test_postmeta( array() );
		wp_mlp_test_options( array() );
		wp_mlp_test_actions( null, true );
		unset( $GLOBALS['wp_mlp_test_meta_primed'] );

		wp_mlp_test_wp(
			array(
				'posts' => array(
					10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) ),
					11 => new \WP_Post( array( 'ID' => 11, 'post_type' => 'post' ) ),
					12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment' ) ),
					13 => new \WP_Post( array( 'ID' => 13, 'post_type' => 'secret' ) ),
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_mlp_test_meta_primed'] );
		wp_mlp_test_postmeta( array() );
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array() );
	}

	private function service(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	public function testMetaKeyIsBuiltFromSanitizedLocale(): void {
		$this->assertSame( '_mlp_visible_de', PostLanguageVisibility::metaKey( 'de' ) );
		$this->assertSame( '_mlp_visible_pt_br', PostLanguageVisibility::metaKey( 'pt-BR' ) );
		$this->assertSame( '_mlp_visible_pt_br', PostLanguageVisibility::metaKey( ' pt_br ' ) );

		// Цифры остаются (es-419), всё остальное, кроме букв и подчёркивания, выпадает: кавычкам в имени метаполя не место.
		$this->assertSame( '_mlp_visible_es_419', PostLanguageVisibility::metaKey( 'es-419' ) );
		$this->assertSame( '_mlp_visible_zh_hans', PostLanguageVisibility::metaKey( 'zh-Hans' ) );
		$this->assertSame( '_mlp_visible_dedrop', PostLanguageVisibility::metaKey( "de';DROP" ) );
		$this->assertSame( '_mlp_visible_123', PostLanguageVisibility::metaKey( '123' ) );
		$this->assertSame( '', PostLanguageVisibility::metaKey( '' ) );
		$this->assertSame( '', PostLanguageVisibility::metaKey( "';!" ) );
	}

	public function testMetaPrefixMatchesUninstallPattern(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString( PostLanguageVisibility::META_PREFIX, $uninstall );
		$this->assertStringContainsString( 'esc_like', $uninstall );
	}

	public function testSetAndGetPerLanguage(): void {
		$service = $this->service();

		$this->assertFalse( $service->isVisible( 10, 'en' ) );

		$this->assertTrue( $service->set( 10, 'en', true ) );
		$this->assertTrue( $service->isVisible( 10, 'en' ) );
		$this->assertFalse( $service->isVisible( 10, 'de' ), 'Флаг одного языка не включает другой.' );
		$this->assertFalse( $service->isVisible( 11, 'en' ) );

		// Хранится как ключ-на-язык, чтобы выбирать через EXISTS.
		$this->assertSame( '1', wp_mlp_test_postmeta()[10]['_mlp_visible_en'] );

		$this->assertTrue( $service->set( 10, 'en', false ) );
		$this->assertFalse( $service->isVisible( 10, 'en' ) );
		$this->assertArrayNotHasKey( '_mlp_visible_en', wp_mlp_test_postmeta()[10] );
	}

	public function testSettingSameStateChangesNothingAndDoesNotFlush(): void {
		$service = $this->service();

		$this->assertFalse( $service->set( 10, 'en', false ) );
		$this->assertSame( array(), wp_mlp_test_actions(), 'Без изменений кэш страниц не сбрасывается.' );
		$this->assertSame( array(), wp_mlp_test_options() );

		$service->set( 10, 'en', true );

		$this->assertFalse( $service->set( 10, 'en', true ) );
	}

	public function testChangeFlushesPageCachesOnceForAWholeBatch(): void {
		$service = $this->service();

		$service->setMany( array( 10, 11, 10 ), 'en', true );

		$this->assertSame( 1, array_count_values( wp_mlp_test_actions() )['mlp_purge_page_cache'] ?? 0 );
		$this->assertSame( 2, wp_mlp_test_options()[ TranslationCache::OPTION_VERSION ], 'Версия кэша выросла — карта сайта пересоберётся.' );
	}

	public function testSetManyCountsOnlyRealChangesAndSkipsGarbage(): void {
		$service = $this->service();
		$service->set( 10, 'en', true );

		$this->assertSame( 2, $service->setMany( array( 10, 11, 0, -5, 12 ), 'en', true ), '11 и 12 новые; 10 уже включена; 0 и -5 мусор.' );
		$this->assertSame( 0, $service->setMany( array( 10 ), "';!", true ), 'Язык без допустимых символов ничего не пишет.' );
	}

	public function testBatchPrimesMetaWithOneCall(): void {
		$service = $this->service();

		$states = $service->states( array( 10, 11, 12 ), 'en' );

		$this->assertSame( array( 10 => false, 11 => false, 12 => false ), $states );
		$this->assertCount( 1, $GLOBALS['wp_mlp_test_meta_primed'] );
		$this->assertSame( array( 'post', array( 10, 11, 12 ) ), $GLOBALS['wp_mlp_test_meta_primed'][0] );
	}

	public function testVisibleAmongKeepsOnlyEnabled(): void {
		$service = $this->service();
		$service->setMany( array( 11 ), 'en', true );

		$this->assertSame( array( 11 ), $service->visibleAmong( array( 10, 11, 12 ), 'en' ) );
		$this->assertSame( array(), $service->visibleAmong( array( 10, 11, 12 ), 'de' ) );
	}

	public function testCountAsksForExactKey(): void {
		$this->db->var = '7';

		$this->assertSame( 7, $this->service()->count( 'pt-br' ) );
		$this->assertStringContainsString( "meta_key = '_mlp_visible_pt_br'", $this->db->queries[0] );
		$this->assertSame( 0, $this->service()->count( "';!" ) );
	}

	public function testCountOnlyPublishedPostsOfEligibleTypes(): void {
		$this->db->var = '3';

		$this->assertSame( 3, $this->service()->count( 'en' ) );

		$sql = $this->db->queries[0];

		$this->assertStringContainsString( 'INNER JOIN wp_posts p ON p.ID = pm.post_id', $sql );
		$this->assertStringContainsString( "p.post_status = 'publish'", $sql );
		// Вложения и непубличные типы в счёт не входят.
		$this->assertStringContainsString( "p.post_type IN ('post','page')", $sql );
		$this->assertStringNotContainsString( 'attachment', $sql );
		$this->assertStringNotContainsString( 'secret', $sql );
	}

	public function testEligibleTypes(): void {
		$service = $this->service();

		$this->assertTrue( $service->isEligiblePost( 10 ) );
		$this->assertTrue( $service->isEligiblePost( 11 ) );
		$this->assertFalse( $service->isEligiblePost( 12 ), 'Вложение.' );
		$this->assertFalse( $service->isEligiblePost( 13 ), 'Непубличный тип.' );
		$this->assertFalse( $service->isEligiblePost( 999 ), 'Нет такой записи.' );
	}

	public function testEnableFullyTranslatedEnablesOnlyEligibleAndCountsNewOnes(): void {
		// 10, 11 — подходят; 12 — вложение; 13 — непубличный; 999 — удалена.
		$this->db->cols = array( '10', '11', '12', '13', '999' );

		$service = $this->service();

		$this->assertSame( 2, $service->enableFullyTranslated( 'en' ) );
		$this->assertTrue( $service->isVisible( 10, 'en' ) );
		$this->assertTrue( $service->isVisible( 11, 'en' ) );
		$this->assertFalse( $service->isVisible( 12, 'en' ) );

		$this->assertSame( 0, $service->enableFullyTranslated( 'en' ), 'Повторный вызов ничего нового не включает.' );
	}

	public function testEnableFullyTranslatedRestrictsToPublishedEligiblePostsInSql(): void {
		// Первый ответ — id из таблицы вхождений (в том числе «чужой» id), второй — то, что осталось после проверки в БД.
		$this->db->colsQueue = array( array( '10', '11', '5' ), array( '10' ) );

		$service = $this->service();

		$this->assertSame( 1, $service->enableFullyTranslated( 'en' ), 'Включается только то, что подтвердила БД.' );
		$this->assertTrue( $service->isVisible( 10, 'en' ) );
		$this->assertFalse( $service->isVisible( 11, 'en' ) );

		$sql = $this->db->queries[1];

		$this->assertStringContainsString( 'FROM wp_posts', $sql );
		$this->assertStringContainsString( "post_status = 'publish'", $sql );
		$this->assertStringContainsString( "post_type IN ('post','page')", $sql );
		$this->assertStringContainsString( 'ID IN (10,11,5)', $sql );
		$this->assertStringNotContainsString( 'attachment', $sql );
	}

	public function testEnableFullyTranslatedRejectsBadLocaleWithoutQuery(): void {
		$this->assertSame( 0, $this->service()->enableFullyTranslated( "en'; --" ) );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * Запись без единой известной строки полностью переведённой не считается:
	 * запрос идёт ТОЛЬКО по подзапросу строк записей, и записи без строк в
	 * него попасть не могут. Сравнение «переведено = всего» не пропускает и
	 * частично переведённые.
	 */
	public function testFullyTranslatedSqlUsesSharedStringRules(): void {
		( new OccurrenceRepository() )->fullyTranslatedObjectIds( 'en' );

		$sql = $this->db->queries[0];

		$this->assertStringContainsString( "t.target_locale = 'en'", $sql );
		$this->assertStringContainsString( 'FROM ( SELECT DISTINCT o.object_id, o.source_id FROM wp_mlp_occurrences o', $sql );
		$this->assertStringContainsString( "t.translated_text IS NOT NULL AND t.translated_text <> ''", $sql );
		$this->assertStringContainsString( 'HAVING SUM(', $sql );
		$this->assertStringContainsString( '= COUNT(*)', $sql );

		// Те же фрагменты, что у колонки «Языки»: строка принадлежит только записи, ссылки не считаются.
		$this->assertStringContainsString( PostStringScope::uniqueNotLinkSql( 'o' ), $sql );
		$this->assertStringContainsString( "lo.attribute_name = 'href'", $sql );
		$this->assertStringContainsString( 'o2.object_id <> o.object_id', $sql );
	}

	/**
	 * Id рубрики или автора не должен считаться id записи: везде, где
	 * `object_id` трактуется как запись, обязателен `object_type = 'post'`.
	 */
	public function testPostScopeSqlRequiresPostObjectType(): void {
		$rows = PostStringScope::ownRowsSql( 'o.object_id = 5' );

		$this->assertStringContainsString( "o.object_type = 'post' AND o.object_id = 5", $rows );
		$this->assertStringContainsString( "o2.object_type = 'post' AND o2.object_id <> o.object_id", $rows );
		$this->assertStringContainsString( "a.object_type = 'post' AND a.object_id = wp_posts.ID", PostStringScope::ownCondition( 'a', 'wp_posts.ID' ) );
	}

	public function testOccurrenceQueriesFilterByPostObjectType(): void {
		$repo = new OccurrenceRepository();

		$repo->fullyTranslatedObjectIds( 'en' );
		$repo->translationProgress( 11, 'en' );
		$repo->translatedObjectIds( 'en' );
		$repo->lastTranslatedAt( 'en' );
		$repo->objectIds();
		$repo->belongsToObject( 3, 11 );

		$this->assertGreaterThanOrEqual( 6, count( $this->db->queries ) );

		foreach ( $this->db->queries as $sql ) {
			$this->assertStringContainsString( "object_type = 'post'", $sql );
		}
	}

	public function testColumnAndBulkEnableShareOneDefinition(): void {
		$this->assertSame(
			\WpMlp\Admin\PostListLanguages::notLinkSql( 'a.source_id' ),
			PostStringScope::notLinkSql( 'a.source_id' )
		);
		$this->assertSame(
			\WpMlp\Admin\PostListLanguages::isSeoSql( 'o.source_id' ),
			PostStringScope::isSeoSql( 'o.source_id' )
		);
	}
}
