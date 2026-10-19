<?php
/**
 * Тесты активации: чистая установка создаёт таблицы lingocanvas_* и больше ничего лишнего.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Plugin;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\Schema;

#[CoversClass( Plugin::class )]
#[CoversClass( Schema::class )]
final class PluginActivationTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/wp-admin/includes/upgrade.php';

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			/** @var list<string> Имена таблиц из CREATE TABLE, переданных в dbDelta(). */
			public array $created = array();

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}

			/** @var list<string> Таблицы, которые dbDelta «не смог» создать. */
			public array $failing = array();

			public function createFromSql( string $sql ): void {
				if ( 1 === preg_match( '/CREATE TABLE\s+(\S+)/', $sql, $m ) && ! in_array( $m[1], $this->failing, true ) ) {
					$this->created[] = $m[1];
				}
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $sql, string $arg ): string {
				return str_replace( '%s', "'" . $arg . "'", $sql );
			}

			/** SHOW TABLES LIKE: отдаёт имя, если таблица создана. */
			public function get_var( string $sql ): ?string {
				if ( 1 !== preg_match( "/LIKE '(.*)'/", $sql, $m ) ) {
					return null;
				}

				$name = stripslashes( $m[1] );

				return in_array( $name, $this->created, true ) ? $name : null;
			}
		};

		lingocanvas_test_options( array() );
		lingocanvas_test_install_log( null, true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		lingocanvas_test_options( array() );
		lingocanvas_test_install_log( null, true );
	}

	public function testActivateOnAFreshSiteCreatesTheFourTablesAndDefaultSettings(): void {
		Plugin::activate();

		$this->assertSame(
			array( 'wp_lingocanvas_sources', 'wp_lingocanvas_translations', 'wp_lingocanvas_occurrences', 'wp_lingocanvas_routes' ),
			$GLOBALS['wpdb']->created
		);
		$this->assertArrayHasKey( 'lingocanvas_settings', lingocanvas_test_options() );
		$this->assertSame( Schema::VERSION, lingocanvas_test_options()['lingocanvas_db_version'] );
		$this->assertContains( 'flush', lingocanvas_test_install_log() );
	}

	public function testActivateWritesNoServiceOptionsOfThePreviousVersions(): void {
		Plugin::activate();

		$names = array_keys( lingocanvas_test_options() );
		sort( $names );

		$this->assertSame( array( 'lingocanvas_db_version', 'lingocanvas_settings' ), $names );
	}

	public function testActivateKeepsExistingSettings(): void {
		lingocanvas_test_options( array( Settings::optionName() => array( 'marker' => 'kept' ) ) );

		Plugin::activate();

		$this->assertSame( array( 'marker' => 'kept' ), lingocanvas_test_options()[ Settings::optionName() ] );
	}

	public function testMaybeUpgradeInstallsOnlyWhenTheVersionDiffers(): void {
		lingocanvas_test_options( array( Schema::versionOption() => Schema::VERSION ) );

		Schema::maybeUpgrade();

		$this->assertSame( array(), $GLOBALS['wpdb']->created, 'Версия совпадает: dbDelta не вызывается.' );

		lingocanvas_test_options( array( Schema::versionOption() => Schema::VERSION - 1 ) );

		Schema::maybeUpgrade();

		$this->assertCount( 4, $GLOBALS['wpdb']->created );
		$this->assertSame( Schema::VERSION, lingocanvas_test_options()[ Schema::versionOption() ] );
	}

	public function testInstallDoesNotSaveTheVersionWhenATableIsMissing(): void {
		$GLOBALS['wpdb']->failing = array( 'wp_lingocanvas_routes' );

		$this->assertFalse( Schema::install() );
		$this->assertArrayNotHasKey( Schema::versionOption(), lingocanvas_test_options() );

		// Следующий запрос в админке повторяет установку.
		lingocanvas_test_install_log( null, true );
		Schema::maybeUpgrade();
		$this->assertCount( 4, lingocanvas_test_install_log() );
		$this->assertArrayNotHasKey( Schema::versionOption(), lingocanvas_test_options() );

		// Привилегия появилась: таблица создаётся, версия сохраняется.
		$GLOBALS['wpdb']->failing = array();
		Schema::maybeUpgrade();
		$this->assertSame( Schema::VERSION, lingocanvas_test_options()[ Schema::versionOption() ] );
	}

	public function testInstallSavesTheVersionWhenAllTablesExist(): void {
		$this->assertTrue( Schema::install() );
		$this->assertSame( Schema::VERSION, lingocanvas_test_options()[ Schema::versionOption() ] );
	}

	public function testSchemaVersionAndNamesAreFixed(): void {
		$this->assertSame( 2, Schema::VERSION );
		$this->assertSame( 'lingocanvas_db_version', Schema::versionOption() );
		$this->assertSame( 'lingocanvas_settings', Settings::optionName() );
		$this->assertSame( 'wp_lingocanvas_routes', Schema::table( 'routes' ) );
	}

	public function testUnknownTableNameIsRejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		Schema::table( 'options' );
	}
}
