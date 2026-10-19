<?php
/**
 * Тесты ссылок на страницу, скрытую на части языков: переключатель, меню, hreflang.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Frontend\CurrentPageLanguages;
use LingoCanvas\Frontend\LanguageSwitcher;
use LingoCanvas\Frontend\NavMenu;
use LingoCanvas\Frontend\SeoTags;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Tests\Doubles\FakeWpdb;

#[CoversClass( CurrentPageLanguages::class )]
#[CoversClass( LanguageSwitcher::class )]
#[CoversClass( NavMenu::class )]
#[CoversClass( SeoTags::class )]
final class HiddenPageLinksTest extends TestCase {

	/**
	 * Запись текущей страницы для провайдера.
	 */
	private int $currentPost = 10;

	protected function setUp(): void {
		$GLOBALS['wpdb']   = new FakeWpdb();
		$this->currentPost = 10;

		lingocanvas_test_postmeta( array() );
		lingocanvas_test_request( array() );
		$_SERVER['REQUEST_URI'] = '/about/';
		unset( $_SERVER['QUERY_STRING'] );

		$this->configure( Settings::POSTS_MANUAL );

		lingocanvas_test_wp(
			array(
				'posts' => array(
					10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page' ) ),
					12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment' ) ),
				),
			)
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REQUEST_URI'] );
		lingocanvas_test_options( array() );
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_wp( array() );
	}

	private function configure( string $mode, bool $withGerman = true ): void {
		$languages = array(
			'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
			'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
		);

		if ( $withGerman ) {
			$languages['de'] = array( 'locale' => 'de', 'slug' => 'de', 'label' => 'Deutsch', 'status' => 'published' );
		}

		lingocanvas_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::optionName() => array(
					'default_locale'          => 'ru',
					'untranslated_posts_mode' => $mode,
					'languages'               => $languages,
				),
			)
		);
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	private function pageLanguages( ?Settings $settings = null ): CurrentPageLanguages {
		return new CurrentPageLanguages( $settings ?? new Settings(), $this->visibility(), fn(): int => $this->currentPost );
	}

	// --- CurrentPageLanguages ---------------------------------------------

	public function testPageIsHiddenOnLanguagesItWasNotEnabledFor(): void {
		$settings = new Settings();
		$this->visibility()->set( 10, 'de', true );

		$pages = $this->pageLanguages( $settings );

		$this->assertTrue( $pages->isHiddenOn( $settings->get( 'en' ) ) );
		$this->assertFalse( $pages->isHiddenOn( $settings->get( 'de' ) ) );
	}

	public function testDefaultLanguageIsNeverHidden(): void {
		$settings = new Settings();

		$this->assertFalse( $this->pageLanguages( $settings )->isHiddenOn( $settings->get( 'ru' ) ) );
	}

	public function testNothingIsHiddenInModeAllOrOutsideSinglePosts(): void {
		$settings = new Settings();
		$en       = $settings->get( 'en' );

		$this->currentPost = 0;
		$this->assertFalse( $this->pageLanguages( $settings )->isHiddenOn( $en ), 'Архив или поиск.' );

		$this->currentPost = 12;
		$this->assertFalse( $this->pageLanguages( $settings )->isHiddenOn( $en ), 'Вложение.' );

		$this->configure( Settings::POSTS_ALL );
		$this->currentPost = 10;
		$settings          = new Settings();
		$this->assertFalse( $this->pageLanguages( $settings )->isHiddenOn( $settings->get( 'en' ) ) );
	}

	// --- Переключатель языков ---------------------------------------------

	private function switcher( bool $withPageLanguages = true ): LanguageSwitcher {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );

		return new LanguageSwitcher(
			$settings,
			$resolver,
			new UrlConverter( $settings, $resolver ),
			$withPageLanguages ? $this->pageLanguages( $settings ) : null
		);
	}

	public function testSwitcherLeadsToLanguageHomeWhenPageIsHiddenThere(): void {
		$this->visibility()->set( 10, 'de', true );

		$html = $this->switcher()->render();

		$this->assertStringContainsString( 'href="https://example.test/en/"', $html, 'Скрыто на en — на главную en.' );
		$this->assertStringNotContainsString( 'https://example.test/en/about/', $html );
		$this->assertStringContainsString( 'href="https://example.test/de/about/"', $html, 'Включено на de — на ту же страницу.' );
		$this->assertStringContainsString( 'href="https://example.test/about/"', $html );
	}

	public function testSwitcherUnchangedWithoutPageLanguagesService(): void {
		$html = $this->switcher( false )->render();

		$this->assertStringContainsString( 'href="https://example.test/en/about/"', $html );
	}

	// --- Меню ---------------------------------------------------------------

	public function testNavMenuLanguageItemsFallBackToHomeForHiddenPage(): void {
		$this->visibility()->set( 10, 'de', true );

		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$menu     = new NavMenu( $settings, $resolver, new UrlConverter( $settings, $resolver ), $this->pageLanguages( $settings ) );

		$marker = (object) array(
			'ID'               => 5,
			'db_id'            => 5,
			'url'              => '#mlp-language-switcher-dropdown',
			'title'            => 'Marker',
			'classes'          => array(),
			'menu_item_parent' => 0,
			'menu_order'       => 3,
			'object'           => 'custom',
			'object_id'        => 0,
			'type'             => 'custom',
		);

		$urls = array();

		foreach ( $menu->injectLanguages( array( $marker ) ) as $item ) {
			if ( in_array( 'mlp-language-item', (array) $item->classes, true ) ) {
				$urls[] = $item->url;
			}
		}

		$this->assertSame(
			array( 'https://example.test/about/', 'https://example.test/en/', 'https://example.test/de/about/' ),
			$urls
		);
	}

	// --- hreflang -------------------------------------------------------------

	private function hreflang( bool $withPageLanguages = true ): string {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$tags     = new SeoTags(
			$settings,
			$resolver,
			new UrlConverter( $settings, $resolver ),
			$withPageLanguages ? $this->pageLanguages( $settings ) : null
		);

		ob_start();
		$tags->printHreflang();

		return (string) ob_get_clean();
	}

	public function testHreflangOmitsLanguagesWhereThePageIsHidden(): void {
		$this->visibility()->set( 10, 'de', true );

		$out = $this->hreflang();

		$this->assertStringNotContainsString( 'hreflang="en"', $out );
		$this->assertStringContainsString( 'hreflang="de" href="https://example.test/de/about/"', $out );
		$this->assertStringContainsString( 'hreflang="ru" href="https://example.test/about/"', $out );
		$this->assertStringContainsString( 'hreflang="x-default" href="https://example.test/about/"', $out );
	}

	public function testHreflangKeepsEverythingWhenNothingIsHidden(): void {
		$this->visibility()->setMany( array( 10 ), 'en', true );
		$this->visibility()->setMany( array( 10 ), 'de', true );

		$out = $this->hreflang();

		$this->assertSame( 4, substr_count( $out, '<link rel="alternate"' ) );
	}

	public function testHreflangIsOmittedWhenOnlyDefaultLanguageRemains(): void {
		$this->configure( Settings::POSTS_MANUAL, false );

		$this->assertSame( '', $this->hreflang(), 'Одна версия страницы — взаимного набора нет.' );
	}

	public function testHreflangUnchangedInModeAll(): void {
		$this->configure( Settings::POSTS_ALL );

		$this->assertStringContainsString( 'hreflang="en"', $this->hreflang() );
	}
}
