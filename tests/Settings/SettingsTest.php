<?php
/**
 * Тесты разбора и очистки настроек.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;

#[CoversClass( Settings::class )]
#[CoversClass( Language::class )]
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options( array( Settings::OPTION => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
	}

	public function testDefaultLanguageComesFirst(): void {
		$settings = new Settings();

		$this->assertSame( 'ru', $settings->defaultLanguage()->locale );
		$this->assertSame( 'ru', (string) array_key_first( $settings->all() ) );
	}

	public function testSecondaryExcludesDefault(): void {
		$settings = new Settings();

		$this->assertSame( array( 'en' ), array_keys( $settings->secondary() ) );
	}

	/**
	 * Язык по умолчанию отдаётся без префикса, поэтому по слагу он находиться
	 * не должен: иначе `/ru/about/` считался бы нормальным адресом.
	 */
	public function testPublishedBySlugIgnoresDefaultLanguage(): void {
		$settings = new Settings();

		$this->assertNotNull( $settings->publishedBySlug( 'en' ) );
		$this->assertNull( $settings->publishedBySlug( 'ru' ) );
		$this->assertNotNull( $settings->anyBySlug( 'ru' ) );
	}

	public function testDraftLanguageIsNotPublished(): void {
		$raw                                    = Settings::defaults();
		$raw['languages']['en']['status']       = Language::STATUS_DRAFT;
		wp_mlp_test_options( array( Settings::OPTION => $raw ) );

		$settings = new Settings();

		$this->assertNull( $settings->publishedBySlug( 'en' ) );
		$this->assertSame( array( 'ru' ), array_keys( $settings->published() ) );
	}

	public function testSanitizeRejectsInvalidLocale(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
					),
					array(
						'locale' => 'en; DROP TABLE wp_posts',
						'slug'   => 'en',
					),
				),
			)
		);

		$this->assertArrayNotHasKey( 'en; drop table wp_posts', $result['settings']['languages'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function testSanitizeRejectsDuplicateSlug(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
					),
					array(
						'locale' => 'en',
						'slug'   => 'x',
					),
					array(
						'locale' => 'de',
						'slug'   => 'x',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'en', $result['settings']['languages'] );
		$this->assertArrayNotHasKey( 'de', $result['settings']['languages'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function testSanitizeForcesDefaultLanguagePublished(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
						'status' => Language::STATUS_DRAFT,
					),
				),
			)
		);

		$this->assertSame(
			Language::STATUS_PUBLISHED,
			$result['settings']['languages']['ru']['status']
		);
	}

	public function testSanitizeDropsDeletedLanguage(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
					),
					array(
						'locale' => 'en',
						'slug'   => 'en',
						'delete' => '1',
					),
				),
			)
		);

		$this->assertSame( array( 'ru' ), array_keys( $result['settings']['languages'] ) );
	}

	/**
	 * Настройки без языка по умолчанию сделали бы сайт недоступным,
	 * поэтому прежнее значение возвращается на место.
	 */
	public function testSanitizeKeepsDefaultLanguageWhenItIsMissing(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'fr',
				'languages'      => array(
					array(
						'locale' => 'en',
						'slug'   => 'en',
					),
				),
			)
		);

		$this->assertSame( 'ru', $result['settings']['default_locale'] );
		$this->assertArrayHasKey( 'ru', $result['settings']['languages'] );
		$this->assertNotEmpty( $result['errors'] );
	}
}
