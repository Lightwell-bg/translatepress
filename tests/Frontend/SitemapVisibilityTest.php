<?php
/**
 * Тесты карты сайта при ручном показе записей на языках.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Frontend\Sitemap;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Tests\Doubles\FakeWpdb;

#[CoversClass( Sitemap::class )]
final class SitemapVisibilityTest extends TestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;

		lingocanvas_test_postmeta( array() );
		lingocanvas_test_request( array() );
		unset( $GLOBALS['lingocanvas_test_meta_primed'] );
		$_SERVER['REQUEST_URI'] = '/lingocanvas-sitemap.xml';

		$this->configure( Settings::POSTS_MANUAL );

		lingocanvas_test_wp(
			array(
				'posts'     => array(
					10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_name' => 'about' ) ),
					11 => new \WP_Post( array( 'ID' => 11, 'post_type' => 'post', 'post_name' => 'blog-post' ) ),
				),
				'get_posts' => array( 11, 10 ),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['lingocanvas_test_meta_primed'], $_SERVER['REQUEST_URI'] );
		lingocanvas_test_options( array() );
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_wp( array() );
	}

	private function configure( string $mode ): void {
		lingocanvas_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::optionName() => array(
					'default_locale'          => 'ru',
					'untranslated_posts_mode' => $mode,
					'languages'               => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
						'de' => array( 'locale' => 'de', 'slug' => 'de', 'label' => 'Deutsch', 'status' => 'published' ),
					),
				),
			)
		);
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	/**
	 * Адреса `loc` карты сайта.
	 *
	 * @return list<string>
	 */
	private function locs(): array {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$sitemap  = new Sitemap(
			$settings,
			new UrlConverter( $settings, $resolver ),
			new OccurrenceRepository(),
			new TranslationCache(),
			$this->visibility()
		);

		$entries = ( new \ReflectionMethod( $sitemap, 'entries' ) )->invoke( $sitemap );

		return array_map( static fn( array $entry ): string => $entry['loc'], $entries );
	}

	/**
	 * Адреса `href` из alternates записи с данным `loc`.
	 *
	 * @return list<string>
	 */
	private function alternates( string $loc ): array {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$sitemap  = new Sitemap( $settings, new UrlConverter( $settings, $resolver ), new OccurrenceRepository(), new TranslationCache(), $this->visibility() );

		foreach ( ( new \ReflectionMethod( $sitemap, 'entries' ) )->invoke( $sitemap ) as $entry ) {
			if ( $entry['loc'] === $loc ) {
				return array_map( static fn( array $alt ): string => $alt['href'], $entry['alternates'] ?? array() );
			}
		}

		return array();
	}

	public function testOnlyEnabledPostsAppearOnSecondaryLanguage(): void {
		$this->visibility()->set( 10, 'en', true );

		$locs = $this->locs();

		$this->assertContains( 'https://example.test/about/', $locs );
		$this->assertContains( 'https://example.test/en/about/', $locs );
		$this->assertNotContains( 'https://example.test/de/about/', $locs, 'На de страница не включена.' );

		$this->assertContains( 'https://example.test/blog-post/', $locs, 'Исходная версия есть всегда.' );
		$this->assertNotContains( 'https://example.test/en/blog-post/', $locs );
		$this->assertNotContains( 'https://example.test/de/blog-post/', $locs );
	}

	public function testHiddenLanguageIsAlsoAbsentFromAlternates(): void {
		$this->visibility()->set( 10, 'en', true );

		$alternates = $this->alternates( 'https://example.test/about/' );

		$this->assertContains( 'https://example.test/en/about/', $alternates );
		$this->assertNotContains( 'https://example.test/de/about/', $alternates );

		// Запись, не включённая нигде, — только сама себя, без взаимного набора.
		$this->assertSame( array( 'https://example.test/blog-post/' ), $this->alternates( 'https://example.test/blog-post/' ) );
	}

	public function testFeedHomeAppearsOnLanguageOnlyWhenSomethingIsEnabledThere(): void {
		$this->visibility()->set( 11, 'en', true );

		$locs = $this->locs();

		$this->assertContains( 'https://example.test/en/', $locs );
		$this->assertNotContains( 'https://example.test/de/', $locs );
	}

	public function testStaticFrontPageFollowsItsOwnFlag(): void {
		lingocanvas_test_options( lingocanvas_test_options() + array( 'page_on_front' => 10 ) );

		$this->assertNotContains( 'https://example.test/en/', $this->locs() );

		$this->visibility()->set( 10, 'en', true );

		$this->assertContains( 'https://example.test/en/', $this->locs() );
	}

	public function testMetaOfAllPostsIsPrimedInOneCall(): void {
		$this->locs();

		$calls = array_filter( $GLOBALS['lingocanvas_test_meta_primed'], static fn( array $c ): bool => 'post' === $c[0] );

		$this->assertCount( 1, $calls, 'Метаполя всех записей прогреваются разом, не по одной.' );
		$this->assertSame( array( 11, 10 ), array_values( $calls )[0][1] );
	}

	public function testOldRuleIsKeptInModeAll(): void {
		$this->configure( Settings::POSTS_ALL );
		// Старое правило «есть хотя бы один перевод»: в базе переведена запись 11.
		$this->db->cols = array( '11' );
		$this->visibility()->set( 10, 'en', true );

		$locs = $this->locs();

		$this->assertContains( 'https://example.test/en/blog-post/', $locs );
		$this->assertNotContains( 'https://example.test/en/about/', $locs, 'В режиме «все» флаги не учитываются.' );
	}
}
