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
	}
}
