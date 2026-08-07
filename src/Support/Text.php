<?php
/**
 * Нормализация строк и решение, что вообще переводить.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Правила приведения текста к ключу словаря.
 *
 * Ключом словаря должна быть строка, а не её случайное форматирование:
 * `"\n   Привет\n"` и `"Привет"` — одна и та же фраза, и переводить её дважды
 * нельзя. Поэтому в БД попадает нормализованный вид, а исходные отступы узла
 * запоминаются отдельно и возвращаются на место при подстановке.
 */
final class Text {

	/**
	 * Пробельные символы Unicode, которые нормализуются в обычный пробел.
	 *
	 * Неразрывный пробел визуально неотличим от обычного, но даёт другой хеш —
	 * без этой замены словарь распух бы дублями.
	 */
	private const SPACES = array(
		"\u{00A0}", // no-break space
		"\u{2007}", // figure space
		"\u{202F}", // narrow no-break space
		"\u{FEFF}", // zero width no-break space
	);

	/**
	 * Приводит текст к каноническому виду: единые пробелы, без краевых отступов.
	 *
	 * @param string $raw Исходное содержимое узла или атрибута.
	 */
	public static function normalize( string $raw ): string {
		$value = str_replace( self::SPACES, ' ', $raw );
		$value = (string) preg_replace( '/\s+/u', ' ', $value );

		return trim( $value );
	}

	/**
	 * Делит содержимое узла на краевые отступы и смысловую часть.
	 *
	 * Отступы нужны, чтобы `<p>Привет <b>мир</b></p>` после перевода не
	 * превратился в `<p>Hello<b>world</b></p>` без пробела.
	 *
	 * @param string $raw Исходное содержимое текстового узла.
	 * @return array{0: string, 1: string, 2: string} Отступ слева, ядро, отступ справа.
	 */
	public static function splitEdges( string $raw ): array {
		if ( 1 !== preg_match( '/^(\s*)(.*?)(\s*)$/su', $raw, $matches ) ) {
			return array( '', $raw, '' );
		}

		return array( $matches[1], $matches[2], $matches[3] );
	}

	/**
	 * Стоит ли отправлять строку в словарь.
	 *
	 * Отсеивается всё, что перевод только испортит: числа, адреса, имена
	 * файлов, шаблонные плейсхолдеры и нераспознанные шорткоды (ТЗ 4.4).
	 *
	 * @param string $text Нормализованная строка.
	 */
	public static function isTranslatable( string $text ): bool {
		if ( '' === $text ) {
			return false;
		}

		// Без единой буквы переводить нечего: «2024», «19,90 ₽», «—», «•».
		if ( 1 !== preg_match( '/\p{L}/u', $text ) ) {
			return false;
		}

		// Адреса и схемы: они локализуются маршрутизатором, а не переводчиком.
		if ( 1 === preg_match( '#^(?:https?:)?//#i', $text ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^(?:mailto|tel|javascript|data|ftp):/i', $text ) ) {
			return false;
		}

		// Одинокое имя файла или домена.
		if ( 1 === preg_match( '/^[\w.-]+\.(?:[a-z]{2,6})$/i', $text ) && ! str_contains( $text, ' ' ) ) {
			return false;
		}

		// Шаблонные плейсхолдеры: {{name}}, {count}, %1$s.
		if ( 1 === preg_match( '/^\{\{.*\}\}$/su', $text ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^\{[^\s{}]*\}$/su', $text ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^%\d*\$?[sdf]$/', $text ) ) {
			return false;
		}

		// Необработанный шорткод целиком: [contact-form-7 id="1"].
		if ( 1 === preg_match( '/^\[[^\]]+\]$/su', $text ) ) {
			return false;
		}

		return true;
	}
}
