<?php
/**
 * Статусы перевода.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Допустимые значения колонки `status` (ТЗ 6.2).
 */
final class TranslationStatus {

	/** Строка найдена, перевода нет. */
	public const MISSING = 'missing';

	/** Перевод получен машинно и не проверялся человеком. */
	public const MACHINE = 'machine';

	/** Перевод ждёт проверки. */
	public const REVIEW = 'review';

	/** Перевод подтверждён человеком. */
	public const APPROVED = 'approved';

	/** Исходная строка изменилась — перевод может быть неактуален. */
	public const STALE = 'stale';

	/** Перевод отклонён. */
	public const REJECTED = 'rejected';

	/**
	 * Allowlist для REST и админки.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::MISSING,
			self::MACHINE,
			self::REVIEW,
			self::APPROVED,
			self::STALE,
			self::REJECTED,
		);
	}

	/**
	 * Допустим ли статус.
	 *
	 * @param string $status Проверяемое значение.
	 */
	public static function isValid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Человекочитаемое название.
	 *
	 * @param string $status Статус.
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::MISSING  => __( 'Нет перевода', 'wp-mlp' ),
			self::MACHINE  => __( 'Машинный', 'wp-mlp' ),
			self::REVIEW   => __( 'На проверке', 'wp-mlp' ),
			self::APPROVED => __( 'Готов', 'wp-mlp' ),
			self::STALE    => __( 'Устарел', 'wp-mlp' ),
			self::REJECTED => __( 'Отклонён', 'wp-mlp' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
