<?php
/**
 * Тесты извлечения метатегов соцсетей.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;

#[CoversClass( Extractor::class )]
final class SocialMetaTest extends TestCase {

	/**
	 * Тексты, найденные в разметке.
	 *
	 * @param string $head Содержимое `<head>`.
	 * @return list<string>
	 */
	private function texts( string $head ): array {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>' . $head . '</head><body></body></html>'
		);

		$this->assertNotNull( $document );

		return array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru' )
		);
	}

	public function testExtractsOpenGraphTextFields(): void {
		$texts = $this->texts(
			'<meta property="og:title" content="Заголовок для соцсетей">'
			. '<meta property="og:description" content="Описание для соцсетей">'
		);

		$this->assertContains( 'Заголовок для соцсетей', $texts );
		$this->assertContains( 'Описание для соцсетей', $texts );
	}

	/**
	 * Стандарт Open Graph требует `property`, Twitter — `name`, а SEO-плагины
	 * в разных версиях выводят twitter-теги то так, то так.
	 */
	public function testHandlesBothNameAndPropertyAttributes(): void {
		$texts = $this->texts(
			'<meta name="twitter:title" content="Твиттер через name">'
			. '<meta property="twitter:description" content="Твиттер через property">'
		);

		$this->assertContains( 'Твиттер через name', $texts );
		$this->assertContains( 'Твиттер через property', $texts );
	}

	/**
	 * Адреса, локаль и тип не переводятся — их подменяет SeoMeta.
	 */
	public function testIgnoresUrlsLocaleAndType(): void {
		$texts = $this->texts(
			'<meta property="og:url" content="https://site.ru/page/">'
			. '<meta property="og:image" content="https://site.ru/img.png">'
			. '<meta property="og:locale" content="ru_RU">'
			. '<meta property="og:type" content="article">'
			. '<meta property="og:title" content="Только это">'
		);

		$this->assertSame( array( 'Только это' ), $texts );
	}

	public function testExtractsImageAltText(): void {
		$texts = $this->texts( '<meta property="og:image:alt" content="Описание картинки">' );

		$this->assertContains( 'Описание картинки', $texts );
	}

	public function testStillExtractsPlainDescription(): void {
		$texts = $this->texts( '<meta name="description" content="Обычное описание">' );

		$this->assertContains( 'Обычное описание', $texts );
	}

	/**
	 * Белый список twitter-полей точный, без маски `twitter:*`: card, site
	 * и creator — служебные значения (тип карточки, @имя аккаунта), а не
	 * текст для читателя. Их не переводят и не подменяют — просто не трогают.
	 */
	public function testIgnoresTwitterFieldsOutsideTheAllowlist(): void {
		$texts = $this->texts(
			'<meta name="twitter:card" content="summary_large_image">'
			. '<meta name="twitter:site" content="@centerai">'
			. '<meta name="twitter:creator" content="@centerai">'
			. '<meta name="twitter:image" content="https://site.ru/img.png">'
			. '<meta name="twitter:title" content="Только это">'
		);

		$this->assertSame( array( 'Только это' ), $texts );
	}

	public function testExtractsArticleSectionAndTag(): void {
		$texts = $this->texts(
			'<meta property="article:section" content="Технологии">'
			. '<meta property="article:tag" content="ИИ">'
			. '<meta property="article:tag" content="WordPress">'
		);

		$this->assertContains( 'Технологии', $texts );
		$this->assertContains( 'ИИ', $texts );
		$this->assertContains( 'WordPress', $texts );
	}

	/**
	 * Даты и адреса article:* — не текст, их не переводят и не подменяют:
	 * плагин их вообще не трогает, только article:section и article:tag.
	 */
	public function testIgnoresOtherArticleFields(): void {
		$texts = $this->texts(
			'<meta property="article:published_time" content="2026-08-08T10:00:00+00:00">'
			. '<meta property="article:author" content="https://site.ru/author/ivan/">'
			. '<meta property="article:section" content="Только это">'
		);

		$this->assertSame( array( 'Только это' ), $texts );
	}

	/**
	 * `twitter:label1`/`twitter:label2`/`twitter:data2` — подписи вроде
	 * «Written by» / «Est. reading time» / «5 minutes», которые Yoast и
	 * Rank Math добавляют в карточку. Это человекочитаемый текст, а не
	 * служебное значение — его нужно переводить.
	 */
	public function testExtractsTwitterLabelsAndSecondDataField(): void {
		$texts = $this->texts(
			'<meta name="twitter:label1" content="Автор">'
			. '<meta name="twitter:label2" content="Время чтения">'
			. '<meta name="twitter:data2" content="5 минут">'
		);

		$this->assertContains( 'Автор', $texts );
		$this->assertContains( 'Время чтения', $texts );
		$this->assertContains( '5 минут', $texts );
	}

	/**
	 * `twitter:data1` в белый список не входит намеренно: там лежит имя
	 * автора — имя собственное, а не текст для читателя.
	 */
	public function testIgnoresTwitterDataOneAsAProperNoun(): void {
		$texts = $this->texts(
			'<meta name="twitter:label1" content="Автор">'
			. '<meta name="twitter:data1" content="Иван Петров">'
		);

		$this->assertContains( 'Автор', $texts );
		$this->assertNotContains( 'Иван Петров', $texts );
	}

	public function testIgnoresUnrelatedMeta(): void {
		$texts = $this->texts(
			'<meta name="viewport" content="width=device-width">'
			. '<meta name="generator" content="WordPress 6.8">'
			. '<meta name="robots" content="index, follow">'
		);

		$this->assertSame( array(), $texts );
	}
}
