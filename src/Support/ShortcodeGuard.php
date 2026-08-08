<?php
/**
 * Защита шорткодов от порчи машинным переводом.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Проверяет, что вся последовательность шорткодов в переводе — та же, что
 * в исходнике: те же теги, в том же порядке и вложенности, с теми же
 * именами, атрибутами и их значениями.
 *
 * Абзац может нести текст и шорткод вперемешку: «Нажмите [button
 * url="/x"]здесь[/button], чтобы продолжить». Модели инструктируются не
 * трогать `[тег ...]`, но инструкция в системном промпте — не гарантия
 * (ТЗ «не давай ИИ свободно переписывать HTML»). Этот класс — независимая
 * проверка результата, а не доверие к тому, что попросили: при любом
 * расхождении (потерянный тег, переставленный порядок, изменённое имя,
 * добавленный/пропавший/изменённый атрибут или его значение) перевод
 * всего материала считается неуспешным — вызывающий код обязан не
 * сохранить НИ ОДНОГО сегмента из этой операции, а не только отбросить
 * пострадавшую строку.
 */
final class ShortcodeGuard {

	/**
	 * Похож ли текст на WordPress-шорткод: `[tag]`, `[tag attr="x"]`, `[/tag]`.
	 *
	 * @param string $text Строка.
	 */
	public static function containsShortcode( string $text ): bool {
		return array() !== self::parse( $text );
	}

	/**
	 * Сохранилась ли в переводе ТА ЖЕ последовательность шорткодов: те же
	 * теги, в том же порядке (а значит — и с той же вложенностью), с теми
	 * же именами, атрибутами и их значениями.
	 *
	 * Стиль кавычек атрибута (`url="/x"` вместо `url='/x'`) не считается
	 * расхождением: это форматирование, а не изменение самого вызова
	 * шорткода. А вот число, порядок, имена атрибутов и их значения —
	 * считаются, посимвольно.
	 *
	 * @param string $source     Исходный текст.
	 * @param string $translated Перевод от провайдера.
	 */
	public static function isPreserved( string $source, string $translated ): bool {
		return self::parse( $source ) === self::parse( $translated );
	}

	/**
	 * Разбирает текст в список тегов шорткодов по порядку появления.
	 * Чистая функция.
	 *
	 * @param string $text Строка.
	 * @return list<array{closing: bool, self_closing: bool, name: string, attrs: list<array{0: string, 1: string}>}>
	 */
	private static function parse( string $text ): array {
		/*
		 * Блок атрибутов — ленивый квантификатор (`*?`), не жадный: жадный
		 * забрал бы себе завершающий `/` самозакрывающего тега (` /]`), и
		 * self_closing никогда бы не увидел его — группа-кандидат на
		 * атрибуты вправе быть короче полного остатка ровно настолько,
		 * чтобы после неё нашлось место отдельной группе `(\/?)`.
		 */
		$count = preg_match_all(
			'/\[(\/?)([a-zA-Z][\w-]*)((?:\s+[^\[\]]*?)?)(\/?)\]/',
			$text,
			$matches,
			PREG_SET_ORDER
		);

		if ( ! is_int( $count ) || $count < 1 ) {
			return array();
		}

		$tags = array();

		foreach ( $matches as $match ) {
			$tags[] = array(
				'closing'      => '/' === $match[1],
				'self_closing' => '/' === $match[4],
				'name'         => strtolower( $match[2] ),
				'attrs'        => self::parseAttributes( $match[3] ),
			);
		}

		return $tags;
	}

	/**
	 * Разбирает атрибуты внутри одного тега: `key="value"`, `key='value'`,
	 * `key=value` без кавычек и одиночные позиционные значения (WordPress
	 * такое тоже допускает, например `[caption "подпись"]`). Список, а не
	 * ассоциативный массив, — порядок и повторяющиеся ключи тоже должны
	 * совпасть, а не молча схлопнуться. Чистая функция.
	 *
	 * @param string $raw Содержимое тега после имени, до закрывающей `]`.
	 * @return list<array{0: string, 1: string}> Пары «имя (или #позиция) => значение».
	 */
	private static function parseAttributes( string $raw ): array {
		$raw = trim( $raw, " \t\n\r\0\x0B/" );

		if ( '' === $raw ) {
			return array();
		}

		preg_match_all(
			'/([a-zA-Z_][\w-]*)\s*=\s*"([^"]*)"'   // key="value"
			. '|([a-zA-Z_][\w-]*)\s*=\s*\'([^\']*)\'' // key=\'value\'
			. '|([a-zA-Z_][\w-]*)\s*=\s*(\S+)'        // key=value
			. '|"([^"]*)"'                            // "позиционное значение"
			. '|(\S+)/',                               // позиционное значение
			$raw,
			$matches,
			PREG_SET_ORDER
		);

		$attrs    = array();
		$position = 0;

		foreach ( $matches as $match ) {
			// PREG_SET_ORDER с несколькими альтернативами: группы после той,
			// что реально совпала в ЭТОМ вхождении, в массиве может не быть
			// вовсе (не просто пустая строка) — поэтому везде ?? '', а не
			// прямое обращение по индексу.
			$key1   = $match[1] ?? '';
			$key3   = $match[3] ?? '';
			$key5   = $match[5] ?? '';
			$value7 = $match[7] ?? '';
			$value8 = $match[8] ?? '';

			if ( '' !== $key1 ) {
				$attrs[] = array( strtolower( $key1 ), $match[2] ?? '' );
			} elseif ( '' !== $key3 ) {
				$attrs[] = array( strtolower( $key3 ), $match[4] ?? '' );
			} elseif ( '' !== $key5 ) {
				$attrs[] = array( strtolower( $key5 ), $match[6] ?? '' );
			} else {
				$attrs[] = array( '#' . $position, '' !== $value7 ? $value7 : $value8 );
				++$position;
			}
		}

		return $attrs;
	}
}
