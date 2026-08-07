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
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$path     = (string) ( $parts['path'] ?? '/' );
		$basePath = LanguageResolver::basePath();
		$newPath  = self::addPrefixToPath( $path, $basePath, $slug );

		if ( $newPath === $path ) {
			return $url;
		}

		// Заменяем только путь, оставляя схему, хост, порт, query и фрагмент.
		$position = strpos( $url, $path );

		if ( false === $position ) {
			return $url;
		}

		return substr_replace( $url, $newPath, $position, strlen( $path ) );
	}

	/**
	 * Добавляет слаг языка сразу после базового пути установки. Чистая функция.
	 *
	 * Возвращает путь без изменений, если префикс уже стоит или сегмент
	 * зарезервирован ядром WordPress.
	 *
	 * @param string $path     Полный путь из адреса, например `/blog/sample-page/`.
	 * @param string $basePath Базовый путь установки, например `/blog`.
	 * @param string $slug     Слаг языка, например `en`.
	 */
	public static function addPrefixToPath( string $path, string $basePath, string $slug ): string {
		$relative = LanguageResolver::relativePath( $path, $basePath );

		list( $segment ) = LanguageResolver::splitFirstSegment( $relative );

		if ( strtolower( $segment ) === $slug ) {
			return $path;
		}

		if ( in_array( strtolower( $segment ), self::RESERVED_SEGMENTS, true ) ) {
			return $path;
		}

		return $basePath . '/' . $slug . $relative;
	}
}
