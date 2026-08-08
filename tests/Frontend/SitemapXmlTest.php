<?php
/**
 * Тесты сборки XML карты сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\SitemapXml;

#[CoversClass( SitemapXml::class )]
final class SitemapXmlTest extends TestCase {

	public function testProducesWellFormedXml(): void {
		$xml = SitemapXml::build(
			array(
				array( 'loc' => 'https://site.ru/about/' ),
			)
		);

		$previous = libxml_use_internal_errors( true );
		$document = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertNotFalse( $document, 'Документ должен разбираться как XML.' );
		$this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
	}

	public function testIncludesLocAndLastmod(): void {
		$xml = SitemapXml::build(
			array(
				array(
					'loc'     => 'https://site.ru/about/',
					'lastmod' => '2026-08-08T10:00:00+00:00',
				),
			)
		);

		$this->assertStringContainsString( '<loc>https://site.ru/about/</loc>', $xml );
		$this->assertStringContainsString( '<lastmod>2026-08-08T10:00:00+00:00</lastmod>', $xml );
	}

	public function testDeclaresXhtmlNamespaceForAlternates(): void {
		$xml = SitemapXml::build( array( array( 'loc' => 'https://site.ru/' ) ) );

		$this->assertStringContainsString( 'xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml );
	}

	public function testWritesAlternatesWhenSeveralVersionsExist(): void {
		$xml = SitemapXml::build(
			array(
				array(
					'loc'        => 'https://site.ru/about/',
					'alternates' => array(
						array(
							'hreflang' => 'ru',
							'href'     => 'https://site.ru/about/',
						),
						array(
							'hreflang' => 'en',
							'href'     => 'https://site.ru/en/about/',
						),
					),
				),
			)
		);

		$this->assertStringContainsString(
			'<xhtml:link rel="alternate" hreflang="ru" href="https://site.ru/about/"/>',
			$xml
		);
		$this->assertStringContainsString(
			'<xhtml:link rel="alternate" hreflang="en" href="https://site.ru/en/about/"/>',
			$xml
		);
	}

	/**
	 * Единственная версия страницы — не «альтернатива самой себе»: такой
	 * xhtml:link ничего не сообщает поисковику и только раздувает файл.
	 */
	public function testSkipsAlternatesForSingleVersion(): void {
		$xml = SitemapXml::build(
			array(
				array(
					'loc'        => 'https://site.ru/about/',
					'alternates' => array(
						array(
							'hreflang' => 'ru',
							'href'     => 'https://site.ru/about/',
						),
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'xhtml:link', $xml );
	}

	/**
	 * Незакодированный амперсанд делает документ невалидным целиком —
	 * поисковик отверг бы карту, а не отдельный адрес.
	 */
	public function testEscapesAmpersandsInUrls(): void {
		$xml = SitemapXml::build(
			array(
				array( 'loc' => 'https://site.ru/search/?a=1&b=2' ),
			)
		);

		$this->assertStringContainsString( 'a=1&amp;b=2', $xml );
		$this->assertStringNotContainsString( 'a=1&b=2', $xml );

		$previous = libxml_use_internal_errors( true );
		$this->assertNotFalse( simplexml_load_string( $xml ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}

	public function testSkipsEntriesWithoutLocation(): void {
		$xml = SitemapXml::build(
			array(
				array( 'loc' => '' ),
				array( 'loc' => '   ' ),
				array( 'loc' => 'https://site.ru/' ),
			)
		);

		$this->assertSame( 1, substr_count( $xml, '<url>' ) );
	}

	public function testEmptyListStillProducesValidDocument(): void {
		$xml = SitemapXml::build( array() );

		$previous = libxml_use_internal_errors( true );
		$this->assertNotFalse( simplexml_load_string( $xml ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertStringNotContainsString( '<url>', $xml );
	}

	/**
	 * Протокол sitemaps.org ограничивает файл 50 000 адресами.
	 */
	public function testTruncatesToProtocolLimit(): void {
		$entries = array();

		for ( $i = 0; $i < SitemapXml::MAX_URLS + 10; $i++ ) {
			$entries[] = array( 'loc' => 'https://site.ru/page-' . $i . '/' );
		}

		$this->assertSame( SitemapXml::MAX_URLS, substr_count( SitemapXml::build( $entries ), '<url>' ) );
	}
}
