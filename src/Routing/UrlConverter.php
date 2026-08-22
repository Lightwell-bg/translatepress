<?php
/**
 * Построение языковых URL.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Routing;

use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * Добавляет языковой префикс к адресам, которые генерирует WordPress.
 *
 * Фильтра `home_url` достаточно почти для всего: permalink записей, страниц,
 * рубрик и меню строятся поверх него (ТЗ 4.2/11). Отдельные методы нужны для
 * переключателя языков и hreflang, где адрес строится для чужого языка.
 */
final class UrlConverter implements Hookable {

	/**
	 * Первые сегменты пути, которые никогда не переводятся в языковые URL.
	 *
	 * Это служебные адреса ядра: их префикс сломал бы REST, загрузку файлов
	 * и вход в админку.
	 */
	private const RESERVED_SEGMENTS = array(
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login.php',
		'wp-cron.php',
		'xmlrpc.php',
	);

	/**
	 * @param Settings         $settings Настройки плагина.
	 * @param LanguageResolver $resolver Язык текущего запроса.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguageResolver $resolver
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'home_url', array( $this, 'filterHomeUrl' ), 10, 2 );
	}

	/**
	 * Подставляет префикс текущего языка в адреса, построенные через home_url().
	 *
	 * @param string $url  Готовый адрес.
	 * @param string $path Путь, переданный в home_url().
	 */
	public function filterHomeUrl( $url, $path = '' ): string {
		$url = (string) $url;

		// В админке ссылки должны оставаться исходными: там редактируется оригинал.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $url;
		}

		$language = $this->resolver->current();

		if ( $language->isDefault ) {
			return $url;
		}

		unset( $path );

