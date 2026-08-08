<?php
/**
 * Переводимый сегмент записи целиком: заголовок, excerpt или содержимое.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Обёртка над {@see Segment} с полем-источником.
 *
 * `field` — не часть идентичности строки (в `uniq_hash` не участвует):
 * это только подпись для интерфейса «Визуального редактора», группирующего
 * список на заголовок/анонс/содержимое. Одна и та же фраза в заголовке и
 * в тексте статьи — всё равно одна строка словаря, с одним переводом.
 */
final class PostSegment {

	/** Сегмент из заголовка записи. */
	public const FIELD_TITLE = 'title';

	/** Сегмент из анонса (excerpt). */
	public const FIELD_EXCERPT = 'excerpt';

	/** Сегмент из тела записи. */
	public const FIELD_CONTENT = 'content';

	/**
	 * @param string  $field   FIELD_* — где на странице встретилась строка.
	 * @param Segment $segment Извлечённый сегмент — тот же класс, что и для
	 *                         разбора живой страницы, с тем же uniq_hash.
	 */
	public function __construct(
		public readonly string $field,
		public readonly Segment $segment
	) {
	}
}
