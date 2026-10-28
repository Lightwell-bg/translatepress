<?php
/**
 * Куски gettext-строки, какими они окажутся в готовом HTML.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\I18n;

use LingoCanvas\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Разбирает переведённую строку на фрагменты, которые реально станут
 * отдельными текстовыми узлами страницы.
 *
 * Нужен затем, чтобы {@see \LingoCanvas\Rendering\Extractor} не завёл строку
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
 * Куски запоминаются с раскрытыми HTML-сущностями (`&rarr;` → «→»): в DOM
 * текстовые узлы уже раскрыты, а gettext отдаёт строку как HTML-источник.
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
	 * Регулярное выражение для строки с плейсхолдерами, подставленными числами.
	 *
	 * Фрагменты ({@see self::of()}) не ловят «13 Minuten», полученное из
	 * `_n( '%d minute', '%d minutes' )`: узел в DOM содержит число внутри
	 * фразы. Поэтому для шаблона строится якорное выражение, где каждый
	 * плейсхолдер — это ЧИСЛО, а буквенная часть дословная. Плейсхолдер не
	 * заменяется на «что угодно»: иначе «%s at %s» съело бы любой контент.
	 *
	 * HTML-сущности перед сборкой раскрываются (`%d comments &rarr;` совпадёт
	 * с узлом DOM «5 comments →»).
	 *
	 * Null — если шаблон не подходит: плейсхолдеров нет (работает точное
	 * совпадение), в разметке есть теги, либо в литеральной части нет букв
	 * («%s», «%1$s – %2$s», «%d»).
	 *
	 * @param string $text Переведённая строка в том виде, в каком её вернул gettext.
	 */
	public static function numericPattern( string $text ): ?string {
		if ( 1 === preg_match( '/<[^>]*>/', $text ) ) {
			return null;
		}

		$normalized = Text::normalize( self::decodeEntities( $text ) );

		$count = preg_match_all( self::PLACEHOLDER, $normalized, $matches, PREG_OFFSET_CAPTURE );

		if ( false === $count || 0 === $count ) {
			return null;
		}

		$pattern     = '';
		$literal     = '';
		$offset      = 0;
		$placeholder = false;

		foreach ( $matches[0] as $match ) {
			list( $token, $position ) = $match;

			$chunk    = substr( $normalized, $offset, $position - $offset );
			$offset   = $position + strlen( $token );
			$literal .= $chunk;
			$pattern .= preg_quote( $chunk, '/' );

			if ( '%%' === $token ) {
				// «%%» — это просто знак процента.
				$literal .= '%';
				$pattern .= '%';

				continue;
			}

			$placeholder = true;
			$pattern    .= '[0-9]+(?:[.,][0-9]+)*';
		}

		$tail     = substr( $normalized, $offset );
		$literal .= $tail;
		$pattern .= preg_quote( $tail, '/' );

		if ( ! $placeholder || 1 !== preg_match( '/\p{L}/u', $literal ) ) {
			return null;
		}

		return '/^' . $pattern . '$/u';
	}

	/**
	 * Раскрывает HTML-сущности (`&rarr;`, `&hellip;`, `&#8217;`, `&amp;`, `&nbsp;`).
	 *
	 * Gettext отдаёт строку как HTML-источник, а текстовые узлы DOM, с которыми
	 * её сравнивает Extractor, уже раскрыты: «hier &rarr;» в разметке — это
	 * «hier →». Без раскрытия строка темы с сущностью не совпадала и оседала
	 * в «Строках» как исходная. Вызывается только после отделения тегов:
	 * иначе `&lt;b&gt;` превратилось бы в ненастоящий тег.
	 *
	 * @param string $text Кусок строки без тегов.
	 */
	private static function decodeEntities( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
		$normalized = Text::normalize( self::decodeEntities( $raw ) );

		if ( '' !== $normalized && Text::isTranslatable( $normalized ) ) {
			$fragments[] = $normalized;
		}
	}
}
