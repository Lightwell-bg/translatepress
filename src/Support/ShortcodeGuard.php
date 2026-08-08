<?php
/**
 * Защита шорткодов от порчи машинным переводом.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Проверяет, что теги шорткодов в переводе — те же и в том же порядке, что
 * в исходнике.
 *
 * Абзац может нести текст и шорткод вперемешку: «Нажмите [button
 * url="/x"]здесь[/button], чтобы продолжить». Модели инструктируются не
 * трогать `[тег ...]`, но инструкция в системном промпте — не гарантия
 * (ТЗ «не давай ИИ свободно переписывать HTML»). Этот класс — независимая
 * проверка результата, а не доверие к тому, что попросили: перевод, в
 * котором шорткод пропал или исказился, отбрасывается вызывающим кодом,
 * а не сохраняется как есть.
 */
final class ShortcodeGuard {

	/**
	 * Похож ли текст на WordPress-шорткод: `[tag]`, `[tag attr="x"]`, `[/tag]`.
	 *
	 * @param string $text Строка.
	 */
	public static function containsShortcode( string $text ): bool {
		return 1 === preg_match( '/\[\/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?\]/', $text );
	}

	/**
	 * Список тегов шорткодов в порядке появления. Чистая функция.
	 *
	 * @param string $text Строка.
	 * @return list<string>
	 */
	public static function tags( string $text ): array {
		$count = preg_match_all( '/\[\/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?\]/', $text, $matches );

		if ( ! is_int( $count ) || $count < 1 ) {
			return array();
		}

		return $matches[0];
	}

	/**
	 * Сохранились ли теги шорткодов в переводе — тот же набор, в том же порядке.
	 *
	 * Атрибуты внутри тега (кавычки, пробелы) не сравниваются побайтово:
	 * модель может слегка переформатировать `[b url="/x"]` в `[b url='/x']`
	 * без вреда для работы шорткода. Важно, что сам список тегов, их имена
	 * и порядок не изменились — то есть шорткод не потерялся и не задвоился.
	 *
	 * @param string $source     Исходный текст.
	 * @param string $translated Перевод от провайдера.
	 */
	public static function isPreserved( string $source, string $translated ): bool {
		return self::tagNames( $source ) === self::tagNames( $translated );
	}

	/**
	 * Имена тегов (без атрибутов) в порядке появления. Чистая функция.
	 *
	 * @param string $text Строка.
	 * @return list<string>
	 */
	private static function tagNames( string $text ): array {
		return array_map(
			static function ( string $tag ): string {
				$name = (string) preg_replace( '/^\[(\/?[a-zA-Z][\w-]*).*$/s', '$1', $tag );

				return strtolower( $name );
			},
			self::tags( $text )
		);
	}
}
