<?php
/**
 * Тесты перевода структурированных данных.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\JsonLdDocument;
use WpMlp\Rendering\JsonLdRules;
use WpMlp\Rendering\Segment;

#[CoversClass( JsonLdRules::class )]
#[CoversClass( JsonLdDocument::class )]
#[CoversClass( \WpMlp\Rendering\JsonLdField::class )]
final class JsonLdTest extends TestCase {

	/**
	 * Оборачивает граф в страницу и извлекает из неё строки.
	 *
	 * @param string $json Содержимое тега ld+json.
	 * @return array{document: HtmlDocument, segments: list<Segment>}
	 */
	private function extract( string $json ): array {
		$html = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. $json . '</script></head><body><p>Текст</p></body></html>';

		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		return array(
			'document' => $document,
			'segments' => ( new Extractor() )->extract( $document, 'ru' ),
		);
	}

	/**
	 * Тексты из графа.
	 *
	 * @param list<Segment> $segments Найденные строки.
	 * @return list<string>
	 */
	private function seoTexts( array $segments ): array {
		$texts = array();

		foreach ( $segments as $segment ) {
			if ( Segment::KIND_SEO === $segment->kind ) {
				$texts[] = $segment->text;
			}
		}

		return $texts;
	}

	public function testExtractsHeadlineAndDescription(): void {
		$result = $this->extract(
			'{"@type":"Article","headline":"Заголовок статьи","description":"Описание статьи"}'
		);

		$texts = $this->seoTexts( $result['segments'] );

		$this->assertContains( 'Заголовок статьи', $texts );
		$this->assertContains( 'Описание статьи', $texts );
	}

	/**
	 * Идентификаторы, типы и даты — не текст. Их перевод молча ломает
	 * микроразметку: на странице ничего не изменится, а поисковик перестанет
	 * её понимать.
	 */
	public function testIgnoresIdentifiersTypesAndUrls(): void {
		$result = $this->extract(
			'{"@type":"Article","@id":"https://site.ru/#article","url":"https://site.ru/a/",'
			. '"datePublished":"2026-08-08","image":"https://site.ru/img.png","headline":"Заголовок"}'
		);

		$texts = $this->seoTexts( $result['segments'] );

		$this->assertSame( array( 'Заголовок' ), $texts );
	}

	/**
	 * Живой случай: страница отдавалась на английском, а `keywords` в
	 * JSON-LD BlogPosting оставался русским — поле не входило в список
	 * переводимых. Читают его только поисковики и другие программы,
	 * разбирающие структурированные данные, но раз уж `inLanguage: "en"`
	 * заявлен в том же графе, ключевые слова другого языка — нестыковка,
	 * которую стоит убрать.
	 */
	public function testExtractsKeywords(): void {
		$result = $this->extract(
			'{"@type":"BlogPosting","headline":"Заголовок","keywords":"плагины мультиязычности WordPress"}'
		);

		$this->assertContains( 'плагины мультиязычности WordPress', $this->seoTexts( $result['segments'] ) );
	}

	/**
	 * Название компании и имя автора — имена собственные, они одинаковы
	 * на всех языках.
	 */
	public function testDoesNotTranslateProperNouns(): void {
		$result = $this->extract(
			'{"@type":"Article","headline":"Заголовок","author":{"@type":"Person","name":"Иван Петров"},'
			. '"publisher":{"@type":"Organization","name":"CenterAI"}}'
		);

		$texts = $this->seoTexts( $result['segments'] );

		$this->assertContains( 'Заголовок', $texts );
		$this->assertNotContains( 'Иван Петров', $texts );
		$this->assertNotContains( 'CenterAI', $texts );
	}

	/**
	 * А вот `name` в хлебных крошках — это подпись для человека, её переводить
	 * нужно (ТЗ 8.4 требует breadcrumb labels).
	 */
	public function testTranslatesBreadcrumbLabels(): void {
		$result = $this->extract(
			'{"@type":"BreadcrumbList","itemListElement":['
			. '{"@type":"ListItem","position":1,"name":"Главная"},'
			. '{"@type":"ListItem","position":2,"name":"Материалы"}]}'
		);

		$texts = $this->seoTexts( $result['segments'] );

		$this->assertContains( 'Главная', $texts );
		$this->assertContains( 'Материалы', $texts );
	}

	public function testAppliedTranslationLandsInsideTheScript(): void {
		$result = $this->extract( '{"@type":"Article","headline":"Заголовок статьи"}' );

		foreach ( $result['segments'] as $segment ) {
			if ( Segment::KIND_SEO === $segment->kind ) {
				$segment->apply( 'Article headline' );
			}
		}

		$html = $result['document']->html();

		$this->assertStringContainsString( 'Article headline', $html );
		$this->assertStringNotContainsString( 'Заголовок статьи', $html );
	}

	/**
	 * После записи содержимое тега обязано остаться разбираемым JSON —
	 * иначе поисковик отбросит весь блок разметки.
	 */
	public function testScriptStaysValidJsonAfterTranslation(): void {
		$result = $this->extract(
			'{"@type":"Article","headline":"Заголовок","author":{"@type":"Person","name":"Иван"}}'
		);

		foreach ( $result['segments'] as $segment ) {
			if ( Segment::KIND_SEO === $segment->kind ) {
				$segment->apply( 'Headline' );
			}
		}

		$document = HtmlDocument::parse( $result['document']->html() );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );

		$this->assertNotNull( $script );

		$decoded = json_decode( trim( (string) $script->textContent ), true );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'Headline', $decoded['headline'] );
		$this->assertSame( 'Иван', $decoded['author']['name'] );
	}

	/**
	 * Текст внутри `<script>` сериализатор отдаёт СЫРЫМ — так требует
	 * спецификация HTML5, и ни libxml, ни `\Dom\HTMLDocument` его не
	 * экранируют. Значит закрывающий тег, попавший в перевод, дошёл бы до
	 * разметки буквально и оборвал бы блок структурированных данных
	 * посреди JSON: поисковик отбросил бы всю разметку, а хвост JSON
	 * вывалился бы в текст страницы.
	 *
	 * Сейчас от этого спасает `wp_strip_all_tags()` на путях записи
	 * перевода, но защита лежит ВНЕ сериализатора: любой новый путь
	 * (импорт, WP-CLI, миграция) снял бы её молча. Кодирование `<` и `>`
	 * прямо при сборке JSON делает строку безопасной независимо от того,
	 * кто и как её туда положил.
	 */
	public function testTranslationCannotCloseTheScriptTag(): void {
		$result = $this->extract( '{"@type":"Article","headline":"Заголовок"}' );

		foreach ( $result['segments'] as $segment ) {
			if ( Segment::KIND_SEO === $segment->kind ) {
				$segment->apply( 'Hi</script><img src=x onerror=alert(1)>' );
			}
		}

		$html = $result['document']->html();

		$this->assertStringNotContainsString( '</script><img', $html );

		// Блок остаётся ровно одним script-узлом с разбираемым JSON внутри.
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$scripts = $document->document()->getElementsByTagName( 'script' );

		$this->assertSame( 1, $scripts->count() );

		$decoded = json_decode( trim( (string) $scripts->item( 0 )->textContent ), true );

		$this->assertIsArray( $decoded, 'Содержимое тега перестало быть JSON.' );
		$this->assertSame( 'Hi</script><img src=x onerror=alert(1)>', $decoded['headline'] );
	}

	public function testBrokenJsonIsLeftAlone(): void {
		$result = $this->extract( '{ это не json }' );

		$this->assertSame( array(), $this->seoTexts( $result['segments'] ) );
		$this->assertStringContainsString( 'это не json', $result['document']->html() );
	}

	public function testHandlesGraphWrapper(): void {
		$result = $this->extract(
			'{"@context":"https://schema.org","@graph":[{"@type":"WebPage","name":"Название страницы"}]}'
		);

		$this->assertContains( 'Название страницы', $this->seoTexts( $result['segments'] ) );
	}

	public function testCollectsUrlsForLocalisation(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"WebPage","url":"https://site.ru/a/","image":"https://site.ru/i.png"}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame( array( 'url' => 'https://site.ru/a/' ), $json->urls() );
	}

	/**
	 * definedEntityTypes() находит только узлы, где сущность ОПРЕДЕЛЕНА
	 * (несёт `@id` и `@type` на одном уровне) — с типами в нижнем регистре,
	 * даже если в самом графе они записаны иначе.
	 */
	public function testDefinedEntityTypesCollectsIdAndTypeTogether(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/en/#organization","url":"https://site.ru/en/",'
			. '"logo":{"@type":"ImageObject","@id":"https://site.ru/en/#logo","url":"https://site.ru/logo.png"}}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame(
			array(
				'https://site.ru/en/#organization' => array( 'organization' ),
				'https://site.ru/en/#logo'          => array( 'imageobject' ),
			),
			$json->definedEntityTypes()
		);
	}

	/**
	 * `@type` — валидно и списком: definedEntityTypes() отдаёт полный
	 * список, не только первый элемент.
	 */
	public function testDefinedEntityTypesHandlesTypeAsArray(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":["Person","Organization"],"@id":"https://site.ru/#/schema/person/1","name":"Иван"}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame(
			array( 'https://site.ru/#/schema/person/1' => array( 'person', 'organization' ) ),
			$json->definedEntityTypes()
		);
	}

	/**
	 * allIdFields() — КАЖДОЕ вхождение `@id` в графе, включая ссылки на
	 * сущность (`publisher`), у которых своего `@type` обычно нет вовсе.
	 * Именно эта разница ломала прошлую версию: ссылка классифицировалась
	 * по типу родителя (`Article`), а не по тому, на что она указывает.
	 */
	public function testAllIdFieldsCollectsDefinitionsAndReferencesAlike(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","@id":"https://site.ru/en/about/#article",'
			. '"publisher":{"@id":"https://site.ru/en/#organization"}}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame(
			array(
				'@id'                  => 'https://site.ru/en/about/#article',
				"publisher\x1F@id" => 'https://site.ru/en/#organization',
			),
			$json->allIdFields()
		);
	}

	public function testRulesRejectUnknownKeys(): void {
		$this->assertFalse( JsonLdRules::isTranslatable( 'datePublished', 'Article' ) );
		$this->assertFalse( JsonLdRules::isTranslatable( '@type', 'Article' ) );
		$this->assertTrue( JsonLdRules::isTranslatable( 'description', 'Article' ) );
		$this->assertTrue( JsonLdRules::isTranslatable( 'name', 'ListItem' ) );
		$this->assertFalse( JsonLdRules::isTranslatable( 'name', 'Organization' ) );
		$this->assertTrue( JsonLdRules::isTranslatable( 'keywords', 'BlogPosting' ) );
	}

	/**
	 * Пункт 3 жалобы: `headline` — заголовок записи внутри графа, тот же
	 * текст, что и H1/`<title>`/og:title. Должен получать тот же uniq_hash,
	 * что и обычный текстовый сегмент с теми же словами, — иначе редактор,
	 * переводящий страницу, и «Перевод строк» заводят для одной фразы два
	 * несвязанных словарных ключа.
	 */
	public function testHeadlineSharesHashWithMatchingPlainText(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","headline":"Пять советов"}'
			. '</script></head><body><h1>Пять советов</h1></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$matching = array_values( array_filter( $segments, static fn( Segment $s ): bool => 'Пять советов' === $s->text ) );

		$this->assertCount( 2, $matching, 'headline и H1 должны дать два сегмента с одним и тем же текстом.' );
		$this->assertSame( $matching[0]->uniqHash, $matching[1]->uniqHash );

		// headline остаётся SEO/GEO по хранимому виду — общий только хеш.
		$headline = Segment::KIND_SEO === $matching[0]->kind ? $matching[0] : $matching[1];
		$this->assertSame( Segment::KIND_SEO, $headline->kind );
	}

	/**
	 * Только `headline` — не любое поле графа. `description` с тем же
	 * текстом, что и случайный абзац на странице, не должна неожиданно
	 * склеиваться с ним в один словарный ключ.
	 */
	public function testDescriptionDoesNotShareHashWithMatchingPlainText(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","description":"Добро пожаловать"}'
			. '</script></head><body><p>Добро пожаловать</p></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$matching = array_values( array_filter( $segments, static fn( Segment $s ): bool => 'Добро пожаловать' === $s->text ) );

		$this->assertCount( 2, $matching );
		$this->assertNotSame( $matching[0]->uniqHash, $matching[1]->uniqHash );
	}

	/**
	 * Пункт 2 жалобы: `logoReferenceIds()` находит `@id` логотипа, когда он
	 * задан полным inline-определением (несёт и `@type`, и `@id` на своём
	 * уровне) — самая частая форма у Yoast/Rank Math.
	 */
	public function testLogoReferenceIdsFindsInlineDefinition(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/en/#organization","url":"https://site.ru/en/",'
			. '"logo":{"@type":"ImageObject","@id":"https://site.ru/en/#logo","url":"https://site.ru/logo.png"}}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame( array( 'https://site.ru/en/#logo' ), $json->logoReferenceIds() );
	}

	/**
	 * То же самое, но `logo` — голая ссылка без собственного `@type`
	 * (`{"@id":"..."}`) — валидная, хоть и менее распространённая форма.
	 */
	public function testLogoReferenceIdsFindsBareReference(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/en/#organization","logo":{"@id":"https://site.ru/en/#logo"}}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame( array( 'https://site.ru/en/#logo' ), $json->logoReferenceIds() );
	}

	public function testLogoReferenceIdsIsEmptyWhenThereIsNoLogo(): void {
		$html     = '<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Organization","@id":"https://site.ru/en/#organization"}'
			. '</script></head><body></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$script = $document->document()->getElementsByTagName( 'script' )->item( 0 );
		$json   = JsonLdDocument::fromNode( $script );

		$this->assertNotNull( $json );
		$this->assertSame( array(), $json->logoReferenceIds() );
	}
}
