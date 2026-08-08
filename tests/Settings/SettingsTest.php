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

	public function testHidingUntranslatedPostsIsOnByDefault(): void {
		$this->assertTrue( ( new Settings() )->hidesUntranslatedPosts() );
	}

	/**
	 * Снятая галочка приходит из формы как отсутствующее поле, а не как
	 * пустое значение — иначе выключить настройку было бы невозможно.
	 */
	public function testUncheckedBoxTurnsHidingOff(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
					),
				),
			)
		);

		$this->assertFalse( $result['settings']['hide_untranslated_posts'] );
	}

	public function testCheckedBoxTurnsHidingOn(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale'          => 'ru',
				'hide_untranslated_posts' => '1',
				'languages'               => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
					),
				),
			)
		);

		$this->assertTrue( $result['settings']['hide_untranslated_posts'] );
	}

	public function testFlagSurvivesRoundTrip(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
						'flag'   => '🇷🇺',
					),
				),
			)
		);

		$this->assertSame( '🇷🇺', $result['settings']['languages']['ru']['flag'] );
	}

	public function testFlagFieldStripsTagsAndTrimsLength(): void {
		// wp_strip_all_tags() вырезает <script>/<style> целиком, вместе с
		// содержимым, — иначе поле «флаг» стало бы местом, куда можно
		// вписать код и получить его как есть после «очистки».
		$this->assertSame( '', Language::sanitizeFlag( '<script>alert(1)</script>' ) );
		// Обычный тег вырезается, а текст внутри — нет: это не script/style.
		$this->assertSame( '🇷🇺', Language::sanitizeFlag( '<b>🇷🇺</b>' ) );
		$this->assertSame(
			str_repeat( 'a', 16 ),
			Language::sanitizeFlag( str_repeat( 'a', 40 ) )
		);
	}

	public function testLabelWithFlagPrependsFlagWhenPresent(): void {
		$withFlag = new Language( 'en', 'en', 'English', Language::STATUS_PUBLISHED, false, '🇬🇧' );
		$noFlag   = new Language( 'en', 'en', 'English', Language::STATUS_PUBLISHED, false );

		$this->assertSame( '🇬🇧 English', $withFlag->labelWithFlag() );
		$this->assertSame( 'English', $noFlag->labelWithFlag() );
	}

	/**
	 * Поле ключа — `type="password"` и никогда не приходит предзаполненным
	 * текущим значением. Пустое поле должно означать «не менять», иначе ключ
	 * стирался бы при каждом сохранении любых других настроек формы.
	 */
	public function testBlankApiKeyFieldKeepsExistingKey(): void {
		$stored                     = Settings::defaults();
		$stored['openai_api_key']   = 'sk-existing';
		wp_mlp_test_options( array( Settings::OPTION => $stored ) );

		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale'  => 'ru',
				'openai_api_key'  => '',
				'languages'       => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$this->assertSame( 'sk-existing', $result['settings']['openai_api_key'] );
	}

	public function testNonEmptyApiKeyFieldReplacesExistingKey(): void {
		$stored                   = Settings::defaults();
		$stored['openai_api_key'] = 'sk-old';
		wp_mlp_test_options( array( Settings::OPTION => $stored ) );

		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'openai_api_key' => 'sk-new',
				'languages'      => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$this->assertSame( 'sk-new', $result['settings']['openai_api_key'] );
	}

	public function testExplicitClearCheckboxWipesTheKeyEvenIfFieldIsBlank(): void {
		$stored                   = Settings::defaults();
		$stored['openai_api_key'] = 'sk-existing';
		wp_mlp_test_options( array( Settings::OPTION => $stored ) );

		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale'        => 'ru',
				'openai_api_key'        => '',
				'openai_api_key_clear'  => '1',
				'languages'             => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$this->assertSame( '', $result['settings']['openai_api_key'] );
	}

	public function testBaseUrlFallsBackToDefaultWhenBlankOrInvalid(): void {
		$settings = new Settings();

		$blank = $settings->sanitize(
			array(
				'default_locale'  => 'ru',
				'openai_base_url' => '',
				'languages'       => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$invalid = $settings->sanitize(
			array(
				'default_locale'  => 'ru',
				'openai_base_url' => 'javascript:alert(1)',
				'languages'       => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$this->assertSame( Settings::DEFAULT_OPENAI_BASE_URL, $blank['settings']['openai_base_url'] );
		$this->assertSame( Settings::DEFAULT_OPENAI_BASE_URL, $invalid['settings']['openai_base_url'] );
	}

	public function testBaseUrlTrailingSlashIsRemoved(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale'  => 'ru',
				'openai_base_url' => 'https://example.com/v1/',
				'languages'       => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$this->assertSame( 'https://example.com/v1', $result['settings']['openai_base_url'] );
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
