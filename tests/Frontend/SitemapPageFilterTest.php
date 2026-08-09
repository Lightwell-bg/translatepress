<?php
/**
 * Тесты отсева служебных страниц из карты сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\SitemapPageFilter;

#[CoversClass( SitemapPageFilter::class )]
final class SitemapPageFilterTest extends TestCase {

	/**
	 * Жалоба на карту сайта: страница «Оформление заказа» (WooCommerce
	 * checkout) сама и её вложенные страницы (история заказов,
	 * подтверждение, счёт, неудавшаяся транзакция) не должны в неё попадать.
	 */
	public function testExcludesServicePageItselfAndItsDescendants(): void {
		$ids       = array( 10, 11, 12, 13, 14, 99 );
		$excluded  = array( 10 ); // «Оформление заказа».
		$ancestors = array(
			10 => array(),
			11 => array( 10 ), // «История заказов» — прямой потомок.
			12 => array( 10 ),
			13 => array( 10 ),
			14 => array( 10 ),
			99 => array(),      // Обычная запись — не задета.
		);

		$this->assertSame(
			array( 99 ),
			SitemapPageFilter::excluding( $ids, $excluded, $ancestors )
		);
	}

	/**
	 * Глубокая вложенность: внук служебной страницы исключается так же, как
	 * прямой потомок — цепочка предков проверяется целиком, а не только
	 * первое звено.
	 */
	public function testExcludesGrandchildThroughFullAncestorChain(): void {
		$ids       = array( 1, 2 );
		$excluded  = array( 1 );
		$ancestors = array(
			// 2 — потомок 5, а 5 — потомок 1 (служебной). Промежуточное
			// звено 5 в списке $ids может и не быть — сама запись 2
			// проверяется, а не факт присутствия родителя в выдаче.
			2 => array( 5, 1 ),
		);

		$this->assertSame( array(), SitemapPageFilter::excluding( $ids, $excluded, $ancestors ) );
	}

	/**
	 * Магазин (`wc_get_page_id('shop')`) в список исключений не передаётся
	 * намеренно (см. Sitemap::woocommercePageIds()) — здесь же просто
	 * подтверждается, что если исключений вообще нет, список не меняется.
	 */
	public function testEmptyExclusionListReturnsIdsUnchanged(): void {
		$ids = array( 1, 2, 3 );

		$this->assertSame( $ids, SitemapPageFilter::excluding( $ids, array(), array() ) );
	}

	/**
	 * Запись без предков (обычная страница верхнего уровня) не задета, даже
	 * если для неё нет записи в карте предков вовсе.
	 */
	public function testIdWithoutAncestorsEntryIsKeptWhenNotExcludedItself(): void {
		$this->assertSame(
			array( 42 ),
			SitemapPageFilter::excluding( array( 42 ), array( 10 ), array() )
		);
	}

	public function testEmptyIdListStaysEmpty(): void {
		$this->assertSame( array(), SitemapPageFilter::excluding( array(), array( 1 ), array() ) );
	}
}
