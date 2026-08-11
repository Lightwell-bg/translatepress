<?php
/**
 * Записи «места использования» строк массового перевода записи.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

use WpMlp\Rendering\PostSegment;
use WpMlp\Support\Hash;

/**
 * Строит строки для `OccurrenceRepository::insertMany()` — по одной на
 * уникальный сегмент, найденный при разборе записи для «Перевести весь
 * материал с ИИ».
 *
 * `uniq_hash` обязан включать `object_id` (id самой записи) — без этого
 * поля две РАЗНЫЕ записи с одинаковым абзацем текста (например, общая
 * фраза-заготовка, повторяющаяся в нескольких статьях) вычислили бы один
 * и тот же `uniq_hash`, и `INSERT ... ON DUPLICATE KEY UPDATE` в
 * `occurrences` (уникальный индекс — ровно `uniq_hash`, см. Schema.php)
 * тихо слил бы их в одну строку: право владения сегментом навсегда
 * осталось бы у той записи, что зарегистрировала его первой, а у второй
 * `OccurrenceRepository::belongsToObject()` начал бы возвращать `false` —
 * `PostCommitValidator` отклонял бы такой сегмент как `foreign_segment`,
 * хотя он действительно принадлежит и этой записи тоже.
 *
 * Вынесено из `PostTranslationController::registerDiscovered()` отдельно,
 * чтобы это правило проверялось юнит-тестом без `$wpdb`.
 */
final class PostOccurrenceRows {

	/**
	 * @param array<string, PostSegment> $unique Уникальные сегменты записи, ключ — uniq_hash.
	 * @param array<string, int>         $ids    uniq_hash => id строки в sources.
	 * @param int                        $postId Идентификатор записи.
	 * @return list<array{source_id: int, object_type: string, object_id: int, url_hash: null, attribute_name: ?string, uniq_hash: string}>
	 */
	public static function build( array $unique, array $ids, int $postId ): array {
		$rows = array();

		foreach ( $unique as $hash => $postSegment ) {
			if ( ! isset( $ids[ $hash ] ) ) {
				continue;
			}

			$rows[] = array(
				'source_id'      => $ids[ $hash ],
				'object_type'    => 'post',
				'object_id'      => $postId,
				'url_hash'       => null,
				'attribute_name' => $postSegment->segment->attribute,
				'uniq_hash'      => Hash::ofParts(
					array( $ids[ $hash ], 'post', (string) $postId, (string) $postSegment->segment->attribute )
				),
			);
		}

		return $rows;
	}
}
