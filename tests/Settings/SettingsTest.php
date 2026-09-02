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

	public function testDefaultLanguageIsFoundByItsFlagNotByPosition(): void {
		$settings = new Settings();

		$this->assertSame( 'ru', $settings->defaultLanguage()->locale );
	}

	/**
	 * Порядок языков задаёт владелец сайта — стрелками в таблице настроек,
	 * а хранится он самим порядком записей в опции. Раньше здесь стояла
	 * жёсткая сортировка (сначала язык по умолчанию, дальше по алфавиту
	 * кода), и переставить языки в переключателе было нельзя вовсе.
	 */
	public function testLanguageOrderFollowsSettings(): void {
		$raw              = Settings::defaults();
		$raw['languages'] = array(
			'en' => $raw['languages']['en'],
			'ru' => $raw['languages']['ru'],
		);

		wp_mlp_test_options( array( Settings::OPTION => $raw ) );

		$settings = new Settings();

		$this->assertSame( array( 'en', 'ru' ), array_keys( $settings->all() ) );

		// Язык по умолчанию остаётся собой, где бы ни стоял.
		$this->assertSame( 'ru', $settings->defaultLanguage()->locale );
		$this->assertSame( array( 'en' ), array_keys( $settings->secondary() ) );
	}

	/**
	 * Порядок переживает сохранение формы: sanitize() складывает языки в
	 * том порядке, в каком их прислал браузер, а он идёт по порядку строк
	 * таблицы.
	 */
	public function testSanitizeKeepsTheSubmittedOrder(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array( 'locale' => 'bg', 'slug' => 'bg', 'label' => 'Bulgarian' ),
					array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский' ),
					array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English' ),
				),
			)
		);

		$this->assertSame(
			array( 'bg', 'ru', 'en' ),
			array_keys( $result['settings']['languages'] )
		);
		$this->assertSame( array(), $result['errors'] );
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

	/**
	 * Самое важное для обновления плагина: у языков, заведённых ДО
	 * появления поля «Локаль WordPress», в настройках его нет вовсе.
	 * Такой язык обязан получить разумную локаль сам, а не пустую строку —
	 * иначе подмена локали (LocaleSwitcher) на нём просто не сработает,
	 * причём молча.
	 */
	public function testLanguageWithoutStoredWpLocaleDerivesItFromTheCode(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						// Ровно то, что лежит в БД у существующих установок:
						// ни ключа wp_locale, ни его значения.
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						'bg' => array( 'locale' => 'bg', 'slug' => 'bg', 'status' => 'published' ),
					),
				),
			)
		);

		$settings = new Settings();

		$this->assertSame( 'ru_RU', $settings->get( 'ru' )->wpLocale );
		$this->assertSame( 'bg_BG', $settings->get( 'bg' )->wpLocale );
	}

	/**
	 * Сохранённое значение всегда сильнее вычисленного: обновление плагина
	 * не должно молча перетирать локаль, которую владелец сайта поправил
	 * руками (например `en_GB` вместо угаданного `en_US`).
	 */
	public function testStoredWpLocaleWinsOverTheDerivedDefault(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						'en' => array(
							'locale'    => 'en',
							'slug'      => 'en',
							'status'    => 'published',
							'wp_locale' => 'en_GB',
						),
					),
				),
			)
		);

		$this->assertSame( 'en_GB', ( new Settings() )->get( 'en' )->wpLocale );
	}

	public function testWpLocaleSurvivesRoundTrip(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale'    => 'ru',
						'slug'      => 'ru',
						'wp_locale' => 'ru_RU',
					),
				),
			)
		);

		$this->assertSame( 'ru_RU', $result['settings']['languages']['ru']['wp_locale'] );
	}

	/**
	 * Пустое поле — не ошибка, а «выведи сама»: у существующих языков его
	 * ещё не заполняли, и сохранение других настроек не должно падать.
	 */
	public function testBlankWpLocaleIsAcceptedAndDerivedLater(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale'    => 'ru',
						'slug'      => 'ru',
						'wp_locale' => '',
					),
				),
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( '', $result['settings']['languages']['ru']['wp_locale'] );
		// …а на чтении пустое значение превращается в выведенное.
		$this->assertSame( 'ru_RU', Language::fromArray( $result['settings']['languages']['ru'], true )->wpLocale );
	}

	public function testInvalidWpLocaleIsRejectedWithAnError(): void {
		$settings = new Settings();

		$result = $settings->sanitize(
			array(
				'default_locale' => 'ru',
				'languages'      => array(
					array(
						'locale'    => 'ru',
						'slug'      => 'ru',
						'wp_locale' => '1',
					),
				),
			)
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( '', $result['settings']['languages']['ru']['wp_locale'] );
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

	/**
	 * Жалоба на карту сайта (переписка 08.08.2026): вручную созданные
	 * страницы вроде «Оформление заказа» не отличить от обычного контента
	 * программно — единственный способ убрать их из карты сайта это явный
	 * список слагов в настройках, по одному на строку textarea.
	 */
	public function testSanitizeParsesSitemapExcludedSlugsOnePerLine(): void {
		$settings = new Settings();

		$result = $this->sanitizeWithSlugsField( $settings, "oformlenie-zakaza\r\nnet-dostupa\n\ntovary" );

		$this->assertSame(
			array( 'oformlenie-zakaza', 'net-dostupa', 'tovary' ),
			$result['settings']['sitemap_excluded_slugs']
		);
	}

	/**
	 * Слаг переживает копирование из адресной строки как есть: обрамляющие
	 * пробелы, слеши и разный регистр не должны давать отдельные записи,
	 * иначе `post_name` (всегда в нижнем регистре, без слешей) с ним никогда
	 * не совпадёт.
	 */
	public function testSanitizeNormalizesSlugsCaseSlashesAndWhitespace(): void {
		$settings = new Settings();

		$result = $this->sanitizeWithSlugsField( $settings, "  /Oformlenie-Zakaza/  \n oformlenie-zakaza \n" );

		$this->assertSame( array( 'oformlenie-zakaza' ), $result['settings']['sitemap_excluded_slugs'] );
	}

	public function testSanitizeEmptySlugsFieldGivesEmptyList(): void {
		$settings = new Settings();

		$result = $this->sanitizeWithSlugsField( $settings, '' );

		$this->assertSame( array(), $result['settings']['sitemap_excluded_slugs'] );
	}

	/**
	 * Уже сохранённый список остаётся в raw()/sitemapExcludedSlugs() ровно
	 * таким, каким его отдал sanitize() — без повторной нормализации при
	 * каждом чтении.
	 */
	public function testSitemapExcludedSlugsGetterReadsStoredValue(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array_merge(
					Settings::defaults(),
					array( 'sitemap_excluded_slugs' => array( 'oformlenie-zakaza', 'net-dostupa' ) )
				),
			)
		);

		$settings = new Settings();

		$this->assertSame( array( 'oformlenie-zakaza', 'net-dostupa' ), $settings->sitemapExcludedSlugs() );
	}

	/**
	 * @param string $rawTextarea Содержимое textarea, как его прислал бы браузер.
	 * @return array{settings: array<string, mixed>, errors: list<string>}
	 */
	private function sanitizeWithSlugsField( Settings $settings, string $rawTextarea ): array {
		return $settings->sanitize(
			array(
				'default_locale'         => 'ru',
				'languages'              => array(
					array(
						'locale' => 'ru',
						'slug'   => 'ru',
					),
				),
				'sitemap_excluded_slugs' => $rawTextarea,
			)
		);
	}
}
