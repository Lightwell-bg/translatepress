<?php
/**
 * Сборка XML мультиязычного sitemap.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

/**
 * Превращает список адресов в XML карты сайта.
 *
 * Вынесено из `Sitemap` отдельно и не знает ни о WordPress, ни о БД: сборку
 * XML и экранирование можно проверить тестами, не поднимая сайт.
 *
 * Формат — sitemaps.org плюс расширение `xhtml:link` для языковых версий.
 * Именно из него Google понимает, что `/about/` и `/en/about/` — одна и та же
 * страница на разных языках, а не два дубля.
 */
final class SitemapXml {

	/**
	 * Предел протокола sitemaps.org — 50 000 адресов в одном файле.
	 */
	public const MAX_URLS = 50000;

	/**
	 * Собирает документ.
	 *
	 * @param list<array{loc: string, lastmod?: string, alternates?: list<array{hreflang: string, href: string}>}> $entries Адреса.
	 */
	public static function build( array $entries ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( array_slice( $entries, 0, self::MAX_URLS ) as $entry ) {
			$xml .= self::renderUrl( $entry );
		}

		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Один элемент `<url>`.
	 *
	 * @param array{loc: string, lastmod?: string, alternates?: list<array{hreflang: string, href: string}>} $entry Адрес.
	 */
	private static function renderUrl( array $entry ): string {
		$loc = trim( (string) ( $entry['loc'] ?? '' ) );

		if ( '' === $loc ) {
			return '';
		}

		$xml = "\t<url>\n";
		$xml .= "\t\t<loc>" . self::escape( $loc ) . "</loc>\n";

		$lastmod = trim( (string) ( $entry['lastmod'] ?? '' ) );

		if ( '' !== $lastmod ) {
			$xml .= "\t\t<lastmod>" . self::escape( $lastmod ) . "</lastmod>\n";
		}

		/*
		 * Одна языковая версия — это не «альтернатива самой себе». Набор
		 * xhtml:link осмыслен только когда версий несколько, иначе он лишь
		 * раздувает файл.
		 */
		$alternates = $entry['alternates'] ?? array();

		if ( count( $alternates ) > 1 ) {
			foreach ( $alternates as $alternate ) {
				$xml .= sprintf(
					"\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\"/>\n",
					self::escape( (string) $alternate['hreflang'] ),
					self::escape( (string) $alternate['href'] )
				);
			}
		}

		return $xml . "\t</url>\n";
	}

	/**
	 * Экранирование для XML.
	 *
	 * Именно `htmlspecialchars` с ENT_XML1, а не функции WordPress: `esc_url`
	 * оставляет `&` как есть, и незакодированный амперсанд в адресе сделал бы
	 * документ невалидным — парсер поисковика отверг бы его целиком.
	 *
	 * @param string $value Значение.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
