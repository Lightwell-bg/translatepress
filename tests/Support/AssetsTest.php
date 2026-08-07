<?php
/**
 * Тесты версионирования скриптов и стилей.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Support\Assets;

#[CoversClass( Assets::class )]
final class AssetsTest extends TestCase {

	public function testUrlIsBuiltFromPluginUrl(): void {
		$this->assertSame( WP_MLP_URL . 'assets/admin.js', Assets::url( 'assets/admin.js' ) );
	}

	public function testLeadingSlashDoesNotDoubleUp(): void {
		$this->assertSame( WP_MLP_URL . 'assets/admin.js', Assets::url( '/assets/admin.js' ) );
	}

	/**
	 * Версия существующего файла включает время изменения — иначе после
	 * обновления плагина копированием файлов браузер продолжил бы отдавать
	 * старый скрипт из кэша по тому же адресу.
	 */
	public function testVersionOfRealFileIncludesModificationTime(): void {
		$version = Assets::version( 'assets/admin.js' );

		$this->assertNotSame( WP_MLP_VERSION, $version );
		$this->assertStringStartsWith( WP_MLP_VERSION . '.', $version );
	}

	public function testMissingFileFallsBackToPluginVersion(): void {
		$this->assertSame( WP_MLP_VERSION, Assets::version( 'assets/does-not-exist.js' ) );
	}
}
