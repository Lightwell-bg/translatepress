<?php
/**
 * Служебные gettext-строки, которые не являются переводом.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

/**
 * Отсеивает msgid, которые технически проходят через `__()`, но текстом
 * для читателя не являются.
 *
 * У ядра WordPress есть строки-НАСТРОЙКИ, оформленные как переводимые:
 * список сокращений для `wptexturize()`, символы тире, форматы даты. Они
 * существуют затем, чтобы переводчик локали подогнал типографику под свой
 * язык, — но владельцу сайта в списке «переведите интерфейс» они не нужны
 * и только прячут за собой настоящие строки.
 *
 * {@see \WpMlp\Support\Text::isTranslatable()} их не ловит: там правило
 * «есть ли хоть одна буква», а в `'tain't,'twere,'twas…` буквы есть.
 * Отличает их не содержимое, а КОНТЕКСТ — ядро в нём прямо пишет, что это
 * список или символ, а не фраза.
 *
 * Список намеренно точный, а не по маске: незнакомый контекст должен
 * попадать в словарь, а не отсеиваться молча. Пропустить лишнюю строку
 * не страшно, потерять нужную — страшно.
 */
final class GettextNoise {

	/**
	 * Контексты ядра, означающие «это настройка, а не текст».
	 *
	 * Сравнение регистронезависимое: контекст пишет разработчик руками, и
	 * у одного и того же смысла в разных версиях ядра регистр разный.
	 */
	private const CONFIG_CONTEXTS = array(
		'comma-separated list of words to texturize in your language',
		'comma-separated list of replacement words in your language',
		'comma-separated list of search stopwords in your language',
		'en dash',
		'em dash',
		'decimal point',
		'thousands separator',
		'word count type. do not translate!',
	);

	/**
	 * Служебная ли это строка. Чистая функция.
	 *
	 * @param string $context Контекст из `_x()`, пустая строка — если его нет.
	 */
	public static function isConfiguration( string $context ): bool {
		if ( '' === $context ) {
			return false;
		}

		return in_array( strtolower( trim( $context ) ), self::CONFIG_CONTEXTS, true );
	}
}
