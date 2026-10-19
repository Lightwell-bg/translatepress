<?php
/**
 * Тесты сброса кэша страниц сторонних плагинов.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Storage\PageCachePurger;

/**
 * Ни один кэширующий плагин в тестовом окружении не установлен — все ветки
 * с function_exists()/class_exists() заведомо ложные. Проверяется то, что
 * действительно можно проверить без реального плагина: вызов не падает
 * и точка расширения для остальных кэшей срабатывает всегда.
 */
#[CoversClass( PageCachePurger::class )]
final class PageCachePurgerTest extends TestCase {

	public function testDoesNotFailWhenNoCachingPluginIsInstalled(): void {
		PageCachePurger::purgeAll();

		$this->addToAssertionCount( 1 );
	}

	public function testFiresExtensionHookForCachesWithoutBuiltInSupport(): void {
		lingocanvas_test_actions( null, true );

		PageCachePurger::purgeAll();

		$this->assertContains( 'lingocanvas_purge_page_cache', lingocanvas_test_actions() );
	}

	public function testDoesNotFireTheRemovedLegacyHookName(): void {
		lingocanvas_test_actions( null, true );

		PageCachePurger::purgeAll();

		$this->assertSame( array( 'lingocanvas_purge_page_cache' ), array_values( array_filter( lingocanvas_test_actions(), static fn( string $name ): bool => str_contains( $name, 'purge_page_cache' ) ) ) );
	}
}
