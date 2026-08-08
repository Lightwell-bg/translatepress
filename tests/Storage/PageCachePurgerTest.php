<?php
/**
 * Тесты сброса кэша страниц сторонних плагинов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Storage\PageCachePurger;

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
		wp_mlp_test_actions( null, true );

		PageCachePurger::purgeAll();

		$this->assertContains( 'mlp_purge_page_cache', wp_mlp_test_actions() );
	}
}
