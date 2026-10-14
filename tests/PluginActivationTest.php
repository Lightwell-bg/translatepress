<?php
/**
 * Тесты отложенной активации (переход с прежнего плагина).
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Plugin;
use WpMlp\Settings\Settings;

#[CoversClass( Plugin::class )]
final class PluginActivationTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/wp-admin/includes/upgrade.php';

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}
		};

		wp_mlp_test_options( array() );
		wp_mlp_test_install_log( null, true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		wp_mlp_test_options( array() );
		wp_mlp_test_install_log( null, true );
	}

	public function testFinishPendingActivationClearsFlagAndRunsInstallSteps(): void {
		wp_mlp_test_options( array( Plugin::PENDING_ACTIVATION_OPTION => 1 ) );

		Plugin::finishPendingActivation();

		$this->assertSame( 'lingocanvas_pending_activation', Plugin::PENDING_ACTIVATION_OPTION );
		$this->assertArrayNotHasKey( Plugin::PENDING_ACTIVATION_OPTION, wp_mlp_test_options() );
		$this->assertArrayHasKey( Settings::OPTION, wp_mlp_test_options(), 'default settings are created' );
		$this->assertContains( 'dbDelta', wp_mlp_test_install_log() );
		$this->assertContains( 'flush', wp_mlp_test_install_log() );
	}

	/**
	 * Флаг снимается раньше установки: сбой не должен зациклить повторы на каждом запросе.
	 */
	public function testFlagIsClearedEvenWhenInstallFails(): void {
		wp_mlp_test_options( array( Plugin::PENDING_ACTIVATION_OPTION => 1 ) );
		unset( $GLOBALS['wpdb'] );

		try {
			Plugin::finishPendingActivation();
			$this->fail( 'Install without $wpdb is expected to throw.' );
		} catch ( \Throwable $e ) {
			$this->assertArrayNotHasKey( Plugin::PENDING_ACTIVATION_OPTION, wp_mlp_test_options() );
		}
	}

	/**
	 * Если флаг уже снял другой запрос, установка не повторяется.
	 */
	public function testFinishPendingActivationSkipsInstallWhenFlagAlreadyClaimed(): void {
		wp_mlp_test_options( array() );

		Plugin::finishPendingActivation();

		$this->assertArrayNotHasKey( Settings::OPTION, wp_mlp_test_options() );
		$this->assertNotContains( 'dbDelta', wp_mlp_test_install_log() );
		$this->assertNotContains( 'flush', wp_mlp_test_install_log() );
	}

	public function testActivateRunsTheSameInstallSteps(): void {
		Plugin::activate();

		$this->assertArrayHasKey( Settings::OPTION, wp_mlp_test_options() );
		$this->assertContains( 'dbDelta', wp_mlp_test_install_log() );
		$this->assertContains( 'flush', wp_mlp_test_install_log() );
	}
}
