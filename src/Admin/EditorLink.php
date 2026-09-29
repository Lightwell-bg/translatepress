<?php
/**
 * Ссылки на визуальный редактор для конкретной записи или пути.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

/**
 * Общий помощник: превращает запись WordPress в путь и адрес редактора.
 *
 * Им пользуются экран редактора, поиск страниц (REST), ссылка «Перевести» в
 * списках записей и метабокс — правило «что можно открыть в редакторе»
 * живёт только здесь, чтобы четыре места не начали отвечать по-разному.
 */
final class EditorLink {

	/**
	 * @param Settings     $settings Настройки плагина.
	 * @param UrlConverter $urls     Построение языковых адресов.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly UrlConverter $urls
	) {
	}

	/**
	 * Путь записи для `mlp_path` — без базового пути и языкового префикса.
	 *
	 * Только опубликованные записи публичных типов: у черновика нет
	 * фронтенд-адреса, который мог бы открыть предпросмотр.
	 *
	 * @param \WP_Post|int $post Запись или её id.
	 * @return string|null null, если запись открыть в редакторе нельзя.
	 */
	public function pathForPost( $post ): ?string {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || 'attachment' === $post->post_type ) {
			return null;
		}

		$type = get_post_type_object( (string) $post->post_type );

		if ( null === $type || empty( $type->public ) ) {
			return null;
		}

		$permalink = get_permalink( $post );

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return null;
		}

		return $this->relativePath( (string) ( wp_parse_url( $permalink, PHP_URL_PATH ) ?? '/' ) );
	}

	/**
	 * Адрес экрана редактора для записи.
	 *
	 * @param \WP_Post|int $post   Запись или её id.
	 * @param string|null  $locale Язык перевода; по умолчанию — первый дополнительный.
	 * @return string|null null, если запись открыть нельзя.
	 */
	public function urlForPost( $post, ?string $locale = null ): ?string {
		$path = $this->pathForPost( $post );

		if ( null === $path || ( null === $locale && array() === $this->settings->secondary() ) ) {
			return null;
		}

		return $this->urlForPath( $path, $locale );
	}

	/**
	 * Адрес экрана редактора для пути.
	 *
	 * @param string      $path   Путь без языкового префикса.
	 * @param string|null $locale Язык перевода; по умолчанию — первый дополнительный.
	 */
	public function urlForPath( string $path, ?string $locale = null ): string {
		if ( null === $locale ) {
			$locale = (string) array_key_first( $this->settings->secondary() );
		}

		return add_query_arg(
			array(
				'page'       => EditorPage::MENU_SLUG,
				'mlp_locale' => $locale,
				'mlp_path'   => $path,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Путь адреса (без строки запроса) в виде, который принимает редактор:
	 * без базового пути установки и без языкового префикса.
	 *
	 * @param string $urlPath Путь из адреса сайта.
	 */
	public function relativePath( string $urlPath ): string {
		return $this->urls->stripPrefix( LanguageResolver::relativePath( $urlPath, LanguageResolver::basePath() ) );
	}

	/**
	 * Нормализует ввод пользователя: полный адрес или путь. Чистое правило
	 * `EditorPage::pathFromUrl()` для адресов; путь без хоста считается
	 * относящимся к самой установке.
	 *
	 * @param string $input Адрес (`https://…`) или путь (`/about/`).
	 */
	public function normalizeInput( string $input ): string {
		if ( 1 === preg_match( '#^https?://#i', $input ) ) {
			return EditorPage::pathFromUrl(
				$input,
				LanguageResolver::basePath(),
				$this->urls->knownSlugs(),
				UrlConverter::homeHost()
			);
		}

		$path = (string) ( wp_parse_url( $input, PHP_URL_PATH ) ?? '/' );

		// Сначала базовый путь установки (по границе сегмента: `/blog-post/` не трогаем), потом языковой префикс.
		$relative = LanguageResolver::relativePath( '/' . ltrim( $path, '/' ), LanguageResolver::basePath() );

		return '/' . ltrim( $this->urls->stripPrefix( $relative ), '/' );
	}
}
