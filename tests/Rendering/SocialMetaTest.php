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

	public function testIgnoresUnrelatedMeta(): void {
		$texts = $this->texts(
			'<meta name="viewport" content="width=device-width">'
			. '<meta name="generator" content="WordPress 6.8">'
			. '<meta name="robots" content="index, follow">'
		);

		$this->assertSame( array(), $texts );
	}
}
