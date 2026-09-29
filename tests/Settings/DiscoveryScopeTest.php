<?php
/**
 * Кто может пополнять список строк: владелец сайта или ещё и посетители.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Settings\Settings;

#[CoversClass( Settings::class )]
final class DiscoveryScopeTest extends TestCase {

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array() ) ) );
	}

	/**
	 * @param array<string, mixed> $settings Сохранённые настройки.
	 */
	private function settings( array $settings, bool $isAdmin ): Settings {
		wp_mlp_test_options( array( Settings::OPTION => $settings ) );
		wp_mlp_test_wp( array_merge( wp_mlp_test_wp(), array( 'caps' => array( 'manage_options' => $isAdmin ) ) ) );

		return new Settings();
	}

	public function testVisitorsDiscoveryIsOffByDefault(): void {
		$settings = $this->settings( array(), true );

		$this->assertFalse( $settings->isDiscoveryFromVisitorsEnabled() );
	}

	public function testSanitizeStoresTheVisitorsFlag(): void {
		$settings = new Settings();
		$base     = array(
			'default_locale' => 'ru',
			'languages'      => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
		);

		$off = $settings->sanitize( $base );
		$on  = $settings->sanitize( array_merge( $base, array( 'discover_from_visitors' => '1' ) ) );

		$this->assertFalse( $off['settings']['discover_from_visitors'] );
		$this->assertTrue( $on['settings']['discover_from_visitors'] );
	}

	public function testMasterSwitchOffDisablesEveryone(): void {
		$settings = $this->settings( array( 'discover_strings' => false, 'discover_from_visitors' => true ), true );

		$this->assertFalse( $settings->shouldDiscoverForCurrentUser() );
	}

	public function testVisitorIsIgnoredByDefault(): void {
		$settings = $this->settings( array( 'discover_strings' => true ), false );

		$this->assertFalse( $settings->shouldDiscoverForCurrentUser() );
	}

	public function testAdminIsCollected(): void {
		$settings = $this->settings( array( 'discover_strings' => true ), true );

		$this->assertTrue( $settings->shouldDiscoverForCurrentUser() );
	}

	public function testVisitorIsCollectedWhenFlagIsOn(): void {
		$settings = $this->settings( array( 'discover_strings' => true, 'discover_from_visitors' => true ), false );

		$this->assertTrue( $settings->shouldDiscoverForCurrentUser() );
	}
}
