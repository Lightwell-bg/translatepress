<?php
/**
 * Куски gettext-строки, какими они окажутся в готовом HTML.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

use WpMlp\Support\Text;

/**
 * Разбирает переведённую строку на фрагменты, которые реально станут
 * отдельными текстовыми узлами страницы.
 *
 * Нужен затем, чтобы {@see \WpMlp\Rendering\Extractor} не завёл строку
 * интерфейса второй раз, уже как контент. Сравнение целиком тут не
 * работает, и это не мелочь, а самый обычный случай в WordPress:
 *
 * ```
 * msgid: 'Logged in as %1$s. <a href="%2$s">Edit your profile</a>. <a href="%3$s">Log out?</a>'
 * в DOM: «Logged in as centerAI.» / «Edit your profile» / «Log out?»
 * ```
 *
 * После `sprintf()` и разбора HTML от исходной строки не остаётся ни
 * одного узла, равного ей целиком, — а значит поиск по полному совпадению
 * не найдёт ничего, и все три куска осядут в «Контенте» как исходные
 * строки. Ровно это и случилось на живом сайте: болгарский текст попал в
 * словарь исходников.
 *
 * Поэтому запоминается и строка целиком, и её литеральные части — то, что
 * лежит МЕЖДУ плейсхолдерами и тегами и потому доходит до разметки без
 * изменений.
 *
 * Чего этот подход не ловит: кусок, где плейсхолдер стоит внутри той же
 * фразы («Logged in as %s.» → в DOM «Logged in as centerAI.»). Такой узел
 * останется в «Контенте» — неприятно, но безопасно: строка просто
 * окажется не в той вкладке, а не потеряется.
 */
final class GettextFragments {

	/**
	 * Плейсхолдеры printf: `%s`, `%d`, `%1$s`, `%2$04d` и подобные.
	 */
	private const PLACEHOLDER = '/%[0-9]*\$?[+\- 0#\']*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX%]/';

	/**
	 * Нормализованные куски строки, включая её саму. Чистая функция.
	 *
	 * @param string $text Переведённая строка в том виде, в каком её вернул gettext.
	 * @return list<string>
	 */
	public static function of( string $text ): array {
		$fragments = array();

		/*
		 * Строка целиком запоминается, только если в ней нет ни тегов, ни
		 * плейсхолдеров: иначе такого текстового узла в DOM не появится
		 * никогда — там будет либо подставленное значение, либо разорванный
		 * тегами текст, — и хранить её незачем. Хуже того, проверка «есть
		 * ли буквы» пропускает `<b>%s</b>` как переводимую строку: буквы
		 * находятся в именах самих тегов.
		 */
		if ( 1 !== preg_match( '/<[^>]*>/', $text ) && 1 !== preg_match( self::PLACEHOLDER, $text ) ) {
			self::collect( $text, $fragments );
		}

		/*
		 * Теги убираются, а не вырезаются вместе с содержимым: внутри
		 * `<a>Edit your profile</a>` лежит ровно тот текст, который станет
		 * отдельным узлом. Плейсхолдеры, наоборот, делят строку — на их
		 * месте окажется подставленное значение, и текст вокруг разорвётся.
		 */
		$withoutTags = preg_replace( '/<[^>]*>/', "\x1F", $text );
		$parts       = preg_split( self::PLACEHOLDER, (string) $withoutTags );

		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			foreach ( explode( "\x1F", $part ) as $piece ) {
				self::collect( $piece, $fragments );
			}
		}

		return array_values( array_unique( $fragments ) );
	}

	/**
	 * Добавляет кусок, если он вообще похож на переводимый текст.
	 *
	 * Через тот же `Text::isTranslatable()`, что и весь остальной словарь:
	 * обрывки пунктуации между тегами («. », «, ») переводом не являются и
	 * в набор попадать не должны — иначе по ним отсеклось бы что-нибудь
	 * постороннее.
	 *
	 * @param string       $raw       Сырой кусок.
	 * @param list<string> $fragments Накопитель результата.
	 */
	private static function collect( string $raw, array &$fragments ): void {
		$normalized = Text::normalize( $raw );

		if ( '' !== $normalized && Text::isTranslatable( $normalized ) ) {
			$fragments[] = $normalized;
		}
	}
}
