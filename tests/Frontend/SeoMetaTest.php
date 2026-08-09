<?php
/**
 * Тесты правки SEO-метатегов под текущий язык.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\SeoMeta;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

#[CoversClass( SeoMeta::class )]
final class SeoMetaTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'status' => 'published' ),
					),
				),
				'home' => 'https://site.ru',
			)
		);

		$_SERVER['REQUEST_URI'] = '/en/about/';
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		unset( $_SERVER['REQUEST_URI'] );
	}

	private function seoMeta(): SeoMeta {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );

		return new SeoMeta( $settings, $resolver, new UrlConverter( $settings, $resolver ) );
	}

	private function target(): \WpMlp\Settings\Language {
		return ( new Settings() )->get( 'en' );
	}

	public function testOgUrlAndTwitterUrlAreSetToLanguageAddress(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<meta property="og:url" content="https://site.ru/about/">'
			. '<meta name="twitter:url" content="https://site.ru/about/">'
			. '</head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString( 'https://site.ru/en/about/', $document->html() );
		$this->assertStringNotContainsString( 'content="https://site.ru/about/"', $document->html() );
	}

	public function testOgLocaleMatchesTargetLanguage(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><meta property="og:locale" content="ru"></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		// Полный код Open Graph (xx_XX), не голое "en" — иначе значение
		// невалидно по документации Facebook.
		$this->assertStringContainsString( 'og:locale" content="en_US"', $document->html() );
	}

	public function testAlternateLocalesAreRebuiltForRemainingLanguages(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<meta property="og:locale" content="ru">'
			. '<meta property="og:locale:alternate" content="stale_value">'
			. '</head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$html = $document->html();

		$this->assertStringNotContainsString( 'stale_value', $html );
		$this->assertStringContainsString( 'og:locale:alternate" content="ru_RU"', $html );
	}

	/**
	 * og:image никогда не трогается: если у SEO-плагина уже стоит изображение
	 * статьи, оно должно остаться ровно тем же на любом языке.
	 */
	public function testOgImageIsNeverTouched(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<meta property="og:image" content="https://site.ru/wp-content/uploads/2026/article-photo.jpg">'
			. '</head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString(
			'og:image" content="https://site.ru/wp-content/uploads/2026/article-photo.jpg"',
			$document->html()
		);
	}

	public function testJsonLdInLanguageMatchesTarget(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","inLanguage":"ru"}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString( '"inLanguage":"en"', $document->html() );
	}

	/**
	 * Стабильная сущность (Organization) не должна сменить @id при переводе:
	 * это её постоянный идентификатор, одинаковый на всех языках сайта.
	 */
	public function testStableEntityIdIsNeverChanged(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/#organization","url":"https://site.ru/"}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString( '"@id":"https://site.ru/#organization"', $document->html() );
	}

	/**
	 * Адрес картинки в ImageObject не должен получить языковой префикс —
	 * файл в wp-content/uploads один на все языки, `/en/wp-content/...` не
	 * существует.
	 */
	public function testImageObjectUrlIsNotPrefixed(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","image":{"@type":"ImageObject","url":"https://site.ru/wp-content/uploads/photo.jpg"}}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString(
			'"url":"https://site.ru/wp-content/uploads/photo.jpg"',
			$document->html()
		);
	}

	/**
	 * Воспроизводит настоящую причину бага из жалобы: SEO-плагин строит `@id`
	 * через `home_url()`, а этот вызов уже проходит через наш собственный
	 * фильтр языкового префикса раньше, чем страница попадает в SeoMeta.
	 * К моменту разбора DOM `@id` уже содержит чужой префикс — тест
	 * проверяет, что SeoMeta возвращает его к виду без префикса, а не
	 * просто оставляет как есть (что было бы недостаточно).
	 */
	public function testStableEntityIdWithBakedInPrefixIsNormalized(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/en/#organization"}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString( '"@id":"https://site.ru/#organization"', $document->html() );
		$this->assertStringNotContainsString( '/en/#organization', $document->html() );
	}

	/**
	 * То же самое, но на языке по умолчанию: там `home_url()` не добавляет
	 * префикс вовсе, поэтому нормализация — no-op, а не порча значения.
	 */
	public function testStableEntityIdIsUntouchedOnDefaultLanguage(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/#organization"}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, ( new Settings() )->get( 'ru' ) );

		$this->assertStringContainsString( '"@id":"https://site.ru/#organization"', $document->html() );
	}

	/**
	 * Пункт 2 жалобы, воспроизведён буквально: сайтовый логотип
	 * (`Organization.logo`, тип `ImageObject`) не должен менять `@id` между
	 * языками, хотя `ImageObject` сам по себе ни стабилен, ни страницезависим
	 * (см. JsonLdRulesTest::testUnknownTypeIsNeitherStableNorPageScoped) —
	 * решает не тип, а то, что на него ссылаются как на `logo`.
	 */
	public function testLogoImageObjectIdIsStableAcrossLanguages(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/#organization",'
			. '"logo":{"@type":"ImageObject","@id":"https://site.ru/#logo","url":"https://site.ru/logo.png"}}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString( '"@id":"https://site.ru/#logo"', $document->html() );
	}

	/**
	 * Тот же случай, что и у Organization/Person/WebSite: SEO-плагин строит
	 * `@id` логотипа через `home_url()`, который уже прошёл через наш
	 * фильтр языкового префикса до того, как страница попала в SeoMeta —
	 * значение приходит С чужим префиксом и должно быть возвращено к виду
	 * без него, а не просто оставлено как есть.
	 */
	public function testLogoImageObjectIdWithBakedInPrefixIsNormalized(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/en/#organization",'
			. '"logo":{"@type":"ImageObject","@id":"https://site.ru/en/#logo","url":"https://site.ru/logo.png"}}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$html = $document->html();

		$this->assertStringContainsString( '"@id":"https://site.ru/#logo"', $html );
		$this->assertStringNotContainsString( '/en/#logo', $html );
	}

	/**
	 * Пункт 4 жалобы: логотип и изображение самой записи — оба `ImageObject`,
	 * но разной природы. Стабилизация `@id` логотипа (пункт 2) не должна
	 * задеть `@id`/`url` изображения записи — оно как было, так и остаётся
	 * нетронутым (страницезависимого типа `ImageObject` у нас нет, и это
	 * осознанный выбор: см. JsonLdRules — файл в uploads один на все языки).
	 */
	public function testArticleOwnImageIsUnaffectedByLogoStabilization(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@context":"https://schema.org","@graph":['
			. '{"@type":"Organization","@id":"https://site.ru/#organization",'
			. '"logo":{"@type":"ImageObject","@id":"https://site.ru/#logo","url":"https://site.ru/logo.png"}},'
			. '{"@type":"Article","@id":"https://site.ru/about/#article",'
			. '"image":{"@type":"ImageObject","@id":"https://site.ru/about/#primaryimage","url":"https://site.ru/wp-content/uploads/photo.jpg"}}'
			. ']}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$html = $document->html();

		// Логотип — стабилен (пункт 2).
		$this->assertStringContainsString( '"@id":"https://site.ru/#logo"', $html );

		// Изображение самой записи — нетронуто: ни @id, ни url не поменялись.
		$this->assertStringContainsString( '"@id":"https://site.ru/about/#primaryimage"', $html );
		$this->assertStringContainsString( '"url":"https://site.ru/wp-content/uploads/photo.jpg"', $html );
	}

	/**
	 * Пункт 4 жалобы: og:image никогда не трогается плагином, даже когда та
	 * же страница несёт логотип со стабилизированным `@id`, — оба механизма
	 * независимы, один не должен просачиваться в другой.
	 */
	public function testOgImageStaysUntouchedWhenLogoIsAlsoPresent(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<meta property="og:image" content="https://site.ru/wp-content/uploads/2026/article-photo.jpg">'
			. '<script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/#organization",'
			. '"logo":{"@type":"ImageObject","@id":"https://site.ru/#logo","url":"https://site.ru/logo.png"}}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString(
			'og:image" content="https://site.ru/wp-content/uploads/2026/article-photo.jpg"',
			$document->html()
		);
	}

	public function testWebPageUrlDoesGetPrefixed(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"WebPage","url":"https://site.ru/about/"}'
			. '</script></head><body></body></html>'
		);

		$this->assertNotNull( $document );

		$this->seoMeta()->apply( $document, $this->target() );

		$this->assertStringContainsString( '"url":"https://site.ru/en/about/"', $document->html() );
	}
}
