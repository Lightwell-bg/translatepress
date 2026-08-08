<?php
/**
 * Подстановка переводов в сырое поле записи (заголовок/excerpt/содержимое).
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Собирает патчи для {@see RawContentPatcher} из сегментов одного поля и
 * карты переводов.
 *
 * Только текст и разрешённые атрибуты (см. SegmentLocator) — переводу
 * подлежат исключительно они, а не теги, классы, стили, URL, `id`,
 * `data-*`, шорткоды или структура блоков: этот класс их даже не видит,
 * потому что не трогает ничего, кроме диапазона, который нашёл
 * SegmentLocator для каждого сегмента.
 */
final class PostFieldPatcher {

	/**
	 * @param string             $raw          Сырое значение поля.
	 * @param list<PostSegment>  $segments     Сегменты ЭТОГО поля, в порядке появления в $raw.
	 * @param array<string, string> $translations uniq_hash => перевод. Сегменты без записи здесь не трогаются.
	 * @return string|null Готовая строка, либо null — если хотя бы один
	 *                      переводимый сегмент не удалось однозначно найти
	 *                      в исходнике.
	 */
	public static function apply( string $raw, array $segments, array $translations ): ?string {
		$patches = array();

		foreach ( $segments as $postSegment ) {
			$translation = $translations[ $postSegment->segment->uniqHash ] ?? null;

			if ( null === $translation || '' === $translation ) {
				continue;
			}

			$candidates = SegmentLocator::candidates( $postSegment->segment );

			if ( array() === $candidates ) {
				return null;
			}

			$patches[] = array(
				'candidates'  => $candidates,
				'replacement' => self::encode( $translation, $postSegment->segment->kind ),
			);
		}

		return RawContentPatcher::apply( $raw, $patches );
	}

	/**
	 * Экранирует перевод под контекст вставки — так же, как исходный текст
	 * был бы закодирован в этой же позиции строки. Чистая функция.
	 *
	 * @param string $translation Текст перевода.
	 * @param string $kind        Segment::KIND_*.
	 */
	private static function encode( string $translation, string $kind ): string {
		return Segment::KIND_ATTRIBUTE === $kind
			? htmlspecialchars( $translation, ENT_QUOTES, 'UTF-8' )
			: htmlspecialchars( $translation, ENT_NOQUOTES, 'UTF-8' );
	}
}
