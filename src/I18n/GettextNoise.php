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
	 * Контексты строк, которые видит только редактор, а не посетитель.
	 *
	 * WordPress регистрирует блоки на КАЖДОМ запросе, включая фронтенд, и
	 * их названия, описания и ключевые слова проходят через gettext всегда
	 * — хотя показываются исключительно в редакторе. Для того, кто
	 * переводит сайт, это чистый шум: `Breadcrumbs`, `Author (deprecated)`,
	 * `atom`, `hr` посетитель не увидит ни при каких условиях.
	 *
	 * Здесь сравнение по началу контекста, а не точное: у блочных строк
	 * контекст всегда начинается с `block `, а конкретных вариантов ядро
	 * добавляет всё новые (`block title`, `block description`,
	 * `block keyword`, `block style label`, `block variation title`…).
	 */
	private const EDITOR_CONTEXT_PREFIXES = array(
		'block ',
		'pattern ',
		'font collection ',
		'rest api ',
		'taxonomy ',
		'post type ',
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

		$context = strtolower( trim( $context ) );

		if ( in_array( $context, self::CONFIG_CONTEXTS, true ) ) {
			return true;
		}

		foreach ( self::EDITOR_CONTEXT_PREFIXES as $prefix ) {
			if ( str_starts_with( $context, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
