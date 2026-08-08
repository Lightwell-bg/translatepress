<?php
/**
 * Тесты достраивания языков в пункте меню WordPress.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\NavMenu;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

/**
 * Полноценный `WP_Query`/`wp_nav_menu()` в юнит-тестах недоступен, поэтому
 * проверяется единственная точка, где есть настоящая логика: разбор массива
 * пунктов меню и синтез языковых пунктов вокруг найденного маркера. Это как
 * раз то место, где легко ошибиться в связке `db_id`/`menu_item_parent`.
 */
#[CoversClass( NavMenu::class )]
final class NavMenuTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published', 'flag' => '🇬🇧' ),
						'de' => array( 'locale' => 'de', 'slug' => 'de', 'label' => 'Deutsch', 'status' => 'draft' ),
					),
				),
			)
		);

		// LanguageResolver и UrlConverter читают REQUEST_URI/home через wp_parse_url();
		// в тестовом окружении домашний адрес не задан — этого достаточно, чтобы
		// собрать переключатель, реальный HTTP-запрос здесь не нужен.
		$_SERVER['REQUEST_URI'] = '/';
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		unset( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * @return object Поддельный пункт меню — ровно те поля, что использует NavMenu.
	 */
	private function marker( string $url, int $dbId = 5, int $menuOrder = 3 ): object {
		$item                  = new \stdClass();
		$item->ID              = $dbId;
		$item->db_id           = $dbId;
		$item->url             = $url;
		$item->title           = 'Marker';
		$item->classes         = array();
		$item->menu_item_parent = 0;
		$item->menu_order      = $menuOrder;
		$item->object          = 'custom';
		$item->object_id       = 0;
		$item->type            = 'custom';
		$item->type_label      = 'Custom Link';
		$item->description     = '';
		$item->attr_title      = '';
		$item->target          = '';
		$item->xfn             = '';

		return $item;
	}

	private function navMenu(): NavMenu {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );

		return new NavMenu( $settings, $resolver, new UrlConverter( $settings, $resolver ) );
	}

	public function testDropdownMarkerGetsChildrenParentedToItself(): void {
		$marker = $this->marker( '#mlp-language-switcher-dropdown', 42 );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		// Триггер остался, плюс по одному пункту на каждый опубликованный язык.
		$this->assertCount( 3, $result );

		$children = array_slice( $result, 1 );

		foreach ( $children as $child ) {
			$this->assertSame( 42, $child->menu_item_parent );
		}
	}

	public function testDropdownMarkerIsMarkedAsHavingChildren(): void {
		$marker = $this->marker( '#mlp-language-switcher-dropdown' );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		$this->assertContains( 'menu-item-has-children', $result[0]->classes );
	}

	public function testDropdownDoesNotIncludeDraftLanguage(): void {
		$marker = $this->marker( '#mlp-language-switcher-dropdown' );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		$urls = array_map( static fn( object $item ): string => $item->url, $result );

		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( '/de/', $url );
		}
	}

	/**
	 * «В ряд»: языки — top-level соседи (parent 0), а не дети маркера,
	 * иначе они ушли бы в скрытое подменю темы вместо видимого ряда.
	 */
	public function testRowMarkerChildrenAreTopLevel(): void {
		$marker = $this->marker( '#mlp-language-switcher-row', 7 );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		$this->assertCount( 2, $result );

		foreach ( $result as $item ) {
			$this->assertSame( 0, $item->menu_item_parent );
		}
	}

	public function testRowMarkerItselfBecomesFirstLanguage(): void {
		$marker = $this->marker( '#mlp-language-switcher-row' );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		// Язык по умолчанию (ru) идёт первым — маркер сам стал этим пунктом.
		$this->assertStringContainsString( 'mlp-language-item-ru', implode( ' ', $result[0]->classes ) );
	}

	public function testFlagIsPrependedToTitle(): void {
		$marker = $this->marker( '#mlp-language-switcher-row' );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		$titles = array_map( static fn( object $item ): string => $item->title, $result );

		$this->assertContains( '🇬🇧 English', $titles );
	}

	public function testSyntheticIdsAreUniqueAndDoNotCollideWithMarker(): void {
		$marker = $this->marker( '#mlp-language-switcher-dropdown', 11 );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		$ids = array_map( static fn( object $item ): int => (int) $item->db_id, $result );

		$this->assertSame( $ids, array_unique( $ids ) );
		$this->assertNotContains( 11, array_slice( $ids, 1 ), 'Синтетический ID не должен совпасть с ID маркера.' );
	}

	/**
	 * Обычные пункты меню (не маркеры) должны пройти без изменений.
	 */
	public function testRegularItemsPassThroughUnchanged(): void {
		$regular       = $this->marker( 'https://example.com/about/', 3 );
		$regular->title = 'О нас';

		$result = $this->navMenu()->injectLanguages( array( $regular ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'О нас', $result[0]->title );
		$this->assertSame( 'https://example.com/about/', $result[0]->url );
	}

	/**
	 * InternalLinks::apply() (второй проход по готовому HTML, добавляющий
	 * языковой префикс к ссылкам темы) узнаёт ссылки переключателя по классу
	 * прямо на `<a>` — ядро WordPress по умолчанию выводит `$item->classes`
	 * только на `<li>`. Без этого фильтра переключатель ломался бы точно так
	 * же, как в жалобе, на любой теме с собственным Walker'ом.
	 */
	public function testLinkAttributesGetsSwitcherClassForLanguageItems(): void {
		$marker = $this->marker( '#mlp-language-switcher-dropdown', 42 );
		$result = $this->navMenu()->injectLanguages( array( $marker ) );

		$languageItem = $result[1];
		$this->assertContains( 'mlp-language-item', $languageItem->classes );

		$atts = $this->navMenu()->filterLinkAttributes( array(), $languageItem );

		$this->assertSame( 'mlp-language-item', $atts['class'] );
	}

	/**
	 * Обычные пункты меню класс не получают и остальные атрибуты ссылки
	 * (например заданные темой `target`) не теряют.
	 */
	public function testLinkAttributesLeavesRegularItemsUntouched(): void {
		$regular = $this->marker( 'https://example.com/about/', 3 );

		$atts = $this->navMenu()->filterLinkAttributes( array( 'target' => '_blank' ), $regular );

		$this->assertSame( array( 'target' => '_blank' ), $atts );
	}

	/**
	 * Один опубликованный язык — переключаться не на что, маркер убирается
	 * целиком, а не остаётся пустой нерабочей ссылкой в меню.
	 */
	public function testMarkerIsRemovedWhenOnlyOneLanguageIsPublished(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
					),
				),
			)
		);

		$marker = $this->marker( '#mlp-language-switcher-dropdown' );
		$other  = $this->marker( 'https://example.com/', 99 );

		$result = $this->navMenu()->injectLanguages( array( $marker, $other ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/', $result[0]->url );
	}
}
