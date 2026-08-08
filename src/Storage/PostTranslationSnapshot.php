<?php
/**
 * Слепок переведённых строк записи — для отметки «материал изменился».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Запоминает, какие строки записи были переведены при последнем массовом
 * сохранении «Перевести весь материал с ИИ», и находит разницу с текущим
 * набором (ТЗ визуального редактора: «при изменении оригинала помечай
 * только изменившиеся сегменты, не сбрасывая весь готовый перевод»).
 *
 * Отдельной таблицы не заводится: `uniq_hash` уже и есть идентичность
 * строки, завязанная на её нормализованный текст (см. Hash::ofParts() в
 * Extractor). Если абзац не менялся — при повторном разборе получится тот
 * же хеш, значит найдётся та же строка в `sources` с уже готовым переводом:
 * «не сбрасывать готовый перевод» тут получается само, без специального
 * флага. А вот «пометить только изменившееся» само по себе не получается:
 * без списка «что было в прошлый раз» не отличить давно висящую
 * непереведённую строку от той, что появилась взамен отредактированного
 * абзаца только что. Слепок — это и есть память о прошлом разе: список
 * hex-хешей, сохранённый в postmeta.
 */
final class PostTranslationSnapshot {

	private const META_PREFIX = '_mlp_translated_segments_';

	/**
	 * Хеши строк, сохранённые при прошлом массовом переводе записи.
	 *
	 * @param int    $postId Идентификатор записи.
	 * @param string $locale Целевой язык.
	 * @return list<string>
	 */
	public function hashesFor( int $postId, string $locale ): array {
		$stored = get_post_meta( $postId, self::metaKey( $locale ), true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Запоминает набор строк текущего успешного сохранения.
	 *
	 * @param int          $postId Идентификатор записи.
	 * @param string       $locale Целевой язык.
	 * @param list<string> $hashes Hex uniq_hash сохранённых строк.
	 */
	public function save( int $postId, string $locale, array $hashes ): void {
		update_post_meta( $postId, self::metaKey( $locale ), array_values( array_unique( $hashes ) ) );
	}

	/**
	 * Строки, которых не было в прошлом слепке — новые или изменившиеся с
	 * последнего массового перевода. Чистая функция.
	 *
	 * @param list<string> $currentHashes  Хеши, найденные сейчас.
	 * @param list<string> $previousHashes Хеши из прошлого слепка.
	 * @return array<string, true> Множество хешей для быстрой проверки `isset()`.
	 */
	public static function changed( array $currentHashes, array $previousHashes ): array {
		$previous = array_fill_keys( $previousHashes, true );

		$changed = array();

		foreach ( $currentHashes as $hash ) {
			if ( ! isset( $previous[ $hash ] ) ) {
				$changed[ $hash ] = true;
			}
		}

		return $changed;
	}

	/**
	 * Ключ postmeta для языка. Чистая функция.
	 *
	 * @param string $locale Целевой язык.
	 */
	private static function metaKey( string $locale ): string {
		return self::META_PREFIX . $locale;
	}
}
