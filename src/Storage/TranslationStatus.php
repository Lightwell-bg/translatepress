<?php
/**
 * Статусы перевода.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Storage;

defined( 'ABSPATH' ) || exit;

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
	 * Перевод пришёл из языкового пакета WordPress (ТЗ 4.5, код 4).
	 *
	 * Статус ВЫЧИСЛЯЕМЫЙ, а не хранимый: строк, переведённых официальным
	 * пакетом, в нашей базе нет вовсе (см. I18n\GettextRegistry — их туда
	 * намеренно не пишут, иначе словарь распух бы на тысячи строк ядра).
	 * Значит и записать этот статус некуда: он появляется только в
	 * интерфейсе «Перевода строк», чтобы отличить «перевод есть, но не
	 * наш» от «перевода нет вовсе».
	 *
	 * Поэтому его нет в {@see all()}: тот список — allowlist для записи
	 * через REST, и принимать `locale_file` на запись нельзя.
	 */
	public const LOCALE_FILE = 'locale_file';

	/**
	 * Allowlist статусов, ДОПУСТИМЫХ К ЗАПИСИ.
	 *
	 * Вычисляемый LOCALE_FILE сюда не входит намеренно — см. его докблок.
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
			self::MISSING     => __( 'No translation', 'lingocanvas' ),
			self::MACHINE     => __( 'Machine', 'lingocanvas' ),
			self::REVIEW      => __( 'In review', 'lingocanvas' ),
			self::APPROVED    => __( 'Ready', 'lingocanvas' ),
			self::STALE       => __( 'Outdated', 'lingocanvas' ),
			self::REJECTED    => __( 'Rejected', 'lingocanvas' ),
			self::LOCALE_FILE => __( 'From the language pack', 'lingocanvas' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
