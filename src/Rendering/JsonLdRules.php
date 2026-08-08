<?php
/**
 * Что именно можно переводить в структурированных данных.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Allowlist полей JSON-LD (ТЗ 8.4: «только разрешённые текстовые поля и URL,
 * без слепого перевода ключей и идентификаторов»).
 *
 * Слепой обход «переводим все строки» здесь недопустим: в графе лежат
 * идентификаторы, типы, даты, адреса картинок и названия организаций.
 * Перевод любого из них ломает микроразметку молча — поисковик просто
 * перестанет её понимать, а в интерфейсе сайта ничего не изменится.
 */
final class JsonLdRules {

	/**
	 * Поля, которые переводятся всегда.
	 *
	 * Это заголовки и описания — то, что человек видит в выдаче.
	 */
	private const ALWAYS = array(
		'headline',
		'alternativeHeadline',
		'description',
		'caption',
		'articleSection',
		'abstract',
		'disambiguatingDescription',
	);

	/**
	 * Типы, у которых `name` — это имя собственное, а не заголовок.
	 *
	 * Название компании, имя автора и марка не переводятся: «CenterAI»
	 * должно остаться «CenterAI» на любом языке.
	 */
	private const NAME_IS_PROPER_NOUN = array(
		'person',
		'organization',
		'corporation',
		'brand',
		'localbusiness',
		'imageobject',
		'sitenavigationelement',
	);

	/**
	 * Поля с адресами: их не переводят, но у них меняется языковой префикс.
	 */
	private const URLS = array( 'url' );

	/**
	 * Поля с постоянным идентификатором сущности.
	 */
	private const ID = array( '@id' );

	/**
	 * Типы, у которых `@id` — это якорь на РЕАЛЬНЫЙ объект (организацию,
	 * автора, сайт целиком), а не на конкретную языковую версию страницы.
	 *
	 * `@id` вида `https://site.ru/#organization` должен быть одинаковым на
	 * всех языках сайта, иначе поисковик решит, что это разные сущности.
	 * Полю не помогает даже то, что плагин никогда не переводит и не
	 * подставляет в него URL напрямую: WordPress сам вызывает `home_url()`
	 * при генерации разметки, а этот вызов уже проходит через наш
	 * собственный фильтр языкового префикса (см.
	 * UrlConverter::filterHomeUrl()) — значит SEO-плагин получает готовый
	 * `@id` вида `.../en/#organization` ещё ДО того, как страница дойдёт до
	 * разбора DOM. Поэтому у этих типов `@id`, в отличие от `url`, не просто
	 * оставляют без изменений, а активно возвращают к виду без префикса.
	 */
	private const STABLE_ID_TYPES = array( 'organization', 'person', 'website' );

	/**
	 * Типы, у которых `@id` — это якорь на КОНКРЕТНУЮ языковую версию
	 * страницы, а не на сущность реального мира.
	 *
	 * Английская и русская версии одной записи — это два разных узла графа
	 * (`WebPage`/`Article`/`BreadcrumbList`), и их `@id` обязан различаться
	 * так же, как различается сам адрес страницы: он получает языковой
	 * префикс совершенно так же, как обычное поле `url` (см. isUrl()).
	 */
	private const PAGE_SCOPED_ID_TYPES = array( 'webpage', 'article', 'blogposting', 'newsarticle', 'breadcrumblist' );

	/**
	 * Типы, у которых `url` — адрес файла, а не страницы.
	 *
	 * Картинку, видео или аудио нельзя переадресовать на языковую версию:
	 * файл в `wp-content/uploads` один на все языки. Добавление префикса
	 * дало бы несуществующий адрес `/en/wp-content/uploads/...` — картинка
	 * пропала бы из превью статьи в соцсетях и из микроразметки.
	 */
	private const MEDIA_TYPES = array(
		'imageobject',
		'videoobject',
		'audioobject',
		'imagegallery',
		'mediaobject',
	);

	/**
	 * Расширения файлов, которые не считаются адресом страницы, даже если
	 * поле называется `url`, а тип объекта не распознан как медиа.
	 *
	 * Вторая, независимая от `@type` защита: тип в графе может быть указан
	 * неточно или отсутствовать, а расширение файла — надёжный сигнал.
	 */
	private const MEDIA_EXTENSIONS = array(
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico',
		'mp4', 'webm', 'mov', 'mp3', 'wav', 'ogg', 'pdf',
	);

	/**
	 * Переводится ли значение поля.
	 *
	 * @param string $key        Имя поля.
	 * @param string $parentType Значение `@type` ближайшего объекта, в нижнем регистре.
	 */
	public static function isTranslatable( string $key, string $parentType ): bool {
		if ( in_array( $key, self::ALWAYS, true ) ) {
			return true;
		}

		if ( 'name' !== $key ) {
			return false;
		}

		return ! in_array( strtolower( $parentType ), self::NAME_IS_PROPER_NOUN, true );
	}

	/**
	 * Является ли поле адресом страницы, который нужно локализовать.
	 *
	 * @param string $key        Имя поля.
	 * @param string $parentType Значение `@type` ближайшего объекта, в нижнем регистре.
	 */
	public static function isUrl( string $key, string $parentType ): bool {
		if ( ! in_array( $key, self::URLS, true ) ) {
			return false;
		}

		return ! in_array( strtolower( $parentType ), self::MEDIA_TYPES, true );
	}

	/**
	 * Является ли поле постоянным `@id` СТАБИЛЬНОЙ сущности (Organization,
	 * Person, WebSite) — его нужно вернуть к виду без языкового префикса.
	 *
	 * @param string $key        Имя поля.
	 * @param string $parentType Значение `@type` ближайшего объекта, в нижнем регистре.
	 */
	public static function isStableId( string $key, string $parentType ): bool {
		if ( ! in_array( $key, self::ID, true ) ) {
			return false;
		}

		return in_array( strtolower( $parentType ), self::STABLE_ID_TYPES, true );
	}

	/**
	 * Является ли поле `@id` СТРАНИЧНОЙ сущности (WebPage, Article,
	 * BreadcrumbList) — его нужно локализовать так же, как `url`.
	 *
	 * @param string $key        Имя поля.
	 * @param string $parentType Значение `@type` ближайшего объекта, в нижнем регистре.
	 */
	public static function isPageScopedId( string $key, string $parentType ): bool {
		if ( ! in_array( $key, self::ID, true ) ) {
			return false;
		}

		return in_array( strtolower( $parentType ), self::PAGE_SCOPED_ID_TYPES, true );
	}

	/**
	 * Похож ли адрес на файл, а не на страницу — по расширению.
	 *
	 * @param string $url Значение поля.
	 */
	public static function looksLikeMediaFile( string $url ): bool {
		$path      = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- класс не зависит от WordPress намеренно, см. заголовок файла.
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::MEDIA_EXTENSIONS, true );
	}
}
