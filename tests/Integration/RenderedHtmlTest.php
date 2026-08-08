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
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;
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
 * живёт в подкаталоге `/blog`. Фикстура специально воспроизводит жёстко
 * закодированные ссылки вида `/ru/...`, оставшиеся в пунктах меню ещё до
 * этого плагина, — именно они дали `/blog/en/ru/...` в жалобе.
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
	 * Переключатель языков, как его строят NavMenu/LanguageSwitcher: класс-
	 * маркер прямо на `<a>`, адрес — абсолютный, посчитанный для ТЕКУЩЕГО
	 * запроса.
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
	 * Полная страница: голова с SEO/JSON-LD, переключатель, футер с
	 * жёстко закодированными легаси-ссылками `/ru/...` (ровно тот случай из
	 * жалобы — пункты меню, сохранённые буквальным текстом до появления
	 * плагина) и русский текст, который должен попасть в «Перевод строк».
	 *
	 * @param array{settings: Settings, resolver: LanguageResolver, urls: UrlConverter, target: Language} $request
	 */
	private function page( array $request ): string {
		$urls   = $request['urls'];
		$target = $request['target'];

		// Как если бы SEO-плагин собрал эти `@id` через home_url() ИМЕННО на
		// этом запросе — с уже вклеенным префиксом текущего языка.
		$organizationId = $urls->absolute( '/#organization', $target );
		$personId       = $urls->absolute( '/#/schema/person/abc123', $target );
		$websiteId      = $urls->absolute( '/#website', $target );

		// А эти — исходные, ещё не локализованные значения (как WebPage.url
		// в уже существующем юнит-тесте SeoMeta): у страничных сущностей
		// @id обязан получить префикс текущего языка точно так же, как url.
		$webPageUrl = self::HOME . '/about/';

		// Как в настоящем графе Yoast: узлы ссылаются друг на друга через
		// голый `{"@id": "..."}"`, без собственного `@type` у самой ссылки —
		// это и есть та форма, которую предыдущая версия (классификация по
		// родителю в дереве) не распознавала вовсе.
		$jsonLd = json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array(
						'@type' => 'Organization',
						'@id'   => $organizationId,
					),
					array(
						'@type' => 'Person',
						'@id'   => $personId,
						'name'  => 'Иван Петров',
					),
					array(
						'@type' => 'WebSite',
						'@id'   => $websiteId,
						'publisher' => array( '@id' => $organizationId ),
					),
					array(
						'@type'     => 'WebPage',
						'@id'       => self::HOME . '/about/#webpage',
						'url'       => $webPageUrl,
						'about'     => array( '@id' => $organizationId ),
						'isPartOf'  => array( '@id' => $websiteId ),
					),
					array(
						'@type'             => 'Article',
						'@id'               => self::HOME . '/about/#article',
						'headline'          => 'Заголовок статьи',
						'publisher'         => array( '@id' => $organizationId ),
						'author'            => array( '@id' => $personId ),
						'mainEntityOfPage'  => array( '@id' => self::HOME . '/about/#webpage' ),
					),
					array(
						'@type' => 'BreadcrumbList',
						'@id'   => self::HOME . '/about/#breadcrumb',
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
			. '<main><p>Добро пожаловать</p></main>'
			. '<footer>'
			. '<a href="/blog/ru/">Home</a>'
			. '<a href="/ru/discuss-the-task/">Discuss the task</a>'
			. '<p>© 2026 Пример. Все права защищены.</p>'
			. '</footer>'
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
	}

	public function testBulgarianRoutesUnderBgPrefix(): void {
		$html = $this->render( 'bg' )['html'];

		$this->assertStringContainsString( 'href="https://site.example/blog/bg/about/"', $html );
	}

	public function testEnglishRoutesUnderEnPrefix(): void {
		$html = $this->render( 'en' )['html'];

		$this->assertStringContainsString( 'href="https://site.example/blog/en/about/"', $html );
	}

	/**
	 * Пункт 1 жалобы: `og:locale` — полный код `xx_XX`, не голое `en`/`bg`/`ru`.
	 */
	public function testOgLocaleUsesFullFormat(): void {
		$this->assertStringContainsString( 'og:locale" content="ru_RU"', $this->render( '' )['html'] );
		$this->assertStringContainsString( 'og:locale" content="bg_BG"', $this->render( 'bg' )['html'] );
		$this->assertStringContainsString( 'og:locale" content="en_US"', $this->render( 'en' )['html'] );
	}

	/**
	 * Пункт 2 жалобы, воспроизведён буквально: легаси-ссылка `Home`
	 * (`/blog/ru/`, с базовым путём в самой ссылке) на английской странице
	 * должна вести на `/blog/en/`, а не на `/blog/en/ru/`.
	 */
	public function testHomeLinkIsNotDoublyLocalized(): void {
		$html = $this->render( 'en' )['html'];

		$this->assertStringContainsString( '>Home</a>', $html );
		$this->assertStringNotContainsString( '/blog/en/ru/', $html );

		if ( 1 !== preg_match( '#href="([^"]*)">Home</a>#', $html, $match ) ) {
			$this->fail( 'Ссылка Home не найдена в итоговом HTML.' );
		}

		$this->assertSame( 'https://site.example/blog/en/', $this->absoluteHref( $match[1] ) );
	}

	/**
	 * Тот же случай, но ссылка изначально была написана БЕЗ базового пути
	 * установки (`/ru/discuss-the-task/`) — второй реальный вариант того,
	 * как легаси-ссылка могла попасть в шаблон.
	 */
	public function testDiscussTheTaskLinkIsNotDoublyLocalized(): void {
		$html = $this->render( 'en' )['html'];

		$this->assertStringContainsString( '>Discuss the task</a>', $html );
		$this->assertStringNotContainsString( '/en/ru/discuss-the-task/', $html );
		$this->assertStringContainsString( 'href="/blog/en/discuss-the-task/"', $html );
	}

	/**
	 * То же на болгарской странице — не только английской.
	 */
	public function testLegacyLinksAreFixedOnBulgarianToo(): void {
		$html = $this->render( 'bg' )['html'];

		$this->assertStringNotContainsString( '/bg/ru/', $html );
		$this->assertStringContainsString( 'href="/blog/bg/discuss-the-task/"', $html );
	}

	/**
	 * Ключевая регрессия из прошлого раунда: переключатель по-прежнему
	 * должен предлагать все три языка с их собственными адресами.
	 */
	public function testLanguageSwitcherOffersAllThreeLanguagesFromEveryPage(): void {
		foreach ( array( '', 'bg', 'en' ) as $slug ) {
			$html = $this->render( $slug )['html'];

			$this->assertStringContainsString( 'href="https://site.example/blog/about/"', $html, "ru switcher link missing on '$slug'" );
			$this->assertStringContainsString( 'href="https://site.example/blog/bg/about/"', $html, "bg switcher link missing on '$slug'" );
			$this->assertStringContainsString( 'href="https://site.example/blog/en/about/"', $html, "en switcher link missing on '$slug'" );
		}
	}

	/**
	 * Пункт 3 жалобы: только Organization/Person/WebSite остаются без
	 * префикса на любом языке.
	 */
	public function testStableEntityIdsAreIdenticalOnEveryLanguage(): void {
		foreach ( array( '', 'bg', 'en' ) as $slug ) {
			$html = $this->render( $slug )['html'];

			$this->assertStringContainsString( '"@id":"https://site.example/blog/#organization"', $html, "wrong Organization @id on '$slug'" );
			$this->assertStringContainsString( '"@id":"https://site.example/blog/#/schema/person/abc123"', $html, "wrong Person @id on '$slug'" );
			$this->assertStringContainsString( '"@id":"https://site.example/blog/#website"', $html, "wrong WebSite @id on '$slug'" );
		}
	}

	/**
	 * Пункт 3 жалобы, вторая половина: WebPage/Article/BreadcrumbList,
	 * наоборот, ДОЛЖНЫ получать префикс текущего языка.
	 */
	public function testPageScopedIdsGetTheLanguagePrefix(): void {
		$ru = $this->render( '' )['html'];
		$this->assertStringContainsString( '"@id":"https://site.example/blog/about/#webpage"', $ru );
		$this->assertStringContainsString( '"@id":"https://site.example/blog/about/#article"', $ru );
		$this->assertStringContainsString( '"@id":"https://site.example/blog/about/#breadcrumb"', $ru );

		$bg = $this->render( 'bg' )['html'];
		$this->assertStringContainsString( '"@id":"https://site.example/blog/bg/about/#webpage"', $bg );
		$this->assertStringContainsString( '"@id":"https://site.example/blog/bg/about/#article"', $bg );
		$this->assertStringContainsString( '"@id":"https://site.example/blog/bg/about/#breadcrumb"', $bg );

		$en = $this->render( 'en' )['html'];
		$this->assertStringContainsString( '"@id":"https://site.example/blog/en/about/#webpage"', $en );
		$this->assertStringContainsString( '"@id":"https://site.example/blog/en/about/#article"', $en );
		$this->assertStringContainsString( '"@id":"https://site.example/blog/en/about/#breadcrumb"', $en );
	}

	public function testCanonicalMatchesEachLanguage(): void {
		$this->assertStringContainsString( 'rel="canonical" href="https://site.example/blog/about/"', $this->render( '' )['html'] );
		$this->assertStringContainsString( 'rel="canonical" href="https://site.example/blog/bg/about/"', $this->render( 'bg' )['html'] );
		$this->assertStringContainsString( 'rel="canonical" href="https://site.example/blog/en/about/"', $this->render( 'en' )['html'] );
	}

	/**
	 * Пункт 4 жалобы, доказательство а не утверждение: русский текст футера
	 * (а) реально извлекается Extractor'ом — то же самое, что кладёт строку
	 * в таблицу `sources` и делает её видимой в «Переводе строк» — и (б)
	 * после подстановки перевода реально заменяется в итоговом HTML, а не
	 * "поддерживается кодом" абстрактно.
	 */
	public function testFooterCopyrightTextIsExtractedAndActuallyReplaced(): void {
		$request  = $this->requestFor( 'en' );
		$source   = $this->page( $request );
		$document = HtmlDocument::parse( $source );

		$this->assertNotNull( $document );
		$this->assertStringContainsString( '© 2026 Пример. Все права защищены.', $source );

		$segments = ( new Extractor() )->extract( $document, 'ru' );

		$footerSegment = null;

		foreach ( $segments as $segment ) {
			if ( Segment::KIND_TEXT === $segment->kind && str_contains( $segment->text, 'Все права защищены' ) ) {
				$footerSegment = $segment;
			}
		}

		$this->assertNotNull( $footerSegment, 'Extractor не нашёл текст футера — он не попал бы в "Перевод строк".' );

		// Ровно то, что делает Translator, когда для строки уже есть перевод.
		$footerSegment->apply( '© 2026 Example. All rights reserved.', $document );

		$translated = $document->html();

		$this->assertStringContainsString( '© 2026 Example. All rights reserved.', $translated );
		$this->assertStringNotContainsString( 'Все права защищены', $translated );
	}

	/**
	 * Тест целостности графа: КАЖДАЯ внутренняя ссылка `@id` (publisher,
	 * author, about, isPartOf, mainEntityOfPage — любое голое
	 * `{"@id": "..."}"` без собственного `@type`) обязана совпадать со
	 * значением `@id` какой-то реально ОПРЕДЕЛЁННОЙ сущности того же графа.
	 * Разбирает итоговый HTML заново, напрямую через json_decode — не через
	 * внутренние методы JsonLdDocument, чтобы проверка была независимой от
	 * реализации и ловила именно то, что видел бы поисковик.
	 */
	public function testEveryInternalIdReferenceMatchesADefinedEntity(): void {
		foreach ( array( '', 'bg', 'en' ) as $slug ) {
			$html = $this->render( $slug )['html'];
			$graph = $this->decodeGraph( $html );

			$defined = array();
			$this->collectDefinedIds( $graph, $defined );

			$references = array();
			$this->collectAllIdValues( $graph, $references );

			$this->assertNotEmpty( $references, "no @id values found at all on '$slug'" );

			foreach ( $references as $id ) {
				if ( ! str_starts_with( $id, self::HOME ) ) {
					continue;
				}

				$this->assertArrayHasKey(
					$id,
					$defined,
					"\"$id\" is referenced on '$slug' but no entity in the graph defines it"
				);
			}
		}
	}

	/**
	 * То же целостность, но конкретно: ссылка внутри Article/WebPage
	 * обязана указывать РОВНО на нормализованный `@id` Organization/Person/
	 * WebSite — не на устаревшее (с чужим языковым префиксом) значение.
	 */
	public function testReferencesPointToTheNormalizedTargetId(): void {
		$html          = $this->render( 'en' )['html'];
		$graph         = $this->decodeGraph( $html );
		$organization  = $this->findNodeByType( $graph, 'organization' );
		$person        = $this->findNodeByType( $graph, 'person' );
		$website       = $this->findNodeByType( $graph, 'website' );
		$article       = $this->findNodeByType( $graph, 'article' );
		$webPage       = $this->findNodeByType( $graph, 'webpage' );

		$this->assertNotNull( $organization );
		$this->assertNotNull( $person );
		$this->assertNotNull( $website );
		$this->assertNotNull( $article );
		$this->assertNotNull( $webPage );

		$this->assertSame( 'https://site.example/blog/#organization', $organization['@id'] );
		$this->assertSame( $organization['@id'], $article['publisher']['@id'] );
		$this->assertSame( $person['@id'], $article['author']['@id'] );
		$this->assertSame( $organization['@id'], $webPage['about']['@id'] );
		$this->assertSame( $website['@id'], $webPage['isPartOf']['@id'] );
		$this->assertSame( $webPage['@id'], $article['mainEntityOfPage']['@id'] );
	}

	/**
	 * Достаёт `@graph` из итогового HTML напрямую, без внутренних классов
	 * плагина.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function decodeGraph( string $html ): array {
		if ( 1 !== preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $match ) ) {
			$this->fail( 'JSON-LD script not found in rendered HTML.' );
		}

		$decoded = json_decode( $match[1], true );

		$this->assertIsArray( $decoded );
		$this->assertIsArray( $decoded['@graph'] ?? null );

		return $decoded['@graph'];
	}

	/**
	 * Узлы, где сущность ОПРЕДЕЛЕНА (несёт и `@id`, и `@type`): id => узел.
	 *
	 * @param mixed                        $node    Текущий узел графа.
	 * @param array<string, array<mixed>>  $defined Накопитель результата.
	 */
	private function collectDefinedIds( $node, array &$defined ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['@id'], $node['@type'] ) && is_string( $node['@id'] ) ) {
			$defined[ $node['@id'] ] = $node;
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collectDefinedIds( $value, $defined );
			}
		}
	}

	/**
	 * Все значения `@id` в графе, включая голые ссылки без `@type`.
	 *
	 * @param mixed        $node Текущий узел графа.
	 * @param list<string> $out  Накопитель результата.
	 */
	private function collectAllIdValues( $node, array &$out ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
			$out[] = $node['@id'];
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collectAllIdValues( $value, $out );
			}
		}
	}

	/**
	 * Первый узел графа верхнего уровня заданного типа (без учёта регистра).
	 *
	 * @param list<array<string, mixed>> $graph Список узлов `@graph`.
	 * @param string                     $type  Искомый тип, в нижнем регистре.
	 * @return array<string, mixed>|null
	 */
	private function findNodeByType( array $graph, string $type ): ?array {
		foreach ( $graph as $node ) {
			$types = is_array( $node['@type'] ?? null ) ? $node['@type'] : array( $node['@type'] ?? '' );
			$types = array_map( static fn( $t ): string => strtolower( (string) $t ), $types );

			if ( in_array( $type, $types, true ) ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Достаёт `href`, даже если он относительный, дописывая происхождение —
	 * так проще сравнивать с абсолютным ожидаемым значением.
	 */
	private function absoluteHref( string $href ): string {
		return str_starts_with( $href, 'http' ) ? $href : 'https://site.example' . $href;
	}
}
