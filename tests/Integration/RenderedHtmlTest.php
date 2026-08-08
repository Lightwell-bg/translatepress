<?php
/**
 * Интеграционный тест: итоговый HTML на всех языках сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\InternalLinks;
use WpMlp\Frontend\SeoMeta;
use WpMlp\Frontend\SeoTags;
use WpMlp\Rendering\DocumentFilter;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;

/**
 * Юнит-тесты остальных классов проверяют каждый фильтр по отдельности.
 * Здесь — вся цепочка `Translator::runFilters()` (SeoTags → SeoMeta →
 * InternalLinks, ровно тот порядок, что в Plugin.php) на одной и той же
 * странице, показанной на всех трёх языках сайта: русский (по умолчанию,
 * без префикса), болгарский (`/bg/`) и английский (`/en/`) — установка
 * живёт в подкаталоге `/blog`, как в жалобе. Это ловит именно то, что не
 * ловят изолированные тесты: регрессии на стыке фильтров (переключатель
 * языков, собранный NavMenu/LanguageSwitcher, сломанный вторым проходом
 * InternalLinks) и баги, которые проявляются только при ненулевом basePath.
 */
#[CoversNothing]
final class RenderedHtmlTest extends TestCase {

	private const HOME = 'https://site.example/blog';

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		unset( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Собирает окружение для одного языка: свежие Settings/Resolver/UrlConverter
	 * и REQUEST_URI, как если бы этот язык сейчас показывался посетителю.
	 *
	 * @param string $slug Слаг языка в пути ('' для языка по умолчанию).
	 * @return array{settings: Settings, resolver: LanguageResolver, urls: UrlConverter, target: Language}
	 */
	private function requestFor( string $slug ): array {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						'bg' => array( 'locale' => 'bg', 'slug' => 'bg', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'status' => 'published' ),
					),
				),
				'home' => self::HOME,
			)
		);

		$_SERVER['REQUEST_URI'] = '/blog' . ( '' !== $slug ? '/' . $slug : '' ) . '/about/';

		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$urls     = new UrlConverter( $settings, $resolver );

		return array(
			'settings' => $settings,
			'resolver' => $resolver,
			'urls'     => $urls,
			'target'   => $resolver->current(),
		);
	}

	/**
	 * @return list<DocumentFilter>
	 */
	private function filters( Settings $settings, LanguageResolver $resolver, UrlConverter $urls ): array {
		return array(
			new SeoTags( $settings, $resolver, $urls ),
			new SeoMeta( $settings, $resolver, $urls ),
			new InternalLinks( $urls ),
		);
	}

	/**
	 * Переключатель языков — три ссылки, ровно как их строит NavMenu/
	 * LanguageSwitcher: класс-маркер прямо на `<a>`, адрес — абсолютный,
	 * посчитанный для ТЕКУЩЕГО запроса (значит одинаковый на всех трёх
	 * проходах, поскольку switchUrlFor() сперва снимает язык с пути).
	 *
	 * @param UrlConverter $urls Построение языковых адресов для текущего запроса.
	 */
	private function switcherMarkup( UrlConverter $urls, Settings $settings ): string {
		$html = '<ul class="mlp-language-switcher">';

		foreach ( $settings->published() as $language ) {
			$html .= sprintf(
				'<li><a class="mlp-language-item" href="%s">%s</a></li>',
				$urls->switchUrlFor( $language ),
				$language->locale
			);
		}

		return $html . '</ul>';
	}

	/**
	 * Полная страница: голова с SEO/JSON-LD, переключатель и футер темы
	 * с ссылкой, написанной буквальным `<a href>` — источник исходной жалобы.
	 *
	 * @param array{settings: Settings, resolver: LanguageResolver, urls: UrlConverter, target: Language} $request
	 */
	private function page( array $request ): string {
		$urls   = $request['urls'];
		$target = $request['target'];

		// Как если бы SEO-плагин собрал `@id` через home_url() ИМЕННО на
		// этом запросе — с уже вклеенным префиксом текущего языка.
		$organizationId = $urls->absolute( '/#organization', $target );

		// А это адрес страницы в WebPage.url — исходное, ещё не локализованное
		// значение, как в существующем юнит-тесте SeoMeta.
		$webPageUrl = self::HOME . '/about/';

		/*
		 * JSON_UNESCAPED_SLASHES здесь намеренно, а не через wp_json_encode():
		 * если ни один фильтр ничего не поменяет (например `@id` уже без
		 * префикса на языке по умолчанию), JsonLdDocument::flush() ни разу не
		 * вызовется, и в ответе останется этот, исходный JSON как есть —
		 * сравнивать его с ожиданиями нужно в том же виде, в каком его
		 * пересобирает flush() (см. её собственный JSON_UNESCAPED_SLASHES).
		 */
		$jsonLd = json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array(
						'@type' => 'Organization',
						'@id'   => $organizationId,
					),
					array(
						'@type' => 'WebPage',
						'url'   => $webPageUrl,
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		);

		return '<!DOCTYPE html><html><head>'
			. '<link rel="canonical" href="' . self::HOME . '/about/">'
			. '<meta property="og:url" content="' . self::HOME . '/about/">'
			. '<meta property="og:locale" content="ru_RU">'
			. '<script type="application/ld+json">' . $jsonLd . '</script>'
			. '</head><body>'
			. '<nav>' . $this->switcherMarkup( $urls, $request['settings'] ) . '</nav>'
			. '<footer><a href="/blog/kontakty/">Контакты</a></footer>'
			. '</body></html>';
	}

	/**
	 * Прогоняет страницу через полную цепочку фильтров одного языка.
	 *
	 * @param string $slug Слаг языка в пути ('' для языка по умолчанию).
	 * @return array{html: string, request: array{settings: Settings, resolver: LanguageResolver, urls: UrlConverter, target: Language}}
	 */
	private function render( string $slug ): array {
		$request  = $this->requestFor( $slug );
		$document = HtmlDocument::parse( $this->page( $request ) );

		$this->assertNotNull( $document );

		foreach ( $this->filters( $request['settings'], $request['resolver'], $request['urls'] ) as $filter ) {
			$filter->apply( $document, $request['target'] );
		}

		return array(
			'html'    => $document->html(),
			'request' => $request,
		);
	}

	public function testRussianDefaultLanguageRoutesWithoutPrefix(): void {
		$html = $this->render( '' )['html'];

		$this->assertStringContainsString( 'href="https://site.example/blog/about/"', $html );
		$this->assertStringContainsString( 'href="/blog/kontakty/"', $html );
	}

	public function testBulgarianRoutesUnderBgPrefix(): void {
		$html = $this->render( 'bg' )['html'];

		$this->assertStringContainsString( 'href="https://site.example/blog/bg/about/"', $html );
		$this->assertStringContainsString( 'href="/blog/bg/kontakty/"', $html );
	}

	public function testEnglishRoutesUnderEnPrefix(): void {
		$html = $this->render( 'en' )['html'];

		$this->assertStringContainsString( 'href="https://site.example/blog/en/about/"', $html );
		$this->assertStringContainsString( 'href="/blog/en/kontakty/"', $html );
	}

	/**
	 * Ключевая регрессия из жалобы: на английской странице переключатель
	 * должен по-прежнему предлагать русский БЕЗ префикса и болгарский с
	 * `/bg/`, а не превращаться в три ссылки на английский.
	 */
	public function testLanguageSwitcherOffersAllThreeLanguagesFromEveryPage(): void {
		foreach ( array( '', 'bg', 'en' ) as $slug ) {
			$html = $this->render( $slug )['html'];

			$this->assertStringContainsString( 'href="https://site.example/blog/about/"', $html, "ru switcher link missing on '$slug'" );
			$this->assertStringContainsString( 'href="https://site.example/blog/bg/about/"', $html, "bg switcher link missing on '$slug'" );
			$this->assertStringContainsString( 'href="https://site.example/blog/en/about/"', $html, "en switcher link missing on '$slug'" );
		}
	}

	public function testOgLocaleMatchesEachLanguage(): void {
		$this->assertStringContainsString( 'og:locale" content="ru"', $this->render( '' )['html'] );
		$this->assertStringContainsString( 'og:locale" content="bg"', $this->render( 'bg' )['html'] );
		$this->assertStringContainsString( 'og:locale" content="en"', $this->render( 'en' )['html'] );
	}

	/**
	 * `@id` уже пришёл в HTML с чужим префиксом (см. page()) — на всех трёх
	 * языках он обязан свестись к одному и тому же адресу без префикса.
	 */
	public function testStableOrganizationIdIsIdenticalOnEveryLanguage(): void {
		$expected = 'https://site.example/blog/#organization';

		foreach ( array( '', 'bg', 'en' ) as $slug ) {
			$html = $this->render( $slug )['html'];

			$this->assertStringContainsString( '"@id":"' . $expected . '"', $html, "wrong @id on '$slug'" );
		}
	}

	public function testWebPageUrlIsLocalizedOnSecondaryLanguagesOnly(): void {
		$this->assertStringContainsString( '"url":"https://site.example/blog/about/"', $this->render( '' )['html'] );
		$this->assertStringContainsString( '"url":"https://site.example/blog/bg/about/"', $this->render( 'bg' )['html'] );
		$this->assertStringContainsString( '"url":"https://site.example/blog/en/about/"', $this->render( 'en' )['html'] );
	}

	public function testCanonicalMatchesEachLanguage(): void {
		$this->assertStringContainsString( 'rel="canonical" href="https://site.example/blog/about/"', $this->render( '' )['html'] );
		$this->assertStringContainsString( 'rel="canonical" href="https://site.example/blog/bg/about/"', $this->render( 'bg' )['html'] );
		$this->assertStringContainsString( 'rel="canonical" href="https://site.example/blog/en/about/"', $this->render( 'en' )['html'] );
	}
}
