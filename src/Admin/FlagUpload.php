<?php
/**
 * Загрузка картинки флага из админки.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use WpMlp\Frontend\Flags;

/**
 * Принимает SVG-файл флага, чистит его и кладёт в загрузки.
 *
 * SVG — не картинка в привычном смысле, а XML с полноценным доступом к
 * DOM: внутри бывают `<script>`, обработчики `onload`, ссылки
 * `javascript:` и `<foreignObject>` с целым HTML-документом. Файл после
 * загрузки лежит в `wp-content/uploads` и открывается по прямой ссылке —
 * то есть скрипт из него выполнится на домене сайта, с правами того, кто
 * эту ссылку открыл. Именно поэтому WordPress не разрешает загрузку SVG
 * без отдельного плагина.
 *
 * Поэтому здесь не «проверка формата», а вычистка: файл разбирается,
 * опасное удаляется, и на диск попадает уже безопасная версия. Что не
 * разобралось как SVG — не сохраняется вовсе.
 */
final class FlagUpload {

	/**
	 * Предельный размер файла.
	 *
	 * Флаг из типового набора весит 1–3 КБ; сюда с запасом влезает даже
	 * подробный герб. Всё, что крупнее, — это загруженное по ошибке фото
	 * или попытка занять место на диске.
	 */
	public const MAX_BYTES = 64000;

	/**
	 * Элементы, которых во флаге быть не может ни при каких условиях.
	 */
	private const FORBIDDEN_TAGS = array(
		'script',
		'foreignobject',
		'iframe',
		'embed',
		'object',
		'handler',
		'set',
		'animate',
		'audio',
		'video',
	);

	/**
	 * Атрибуты со ссылками: в них прячется `javascript:`.
	 */
	private const URL_ATTRIBUTES = array( 'href', 'xlink:href', 'src', 'from', 'to', 'values' );

	/**
	 * Чистит содержимое SVG. Чистая функция.
	 *
	 * Пустая строка означает «сохранять нечего»: файл не разобрался как
	 * SVG, оказался слишком большим или после вычистки от него ничего не
	 * осталось.
	 *
	 * @param string $content Содержимое загруженного файла.
	 */
	public static function sanitize( string $content ): string {
		$content = trim( $content );

		if ( '' === $content || strlen( $content ) > self::MAX_BYTES ) {
			return '';
		}

		/*
		 * Объявление сущностей — признак попытки прочитать файлы сервера
		 * через картинку (XXE). Файл отвергается целиком, а не чистится:
		 * во флаге таким записям делать нечего, и «починить» подозрительный
		 * файл — значит принять его.
		 *
		 * Проверено на этой машине (libxml 2.11.9): свежий libxml внешние
		 * сущности и сам не грузит — loadXML() возвращает false. Но версия
		 * библиотеки на чужом хостинге не наша забота и не наша гарантия, а
		 * проверка стоит одну строку. Мутационная проверка её, что честно,
		 * не ловит: на современном libxml обе ветки дают один результат.
		 */
		if ( 1 === preg_match( '/<!ENTITY/i', $content ) ) {
			return '';
		}

		$document                     = new DOMDocument();
		$document->preserveWhiteSpace = false;

		$previous = libxml_use_internal_errors( true );

		// LIBXML_NONET запрещает любые обращения по сети во время разбора.
		$loaded = $document->loadXML( $content, LIBXML_NONET | LIBXML_NOENT );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || null === $document->documentElement ) {
			return '';
		}

		if ( 'svg' !== strtolower( $document->documentElement->nodeName ) ) {
			return '';
		}

		self::clean( $document->documentElement );

		$svg = $document->saveXML( $document->documentElement );

		return is_string( $svg ) ? $svg : '';
	}

	/**
	 * Рекурсивно убирает из узла всё исполняемое.
	 *
	 * @param DOMNode $node Узел.
	 */
	private static function clean( DOMNode $node ): void {
		// Обход с конца: удаление узла сдвигает live-коллекцию childNodes.
		for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) {
			$child = $node->childNodes->item( $i );

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( in_array( strtolower( $child->nodeName ), self::FORBIDDEN_TAGS, true ) ) {
				$node->removeChild( $child );

				continue;
			}

			self::clean( $child );
		}

		if ( $node instanceof DOMElement ) {
			self::cleanAttributes( $node );
		}
	}

	/**
	 * Убирает опасные атрибуты элемента.
	 *
	 * @param DOMElement $element Элемент.
	 */
	private static function cleanAttributes( DOMElement $element ): void {
		for ( $i = $element->attributes->length - 1; $i >= 0; $i-- ) {
			$attribute = $element->attributes->item( $i );

			if ( ! $attribute instanceof DOMAttr ) {
				continue;
			}

			$name  = strtolower( $attribute->nodeName );
			$value = $attribute->nodeValue ?? '';

			// Любой обработчик события: onload, onclick, onmouseover и прочие.
			if ( str_starts_with( $name, 'on' ) ) {
				$element->removeAttributeNode( $attribute );

				continue;
			}

			if ( in_array( $name, self::URL_ATTRIBUTES, true ) && self::isDangerousUrl( $value ) ) {
				$element->removeAttributeNode( $attribute );

				continue;
			}

			/*
			 * `style` может нести `url(javascript:…)` и внешние загрузки.
			 * Флагу хватает атрибутов fill/stroke, поэтому проще убрать
			 * такой style целиком, чем разбирать CSS.
			 */
			if ( 'style' === $name && self::isDangerousUrl( $value ) ) {
				$element->removeAttributeNode( $attribute );
			}
		}
	}

	/**
	 * Похоже ли значение на исполняемую или внешнюю ссылку. Чистая функция.
	 *
	 * @param string $value Значение атрибута.
	 */
	private static function isDangerousUrl( string $value ): bool {
		// Пробелы и переводы строк внутри схемы — обычный приём обхода.
		$normalized = strtolower( (string) preg_replace( '/\s+/', '', $value ) );

		foreach ( array( 'javascript:', 'data:text/html', 'vbscript:' ) as $scheme ) {
			if ( str_contains( $normalized, $scheme ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Сохраняет очищенный флаг для языка.
	 *
	 * @param string $locale  Код языка.
	 * @param string $content Содержимое загруженного файла.
	 * @return bool Удалось ли сохранить.
	 */
	public static function store( string $locale, string $content ): bool {
		$name = Flags::fileName( $locale );
		$svg  = self::sanitize( $content );

		if ( '' === $name || '' === $svg ) {
			return false;
		}

		$directory = Flags::directoryPath();

		if ( ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		return false !== file_put_contents( $directory . '/' . $name, $svg );
	}

	/**
	 * Удаляет флаг языка.
	 *
	 * @param string $locale Код языка.
	 */
	public static function remove( string $locale ): bool {
		$name = Flags::fileName( $locale );

		if ( '' === $name ) {
			return false;
		}

		$path = Flags::directoryPath() . '/' . $name;

		return is_file( $path ) && unlink( $path );
	}
}
