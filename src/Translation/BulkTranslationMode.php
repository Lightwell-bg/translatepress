<?php
/**
 * Режимы «Перевести весь материал с ИИ».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

/**
 * Решает, какие из найденных строк записи вообще нужно отправлять
 * провайдеру — до единого HTTP-вызова к ИИ (ТЗ визуального редактора:
 * «только пустые сегменты» не должен трогать уже переведённое).
 */
final class BulkTranslationMode {

	/** Только строки без перевода. */
	public const EMPTY = 'empty';

	/** Все строки записи, включая уже переведённые и подтверждённые. */
	public const ALL = 'all';

	/**
	 * Допустимые значения для REST-параметра `mode`.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::EMPTY, self::ALL );
	}

	/**
	 * Хеши строк, которые нужно отправить в ИИ в этом режиме.
	 *
	 * В режиме EMPTY уже переведённая строка в отправку не попадает вообще —
	 * не «переводится и отбрасывается результат», а просто не участвует
	 * в запросе к провайдеру, значит и в дневной бюджет символов не входит.
	 *
	 * @param list<string>          $allHashes           Все найденные хеши записи, hex.
	 * @param array<string, string> $existingTranslations Хеш => текущий перевод (может быть '').
	 * @param string                $mode                 self::EMPTY или self::ALL.
	 * @return list<string>
	 */
	public static function selectForTranslation( array $allHashes, array $existingTranslations, string $mode ): array {
		if ( self::ALL === $mode ) {
			return array_values( $allHashes );
		}

		return array_values(
			array_filter(
				$allHashes,
				static fn( string $hash ): bool => '' === trim( (string) ( $existingTranslations[ $hash ] ?? '' ) )
			)
		);
	}
}
