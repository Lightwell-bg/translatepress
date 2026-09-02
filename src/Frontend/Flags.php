<?php
/**
 * Картинки флагов для переключателя языков.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Settings\Language;
use WpMlp\Settings\SwitcherDisplay;
use WpMlp\Support\Locale;

/**
 * Ищет файл флага, положенный владельцем сайта.
 *
 * Emoji-флаги (🇷🇺) выглядят решением до первой встречи с Windows: там нет
 * шрифта с флагами вовсе, и посетитель видит две буквы — `RU`, `BG`, `GB`.
 * Поэтому флаг берётся картинкой.
 *
 * Файлы лежат НЕ в каталоге плагина, а в загрузках WordPress: каталог
 * плагина перезаписывается при каждом обновлении, и всё сложенное туда
 * однажды исчезло бы без предупреждения. Имя файла — код языка из
 * настроек: `ru.svg`, `bg.svg`, `pt-br.svg`.
 *
 * SVG выводится через `<img src>`, а не вставкой разметки в страницу.
 * Внутри SVG может быть `<script>`, и при вставке он выполнился бы прямо
 * на странице сайта; в `<img>` браузер скрипты внутри картинки не
 * исполняет.
 */
final class Flags {

	/**
	 * Каталог внутри `wp-content/uploads`.
	 */
	public const DIRECTORY = 'wp-mlp-flags';

	/**
	 * Имя файла флага для кода языка. Чистая функция.
	 *
	 * Пустая строка означает «такого файла быть не может»: код языка не
	 * прошёл проверку, а собирать из него путь нельзя. Проверка здесь
	 * своя, а не «код уже проверен в настройках», потому что результат
	 * уходит в файловую систему — место, где чужой валидации не доверяют.
	 *
	 * @param string $locale Код языка.
	 */
	public static function fileName( string $locale ): string {
		$normalized = Locale::normalize( $locale );

		if ( ! Locale::isValid( $normalized ) ) {
			return '';
		}

		return $normalized . '.svg';
	}

	/**
	 * Путь к каталогу с флагами на диске.
	 */
	public static function directoryPath(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . self::DIRECTORY;
	}

	/**
	 * Адрес каталога с флагами.
	 */
	public static function directoryUrl(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) ) . self::DIRECTORY;
	}

	/**
	 * Готовая подпись языка для переключателя — с картинкой флага, если
	 * она есть и режим её просит.
	 *
	 * Единственное место сборки: подпись нужна и шорткоду-переключателю, и
	 * пунктам меню, и разойтись между собой они не должны.
	 *
	 * @param Language $language Язык.
	 * @param string   $mode     Режим показа.
	 */
	public static function switcherMarkup( Language $language, string $mode ): string {
		$parts = array();

		if ( SwitcherDisplay::showsFlag( $mode ) ) {
			$url = self::url( $language->locale );

			if ( '' !== $url ) {
				/*
				 * `alt` пустой намеренно: рядом уже есть название языка — в
				 * `title` ссылки, а в режиме с кодом ещё и текстом. Второе
				 * имя того же самого заставило бы читалку повторяться.
				 */
				$parts[] = sprintf(
					'<img class="mlp-language-switcher__flag" src="%s" alt="" width="20" height="15" loading="lazy" decoding="async">',
					esc_url( $url )
				);
			} else {
				// Файла для языка нет: остаётся вписанный emoji или код языка.
				$fallback = SwitcherDisplay::fallbackFlag( $language, $mode );

				/*
				 * Пустую замену в список НЕ кладём: части склеиваются
				 * пробелом, и пустой кусок дал бы подпись с пробелом
				 * впереди — ровно то, что видно у языка без флага в
				 * режиме «название».
				 */
				if ( '' !== $fallback ) {
					$parts[] = esc_html( $fallback );
				}
			}
		}

		$text = SwitcherDisplay::text( $language, $mode );

		if ( '' !== $text ) {
			$parts[] = esc_html( $text );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Та же подпись, но без разметки — для мест, где может быть только
	 * текст: `<option>` выпадающего списка картинку не примет.
	 *
	 * @param Language $language Язык.
	 * @param string   $mode     Режим показа.
	 */
	public static function switcherText( Language $language, string $mode ): string {
		$parts = array();

		if ( SwitcherDisplay::showsFlag( $mode ) ) {
			$fallback = SwitcherDisplay::fallbackFlag( $language, $mode );

			// Пустая замена дала бы лишний пробел перед названием.
			if ( '' !== $fallback ) {
				$parts[] = $fallback;
			}
		}

		$text = SwitcherDisplay::text( $language, $mode );

		if ( '' !== $text ) {
			$parts[] = $text;
		}

		$label = trim( implode( ' ', $parts ) );

		// Пустого пункта в списке быть не должно ни при каком режиме.
		return '' !== $label ? $label : $language->label;
	}

	/**
	 * Адрес картинки флага, либо пустая строка, если файла нет.
	 *
	 * @param string $locale Код языка.
	 */
	public static function url( string $locale ): string {
		$name = self::fileName( $locale );

		if ( '' === $name ) {
			return '';
		}

		if ( ! is_readable( self::directoryPath() . '/' . $name ) ) {
			return '';
		}

		return self::directoryUrl() . '/' . $name;
	}
}
