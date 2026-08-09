<?php
/**
 * Выбор сегмента-представителя при совпадении uniq_hash.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Одинаковые по `uniq_hash` сегменты на странице ищутся в словаре один раз
 * (ТЗ 4.5) — нужно решить, какой из них представляет всю группу при записи
 * новой строки: его `kind` попадает в `sources.kind`, а его `attribute` —
 * в `occurrences.attribute_name`.
 *
 * Вынесено из {@see Translator} в отдельный класс, чтобы правило приоритета
 * проверялось юнит-тестом напрямую, без фейковых репозиториев и `$wpdb`.
 */
final class SegmentDeduplicator {

	/**
	 * Схлопывает сегменты по `uniq_hash`, выбирая представителя. Чистая функция.
	 *
	 * По умолчанию побеждает первый по порядку в документе — так было
	 * всегда, пока у сегментов с одинаковым текстом совпадал и `kind`.
	 * С тех пор как `og:title`/`twitter:title`/JSON-LD `headline` хешируются
	 * как обычный текст при совпадении с заголовком записи (см.
	 * Extractor::makeSegment(), TITLE_META) — это больше не гарантия:
	 * `<title>`/H1 почти всегда стоят в разметке раньше `<script
	 * type="application/ld+json">` в конце `<head>`, и правило «первый
	 * победил» стабильно превращало бы такую строку в обычный текст —
	 * `SourceRepository::TYPE_SEO` в «Переводе строк» (`kind = seo`, либо
	 * `attribute` с `attribute_name = content`) её бы тогда не находил, хотя
	 * она настолько же SEO-поле, насколько и текст страницы.
	 *
	 * Поэтому SEO-сегмент (поле JSON-LD или `content` у meta) побеждает
	 * простой текст/атрибут независимо от порядка в документе. Среди
	 * нескольких текстовых или нескольких SEO-сегментов порядок остаётся
	 * прежним — «первый найденный», как и раньше.
	 *
	 * @param list<Segment> $segments Все сегменты страницы, как их отдал Extractor.
	 * @return array<string, Segment> Ключ — uniq_hash, значение — сегмент-представитель.
	 */
	public static function deduplicate( array $segments ): array {
		$unique = array();

		foreach ( $segments as $segment ) {
			$current = $unique[ $segment->uniqHash ] ?? null;

			if ( null === $current || ( ! self::isSeoFlavored( $current ) && self::isSeoFlavored( $segment ) ) ) {
				$unique[ $segment->uniqHash ] = $segment;
			}
		}

		return $unique;
	}

	/**
	 * SEO-сегмент: поле JSON-LD или meta-тег `content` (`og:*`/`twitter:*`) —
	 * то же самое, что находит по `kind` фильтр `SourceRepository::TYPE_SEO`.
	 *
	 * @param Segment $segment Проверяемый сегмент.
	 */
	private static function isSeoFlavored( Segment $segment ): bool {
		return Segment::KIND_SEO === $segment->kind
			|| ( Segment::KIND_ATTRIBUTE === $segment->kind && 'content' === $segment->attribute );
	}
}
