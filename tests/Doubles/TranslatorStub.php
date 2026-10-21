<?php
/**
 * Подмена финального класса Translator для тестов перехвата вывода.
 *
 * Класс объявлен тем же именем, что и настоящий, поэтому подключать файл
 * можно только в отдельном процессе PHPUnit (#[RunInSeparateProcess]), где
 * настоящий Translator ещё не загружен автозагрузчиком.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Rendering;

use LingoCanvas\Settings\Language;

/**
 * «Переводит» Hello в Привет; строка THROW вызывает исключение.
 */
final class Translator {

	/**
	 * Сколько раз вызывался перевод.
	 */
	public static int $calls = 0;

	/**
	 * @param string   $html   Исходный HTML.
	 * @param Language $target Язык перевода.
	 */
	public function translateHtml( string $html, Language $target ): ?string {
		unset( $target );

		++self::$calls;

		if ( str_contains( $html, 'THROW' ) ) {
			throw new \RuntimeException( 'translator failure' );
		}

		return str_replace( 'Hello', 'Привет', $html );
	}
}
