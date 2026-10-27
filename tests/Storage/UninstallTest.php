<?php
/**
 * uninstall.php: служебные опции убираются всегда, данные — только с согласием владельца,
 * чужие (mlp_*) данные не затрагиваются никогда.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Storage;

use LingoCanvas\Storage\Schema;
use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase {

	/**
	 * Служебные опции версий 0.4.x, которые убираются всегда.
	 */
	private const SERVICE_OPTIONS = array(
		'lingocanvas_data_prefix',
		'lingocanvas_migration',
		'lingocanvas_migration_lock',
		'lingocanvas_write_freeze',
		'lingocanvas_prefix_conflicts',
		'lingocanvas_pending_activation',
		'lingocanvas_legacy_dir',
		'lingocanvas_cloud_ops',
		'lingocanvas_cloud_ops_lock',
		'lingocanvas_cloud_claim_archive',
		'lingocanvas_cloud_claim_unconfirmed',
		'lingocanvas_cloud_claim_lock',
		'lingocanvas_cloud_signup_lock',
	);

	private string $uploads;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/lingocanvas-uninstall-' . uniqid();
		mkdir( $this->uploads . '/lingocanvas-flags', 0777, true );
		mkdir( $this->uploads . '/wp-mlp-flags', 0777, true );
		file_put_contents( $this->uploads . '/lingocanvas-flags/ru.svg', '<svg></svg>' );
		file_put_contents( $this->uploads . '/wp-mlp-flags/ru.svg', '<svg></svg>' );
		lingocanvas_test_uploads( $this->uploads );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix   = 'wp_';
			public string $options  = 'wp_options';
			public string $postmeta = 'wp_postmeta';

			/** @var list<string> Выполненные запросы (после подстановки значений). */
			public array $queries = array();

			/** @var list<string> Что вернёт SHOW TABLES LIKE (резервные таблицы). */
			public array $backupTables = array();

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;

				return (string) preg_replace_callback(
					'/%[si]/',
					static function ( array $m ) use ( &$i, $args ): string {
						$value = (string) ( $args[ $i++ ] ?? '' );

						return '%i' === $m[0] ? '`' . $value . '`' : "'" . $value . "'";
					},
					$sql
				);
			}

			public function query( string $sql ): int {
				$this->queries[] = $sql;

				return 0;
			}

			/**
			 * @return list<string>
			 */
			public function get_col( string $sql ): array {
				$this->queries[] = $sql;

				return $this->backupTables;
			}
		};

		lingocanvas_test_options( array() );
	}

	protected function tearDown(): void {
		$this->remove( $this->uploads );
		unset( $GLOBALS['wpdb'] );
		lingocanvas_test_uploads( sys_get_temp_dir() . '/lingocanvas-uploads' );
		lingocanvas_test_options( array() );
	}

	private function remove( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$this->remove( $path . '/' . $name );
				}
			}

			rmdir( $path );
		} elseif ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Запускает настоящий uninstall.php для текущего сайта (не мультисайт).
	 */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'lingocanvas/lingocanvas.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * @param array<string, mixed> $extra Дополнительные опции сайта.
	 */
	private function seed( bool $consent, array $extra = array() ): void {
		$options = array(
			'lingocanvas_settings'      => array( 'delete_data_on_uninstall' => $consent ),
			'lingocanvas_db_version'    => Schema::VERSION,
			'lingocanvas_cache_version' => 5,
			'lingocanvas_block_hashes'  => array(),
			'mlp_settings'              => array( 'old' => 1 ),
			'mlp_db_version'            => 1,
		);

		foreach ( self::SERVICE_OPTIONS as $name ) {
			$options[ $name ] = 'x';
		}

		lingocanvas_test_options( array_merge( $options, $extra ) );
	}

	/**
	 * @return list<string> Запросы, содержащие подстроку.
	 */
	private function queriesLike( string $needle ): array {
		return array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $q ): bool => str_contains( $q, $needle ) ) );
	}

	public function test_the_inline_table_list_matches_the_schema(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertSame( 1, preg_match( '/foreach \( array\( ([^)]*) \) as \$name \)/', $source, $m ), 'inline table list not found' );

		preg_match_all( "/'([a-z_]+)'/", $m[1], $names );

		$schema = ( new \ReflectionClassConstant( Schema::class, 'TABLES' ) )->getValue();

		$this->assertSame( $schema, $names[1] );
	}

	public function test_uninstall_does_not_declare_classes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertDoesNotMatchRegularExpression( '/^\s*(final\s+)?class\s/m', $source );
	}

	public function test_service_options_are_removed_even_without_consent(): void {
		$this->seed( false );

		$this->uninstall();

		foreach ( self::SERVICE_OPTIONS as $name ) {
			$this->assertArrayNotHasKey( $name, lingocanvas_test_options(), $name );
		}

		$this->assertNotEmpty( $this->queriesLike( 'lingocanvas\\_cleanup\\_' ), 'Опции разовых чисток 0.4.x удаляются.' );
		$this->assertNotEmpty( $this->queriesLike( 'lingocanvas\\_objtype\\_' ) );
	}

	public function test_nothing_else_is_deleted_without_consent(): void {
		$this->seed( false );

		$this->uninstall();

		$this->assertArrayHasKey( 'lingocanvas_settings', lingocanvas_test_options() );
		$this->assertArrayHasKey( 'lingocanvas_db_version', lingocanvas_test_options() );
		$this->assertSame( array(), $this->queriesLike( 'DROP TABLE' ) );
		$this->assertSame( array(), $this->queriesLike( 'postmeta' ) );
		$this->assertFileExists( $this->uploads . '/lingocanvas-flags/ru.svg' );
	}

	public function test_consent_drops_the_four_tables_and_the_backups(): void {
		$this->seed( true );
		$GLOBALS['wpdb']->backupTables = array( 'wp_lingocanvas_bak_sources', 'wp_lingocanvas_bak_routes_2' );

		$this->uninstall();

		foreach ( array( 'sources', 'translations', 'occurrences', 'routes' ) as $name ) {
			$this->assertCount( 1, $this->queriesLike( 'DROP TABLE IF EXISTS `wp_lingocanvas_' . $name . '`' ), $name );
		}

		$this->assertCount( 1, $this->queriesLike( 'DROP TABLE IF EXISTS `wp_lingocanvas_bak_sources`' ) );
		$this->assertCount( 1, $this->queriesLike( 'DROP TABLE IF EXISTS `wp_lingocanvas_bak_routes_2`' ) );
		$this->assertCount( 6, $this->queriesLike( 'DROP TABLE' ) );
	}

	public function test_foreign_backup_names_are_never_dropped(): void {
		$this->seed( true );
		$GLOBALS['wpdb']->backupTables = array( 'wp_users', 'wp_lingocanvas_bak_a`; DROP TABLE wp_users', 'other_lingocanvas_bak_x' );

		$this->uninstall();

		$this->assertSame( array(), $this->queriesLike( 'DROP TABLE IF EXISTS `wp_users' ) );
		$this->assertSame( array(), $this->queriesLike( 'other_lingocanvas_bak_x' ) );
		$this->assertCount( 4, $this->queriesLike( 'DROP TABLE' ) );
	}

	public function test_consent_removes_options_meta_transients_and_the_flags_folder(): void {
		$this->seed( true, array( 'lingocanvas_usage_2026-10-19' => 10 ) );

		$this->uninstall();

		foreach ( array( 'lingocanvas_settings', 'lingocanvas_db_version', 'lingocanvas_cache_version', 'lingocanvas_block_hashes' ) as $name ) {
			$this->assertArrayNotHasKey( $name, lingocanvas_test_options(), $name );
		}

		$this->assertNotEmpty( $this->queriesLike( 'lingocanvas\\_usage\\_' ) );
		$this->assertNotEmpty( $this->queriesLike( 'lingocanvas\\_bak\\_' ), 'Копии опций lingocanvas_bak_*_options удаляются вместе с резервными таблицами.' );
		$this->assertNotEmpty( $this->queriesLike( 'transient\\_lingocanvas\\_' ) );
		$this->assertNotEmpty( $this->queriesLike( '_lingocanvas\\_visible\\_' ) );
		$this->assertNotEmpty( $this->queriesLike( '_lingocanvas\\_translated\\_segments\\_' ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/lingocanvas-flags' );
	}

	public function test_previous_plugin_data_is_never_touched(): void {
		$this->seed( true );

		$this->uninstall();

		$this->assertArrayHasKey( 'mlp_settings', lingocanvas_test_options() );
		$this->assertArrayHasKey( 'mlp_db_version', lingocanvas_test_options() );
		$this->assertSame( array(), $this->queriesLike( 'mlp' ), 'Ни один запрос не упоминает mlp_*.' );
		$this->assertFileExists( $this->uploads . '/wp-mlp-flags/ru.svg' );
	}
}