		return $this->addPrefixToUrl( $url, $language->slug );
	}

	/**
	 * Слаги всех языков сайта, включая черновики, в нижнем регистре.
	 *
	 * Нужны, чтобы отличить сегмент пути, уже занятый ДРУГИМ языком (например
	 * `/ru/...` в ссылке пункта меню, сохранённой ещё до того, как на сайте
	 * появился этот плагин, или до того, как русский стал языком по
	 * умолчанию), от обычного сегмента вроде `/kontakty/`. Первый нужно
	 * ЗАМЕНИТЬ на префикс целевого языка, а не нарастить префикс поверх —
	 * иначе получается `/en/ru/...` (см. addPrefixToPath()).
	 *
	 * @return list<string>
	 */
	public function knownSlugs(): array {
		return array_values(
			array_unique(
				array_map(
					static fn( Language $language ): string => strtolower( $language->slug ),
					array_values( $this->settings->all() )
				)
			)
		);
	}

	/**
	 * Абсолютный адрес текущей страницы на указанном языке — без строки запроса.
	 *
	 * Используется для canonical и hreflang: параметры запроса (utm, фильтры)
	 * в индексируемый адрес попадать не должны.
	 *
	 * @param Language $language Целевой язык.
	 */
	public function canonicalUrlFor( Language $language ): string {
		return $this->absolute( $this->resolver->pathWithoutLanguage(), $language );
	}

	/**
	 * Адрес текущей страницы на указанном языке с сохранением строки запроса.
	 *
	 * Нужен переключателю языков: со страницы поиска `?s=...` посетитель должен
	 * попасть на тот же поиск, а не на главную.
	 *
	 * @param Language $language Целевой язык.
	 */
	public function switchUrlFor( Language $language ): string {
		$url = $this->canonicalUrlFor( $language );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение экранируется при выводе esc_url().
		$query = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( (string) $_SERVER['QUERY_STRING'] ) : '';

		return '' !== $query ? $url . '?' . $query : $url;
	}

	/**
	 * Главная страница указанного языка.
	 *
	 * @param Language $language Целевой язык.
	 */
	public function homeFor( Language $language ): string {
		return $this->absolute( '/', $language );
	}

	/**
	 * Собирает абсолютный адрес из пути без языка.
	 *
	 * @param string   $relativePath Путь с ведущим слешем, без базового пути установки.
	 * @param Language $language     Целевой язык.
	 */
	public function absolute( string $relativePath, Language $language ): string {
		$origin = untrailingslashit( (string) get_option( 'home' ) );
		$prefix = $language->isDefault ? '' : '/' . $language->slug;

		return $origin . $prefix . '/' . ltrim( $relativePath, '/' );
	}

	/**
	 * Убирает языковой префикс из пути, если он там есть.
	 *
	 * @param string $relativePath Путь без базового пути установки.
	 */
	public function stripPrefix( string $relativePath ): string {
		list( $segment, $rest ) = LanguageResolver::splitFirstSegment( $relativePath );

		if ( '' === $segment ) {
			return $relativePath;
		}

		return null !== $this->settings->anyBySlug( strtolower( $segment ) ) ? $rest : $relativePath;
	}

	/**
	 * Вставляет языковой слаг в путь абсолютного или относительного адреса.
	 *
	 * @param string $url  Адрес.
	 * @param string $slug Слаг языка.
	 */
	private function addPrefixToUrl( string $url, string $slug ): string {
		return self::withLanguagePrefix( $url, LanguageResolver::basePath(), $slug, $this->knownSlugs() );
	}

	/**
	 * Добавляет языковой префикс к адресу. Чистая функция.
	 *
	 * @param string       $url        Абсолютный, относительный или схемо-независимый адрес.
	 * @param string       $basePath   Базовый путь установки WordPress.
	 * @param string       $slug       Слаг целевого языка.
	 * @param list<string> $knownSlugs Слаги всех языков сайта — см. addPrefixToPath().
	 */
	public static function withLanguagePrefix( string $url, string $basePath, string $slug, array $knownSlugs = array() ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$path = (string) ( $parts['path'] ?? '/' );

		if ( self::isOutsideInstallation( $parts, $path, $basePath, $knownSlugs ) ) {
			return $url;
		}

		$newPath = self::addPrefixToPath( $path, $basePath, $slug, $knownSlugs );

		if ( $newPath === $path ) {
			return $url;
		}

		$parts['path'] = $newPath;

		return self::buildUrl( $parts, str_starts_with( $url, '//' ) );
	}

	/**
	 * Указывает ли полный адрес за пределы установки WordPress. Чистая функция.
	 *
	 * WordPress может стоять в подкаталоге (`/blog`), и тогда на том же
	 * домене живёт что-то ещё — лендинг, статика, другая CMS. Языковых
	 * версий у них нет, и добавлять префикс им нельзя: адрес
	 * `https://site.ru/about/` превратился бы в `https://site.ru/blog/bg/about/`,
	 * то есть ссылка на чужую страницу молча уехала бы внутрь блога и
	 * отдала 404.
	 *
	 * Проверяется только адрес С ЯВНЫМ ХОСТОМ. У относительного пути
	 * (`/kontakty/`) автор не сказал, куда он ведёт, и мы по-прежнему
	 * считаем его ссылкой внутрь установки: именно так выглядят пункты
	 * меню, сохранённые до появления плагина, и ломать их поведение
	 * нельзя. А написав адрес целиком, автор указал место точно — и его
	 * нужно уважать.
	 *
	 * Исключение — голый языковой сегмент без хвоста, но записанный ПОЛНЫМ
	 * адресом (`https://site.ru/ru/#services`, а не относительным `/ru/`).
	 * Живой баг: та же ситуация, что и в addPrefixToPath() (см. её
	 * докблок) — автор имел в виду корень домена другого языка, просто
	 * указал схему и хост явно. Без этого исключения проверка отсекала
	 * такую ссылку как «чужой домен» раньше, чем addPrefixToPath() успевал
	 * распознать в ней домен-root случай, — то есть его ветка для полных
	 * адресов была попросту недостижима, и `href="https://site.ru/ru/..."`
	 * так и оставался на исходном языке навсегда.
	 *
	 * @param array<string, mixed> $parts      Результат wp_parse_url().
	 * @param string               $path       Путь из адреса.
	 * @param string               $basePath   Базовый путь установки, `` для корня домена.
	 * @param list<string>         $knownSlugs Слаги всех языков сайта, в нижнем регистре.
	 */
	private static function isOutsideInstallation( array $parts, string $path, string $basePath, array $knownSlugs ): bool {
		if ( '' === $basePath || ! isset( $parts['host'] ) ) {
			// WordPress в корне домена — «снаружи» просто нет.
			return false;
		}

		if ( $path === $basePath || str_starts_with( $path, $basePath . '/' ) ) {
			return false;
		}

		return ! self::isBareLanguageSegment( $path, $knownSlugs );
	}

	/**
	 * Путь — голый языковой сегмент без хвоста (`/ru/`), а не что-то ещё
	 * за пределами установки. Чистая функция.
	 *
	 * @param string       $path       Путь из адреса.
	 * @param list<string> $knownSlugs Слаги всех языков сайта, в нижнем регистре.
	 */
	private static function isBareLanguageSegment( string $path, array $knownSlugs ): bool {
		list( $segment, $rest ) = LanguageResolver::splitFirstSegment( $path );

		return '/' === $rest && in_array( strtolower( $segment ), $knownSlugs, true );
	}

	/**
	 * Убирает языковой префикс из адреса, если он там есть. Чистая функция.
	 *
	 * Симметрична withLanguagePrefix(), но в обратную сторону: нужна для
	 * постоянных идентификаторов (`@id` в JSON-LD), которые обязаны быть
	 * одинаковыми на всех языках, даже если префикс уже попал в значение
	 * до того, как плагин успел это увидеть (см. JsonLdRules::isStableId()).
	 *
	 * @param string        $url      Абсолютный, относительный или схемо-независимый адрес.
	 * @param string        $basePath Базовый путь установки WordPress.
	 * @param list<string>  $slugs    Слаги всех известных языков (включая черновики).
	 */
	public static function withoutLanguagePrefix( string $url, string $basePath, array $slugs ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$path    = (string) ( $parts['path'] ?? '/' );
		$newPath = self::removePrefixFromPath( $path, $basePath, $slugs );

		if ( $newPath === $path ) {
			return $url;
		}

		$parts['path'] = $newPath;

		return self::buildUrl( $parts, str_starts_with( $url, '//' ) );
	}

	/**
	 * Убирает первый сегмент пути, если это слаг известного языка. Чистая функция.
	 *
	 * @param string       $path     Полный путь из адреса, например `/blog/en/sample-page/`.
	 * @param string       $basePath Базовый путь установки, например `/blog`.
	 * @param list<string> $slugs    Слаги всех известных языков.
	 */
	public static function removePrefixFromPath( string $path, string $basePath, array $slugs ): string {
		$relative = LanguageResolver::relativePath( $path, $basePath );

		list( $segment, $rest ) = LanguageResolver::splitFirstSegment( $relative );

		if ( '' === $segment || ! in_array( strtolower( $segment ), $slugs, true ) ) {
			return $path;
		}

		return $basePath . $rest;
	}

	/**
	 * Собирает адрес обратно из разобранных частей.
	 *
	 * Подменять путь поиском по строке нельзя: в `https://site.ru/` первый
	 * найденный `/` принадлежит схеме, а не пути.
	 *
	 * @param array<string, mixed> $parts           Результат wp_parse_url().
	 * @param bool                 $schemeRelative  Исходный адрес начинался с `//`.
	 */
	private static function buildUrl( array $parts, bool $schemeRelative ): string {
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : ( $schemeRelative ? '//' : '' );

		$auth = '';

		if ( isset( $parts['user'] ) ) {
			$auth = $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@';
		}

		return $scheme
			. $auth
			. ( $parts['host'] ?? '' )
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			. ( $parts['path'] ?? '' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Добавляет слаг языка сразу после базового пути установки. Чистая функция.
	 *
	 * Возвращает путь без изменений, если префикс уже стоит или сегмент
	 * зарезервирован ядром WordPress. Если первый сегмент — слаг ДРУГОГО
	 * известного языка (например `/ru/...` в ссылке, сохранённой раньше, чем
	 * появился этот плагин), он ЗАМЕНЯЕТСЯ на целевой, а не остаётся перед
	 * новым префиксом — без этой проверки получалось бы `/en/ru/...`.
	 *
	 * Найдено на живом сайте: WordPress стоит в `/blog`, а на корне домена
	 * живёт отдельный, не-WordPress раздел сайта со своими собственными
	 * `/ru/`, `/en/`, `/bg/`. Автор написал в тексте статьи `href="/ru/"`,
	 * имея в виду именно его — ведущий слеш в браузере всегда означает
	 * корень домена, независимо от того, в каком подкаталоге стоит
	 * WordPress. Замена по слагу считала «ru» своим языковым сегментом и
	 * переписывала путь в `/blog/en/` — адрес внутри блога вместо корня
	 * домена, то есть совсем не туда.
	 *
	 * Отличить этот случай от двух легаси-сценариев, ради которых замену
	 * по слагу вообще сделали, нужны ОБА признака сразу:
	 *
	 * 1. Путь не начинался с `$basePath` — `LanguageResolver::relativePath()`
	 *    ничего не отрезала, `$relative` совпал с исходным `$path`. Иначе
	 *    это `/blog/ru/…` — базовый путь уже стоит явно, автор писал
	 *    ссылку именно внутрь установки, просто с устаревшим языком.
	 * 2. После языкового сегмента ничего нет (`$rest === '/'`). Иначе это
	 *    `/ru/discuss-the-task/» — ссылка на РЕАЛЬНУЮ страницу внутри
	 *    установки, просто без базового пути в написании. Внутри самой
	 *    установки голого языка без хвоста не бывает: у языка по
	 *    умолчанию своя главная без префикса вовсе, а у дополнительного —
	 *    это всегда слаг конкретной записи или страницы.
	 *
	 * Только когда путь одновременно НЕ начинается с базового пути И
	 * заканчивается на самом языковом сегменте — это, вероятнее всего,
	 * ссылка на корень домена, а не внутрь установки, и `$basePath`
	 * добавлять не нужно.
	 *
	 * @param string       $path       Полный путь из адреса, например `/blog/sample-page/`.
	 * @param string       $basePath   Базовый путь установки, например `/blog`.
	 * @param string       $slug       Слаг целевого языка, например `en`.
	 * @param list<string> $knownSlugs Слаги всех языков сайта, в нижнем регистре.
	 */
	public static function addPrefixToPath( string $path, string $basePath, string $slug, array $knownSlugs = array() ): string {
		$relative = LanguageResolver::relativePath( $path, $basePath );

		list( $segment, $rest ) = LanguageResolver::splitFirstSegment( $relative );
		$normalizedSegment      = strtolower( $segment );

		if ( $normalizedSegment === $slug ) {
			return $path;
		}

		if ( in_array( $normalizedSegment, self::RESERVED_SEGMENTS, true ) ) {
			return $path;
		}

		if ( in_array( $normalizedSegment, $knownSlugs, true ) ) {
			if ( '' !== $basePath && $path === $relative && '/' === $rest ) {
				return '/' . $slug . $rest;
			}

			return $basePath . '/' . $slug . $rest;
		}

		return $basePath . '/' . $slug . $relative;
	}
}
