<?php
/**
 * Проверка пакета переводов перед сохранением — всё или ничего.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

use WpMlp\Rendering\BlockSanitizer;
use WpMlp\Rendering\Segment;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Support\Hash;
use WpMlp\Support\ShortcodeGuard;

/**
 * Решает — до единой записи в БД — можно ли вообще сохранять пакет
 * переводов «Перевести весь материал с ИИ».
 *
 * Чистая функция без $wpdb: проверка отдельно от транзакции ровно затем,
 * чтобы её можно было прогнать в тесте без реальной базы. Атомарность
 * коммита складывается из двух независимых гарантий: эта проверка не
 * пропускает НИ ОДНОЙ записи дальше, если хоть один сегмент пакета не
 * прошёл хоть одну проверку (несуществующий id, чужая запись, задвоенный
 * id, испорченный шорткод) — а транзакция в контроллере откатывает уже
 * начатую запись, если по пути откажет сама БД. Здесь — первая линия;
 * вторая — в PostTranslationController::commit().
 */
final class PostCommitValidator {

	/** Пусто прислали. */
	public const ERROR_EMPTY = 'empty_segments';

	/** Прислали больше, чем разрешено за одну операцию. */
	public const ERROR_TOO_MANY = 'too_many_segments';

	/** uniq_hash не похож на валидный hex-хеш. */
	public const ERROR_INVALID_ID = 'invalid_id';

	/** Тот же uniq_hash встретился в пакете дважды. */
	public const ERROR_DUPLICATE = 'duplicate_segment';

	/** uniq_hash не найден среди строк, реально извлечённых из этой записи. */
	public const ERROR_UNKNOWN = 'unknown_segment';

	/** Строка найдена, но принадлежит другой записи. */
	public const ERROR_FOREIGN = 'foreign_segment';

	/** Перевод меняет последовательность шорткодов исходника. */
	public const ERROR_SHORTCODE = 'shortcode_mismatch';

	/**
	 * @param list<array<string, mixed>>                                  $segments      Сырые строки из тела запроса.
	 * @param array<string, array{id: int, kind: string, source_text: string}> $knownSources uniq_hash => строка sources, уже найденная в БД.
	 * @param callable(int $sourceId): bool                                $belongsToPost Проверка «эта строка действительно из этой записи».
	 * @param int                                                          $maxSegments   Верхняя граница размера пакета.
	 * @return array{ok: bool, errors: list<string>, rows: list<array{id: int, kind: string, text: string, status: string}>}
	 */
	public static function validate(
		array $segments,
		array $knownSources,
		callable $belongsToPost,
		int $maxSegments = 400
	): array {
		if ( array() === $segments ) {
			return array( 'ok' => false, 'errors' => array( self::ERROR_EMPTY ), 'rows' => array() );
		}

		if ( count( $segments ) > $maxSegments ) {
			return array( 'ok' => false, 'errors' => array( self::ERROR_TOO_MANY ), 'rows' => array() );
		}

		$errors = array();
		$rows   = array();
		$seen   = array();

		foreach ( $segments as $segment ) {
			$hash = is_array( $segment ) ? (string) ( $segment['uniq_hash'] ?? '' ) : '';

			if ( ! Hash::isValid( $hash ) ) {
				$errors[] = self::ERROR_INVALID_ID;
				continue;
			}

			if ( isset( $seen[ $hash ] ) ) {
				$errors[] = self::ERROR_DUPLICATE . ':' . $hash;
				continue;
			}

			$seen[ $hash ] = true;

			$source = $knownSources[ $hash ] ?? null;

			if ( null === $source ) {
				$errors[] = self::ERROR_UNKNOWN . ':' . $hash;
				continue;
			}

			if ( ! $belongsToPost( $source['id'] ) ) {
				$errors[] = self::ERROR_FOREIGN . ':' . $hash;
				continue;
			}

			$text = trim( (string) ( $segment['translated_text'] ?? '' ) );

			if ( '' !== $text
				&& ShortcodeGuard::containsShortcode( $source['source_text'] )
				&& ! ShortcodeGuard::isPreserved( $source['source_text'], $text )
			) {
				$errors[] = self::ERROR_SHORTCODE . ':' . $hash;
				continue;
			}

			$text = Segment::KIND_HTML_BLOCK === $source['kind']
				? BlockSanitizer::sanitize( $text )
				: wp_strip_all_tags( $text );

			$status = (string) ( $segment['status'] ?? '' );

			if ( ! TranslationStatus::isValid( $status ) ) {
				$status = '' !== $text ? TranslationStatus::APPROVED : TranslationStatus::MISSING;
			}

			$rows[] = array(
				'id'     => $source['id'],
				'kind'   => $source['kind'],
				'text'   => $text,
				'status' => $status,
			);
		}

		if ( array() !== $errors ) {
			return array( 'ok' => false, 'errors' => $errors, 'rows' => array() );
		}

		return array( 'ok' => true, 'errors' => array(), 'rows' => $rows );
	}
}
