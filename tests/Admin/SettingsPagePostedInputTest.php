<?php
/**
 * Тесты явного списка полей формы настроек (SettingsPage::postedInput()).
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use LingoCanvas\Admin\SettingsPage;
use LingoCanvas\I18n\LanguagePacks;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Translation\ProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SettingsPage::class )]
final class SettingsPagePostedInputTest extends TestCase {

	/**
	 * Исходное содержимое $_POST (тесты его подменяют).
	 *
	 * @var array<string, mixed>
	 */
	private array $originalPost = array();

	protected function setUp(): void {
		$this->originalPost = $_POST;
		lingocanvas_test_options( array( Settings::optionName() => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		$_POST = $this->originalPost;
		lingocanvas_test_options( array() );
	}

	private function page(): SettingsPage {
		$settings = new Settings();

		return new SettingsPage( $settings, new LanguagePacks(), new ProviderFactory( $settings, static fn( string $name ): ?string => null ) );
	}

	public function testUnknownPostKeysAreNotPassedOn(): void {
		$_POST = array(
			'action'           => 'lingocanvas_save_settings',
			'_wpnonce'         => 'abc',
			'evil'             => 'x',
			'default_locale'   => 'ru',
			'admin_password'   => 'secret',
			'languages'        => array( array( 'locale' => 'ru', 'unknown_row_key' => 'x' ) ),
		);

		$input = $this->page()->postedInput();

		$this->assertSame( array( 'default_locale', 'languages' ), array_keys( $input ) );
		$this->assertSame( array( 0 => array( 'locale' => 'ru' ) ), $input['languages'] );
	}

	public function testTagsAreStrippedAndSlashesRemoved(): void {
		$_POST = array(
			'openai_model' => ' gpt-<b>4</b>o ',
			'languages'    => array(
				3 => array(
					'locale'    => 'de',
					'label'     => 'Deut<script>alert(1)</script>sch \\"x\\"',
					'status'    => 'DRAFT<i>',
					'flag'      => '<img src=x>',
					'wp_locale' => 'de_DE',
				),
			),
		);

		$input = $this->page()->postedInput();

		$this->assertSame( 'gpt-4o', $input['openai_model'] );
		// Ключ строки (номер для flag_file_3) сохранён.
		$this->assertArrayHasKey( 3, $input['languages'] );
		$this->assertSame( 'Deutsch "x"', $input['languages'][3]['label'] );
		$this->assertSame( 'draft', $input['languages'][3]['status'] );
		$this->assertSame( '', $input['languages'][3]['flag'] );
	}

	public function testArraysInScalarFieldsAndNonArrayRowsAreIgnored(): void {
		$_POST = array(
			'default_locale'   => array( 'ru' ),
			'openai_api_key'   => array( 'k' ),
			'languages'        => array(
				'not-a-row',
				array( 'locale' => array( 'ru' ), 'slug' => 'ru' ),
			),
		);

		$input = $this->page()->postedInput();

		$this->assertArrayNotHasKey( 'default_locale', $input );
		$this->assertArrayNotHasKey( 'openai_api_key', $input );
		$this->assertSame( array( 1 => array( 'slug' => 'ru' ) ), $input['languages'] );
	}

	public function testSitemapTextareaKeepsLinesAndDecodesPercentEncodedSlugs(): void {
		$_POST = array( 'sitemap_excluded_slugs' => "checkout\r\n%d0%bf%d1%80\n<b>cart</b>" );

		$input = $this->page()->postedInput();

		$this->assertStringContainsString( "\n", $input['sitemap_excluded_slugs'] );
		$this->assertStringContainsString( 'checkout', $input['sitemap_excluded_slugs'] );
		$this->assertStringContainsString( 'cart', $input['sitemap_excluded_slugs'] );
		$this->assertStringNotContainsString( '<b>', $input['sitemap_excluded_slugs'] );
		// Процентное кодирование не вырезано, а декодировано: Settings::sanitize() закодирует слаг заново.
		$this->assertStringContainsString( 'пр', $input['sitemap_excluded_slugs'] );
	}

	public function testPostedInputFeedsSettingsSanitizeWithTheSameShapeAsBefore(): void {
		$_POST = array(
			'default_locale'           => 'ru',
			'switcher_display'         => 'flag',
			'discover_strings'         => '1',
			'delete_data_on_uninstall' => '1',
			'untranslated_posts_mode'  => 'manual',
			'openai_daily_char_limit'  => '5000',
			'openai_api_key'           => 'sk-test-123',
			'openai_model'             => 'gpt-4o-mini',
			'openai_base_url'          => 'https://proxy.example.com/v1/',
			'sitemap_excluded_slugs'   => "Cart\ncheckout",
			'languages'                => array(
				array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published', 'wp_locale' => 'ru_RU' ),
				array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'draft', 'wp_locale' => 'en_US' ),
			),
		);

		$result   = ( new Settings() )->sanitize( $this->page()->postedInput() );
		$settings = $result['settings'];

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'ru', $settings['default_locale'] );
		$this->assertSame( 'flag', $settings['switcher_display'] );
		$this->assertTrue( $settings['discover_strings'] );
		$this->assertFalse( $settings['discover_from_visitors'] );
		$this->assertTrue( $settings['delete_data_on_uninstall'] );
		$this->assertSame( 'manual', $settings['untranslated_posts_mode'] );
		$this->assertSame( 5000, $settings['openai_daily_char_limit'] );
		$this->assertSame( 'sk-test-123', $settings['openai_api_key'] );
		$this->assertSame( 'https://proxy.example.com/v1', $settings['openai_base_url'] );
		$this->assertSame( array( 'cart', 'checkout' ), $settings['sitemap_excluded_slugs'] );
		$this->assertSame( 'Русский', $settings['languages']['ru']['label'] );
		$this->assertSame( 'draft', $settings['languages']['en']['status'] );
	}
}
