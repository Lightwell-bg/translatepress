<?php
/**
 * Кто может пополнять список строк: владелец сайта или ещё и посетители.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Settings\Settings;

#[CoversClass( Settings::class )]
final class DiscoveryScopeTest extends TestCase {

	protected function tearDown(): void {
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( 'caps' => array() ) ) );
	}

	/**
	 * @param array<string, mixed> $settings Сохранённые настройки.
	 */
	private function settings( array $settings, bool $isAdmin ): Settings {
		lingocanvas_test_options( array( Settings::optionName() => $settings ) );
		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( 'caps' => array( 'manage_options' => $isAdmin ) ) ) );

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

	/**
	 * Поиск, 404, фид и предпросмотр не пополняют список строк даже при
	 * включённом сборе со страниц посетителей.
	 *
	 * @return array<string, array{string}>
	 */
	public static function noDiscoveryRequests(): array {
		return array(
			'search'  => array( 'is_search' ),
			'404'     => array( 'is_404' ),
			'feed'    => array( 'is_feed' ),
			'preview' => array( 'is_preview' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'noDiscoveryRequests' )]
	public function testSpecialRequestsAreNeverCollected( string $flag ): void {
		$settings = $this->settings( array( 'discover_strings' => true, 'discover_from_visitors' => true ), true );

		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( $flag => true ) ) );

		$this->assertFalse( $settings->shouldDiscoverForCurrentUser() );

		lingocanvas_test_wp( array_merge( lingocanvas_test_wp(), array( $flag => false ) ) );

		$this->assertTrue( $settings->shouldDiscoverForCurrentUser() );
	}

	public function testVisitorIsCollectedWhenFlagIsOn(): void {
		$settings = $this->settings( array( 'discover_strings' => true, 'discover_from_visitors' => true ), false );

		$this->assertTrue( $settings->shouldDiscoverForCurrentUser() );
	}
}
