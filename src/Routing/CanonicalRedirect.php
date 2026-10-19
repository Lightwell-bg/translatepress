<?php
/**
 * Канонические редиректы языковых URL.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Routing;

use LingoCanvas\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Приводит адрес к единственной канонической форме одним 301.
 *
 * Обрабатываются только те случаи, которых не знает ядро WordPress:
 * лишний префикс языка по умолчанию, чужой регистр слага и обращение к
 * черновому языку. Хвостовой слеш и прочую нормализацию по-прежнему делает
 * `redirect_canonical()` — мы лишь приводим путь к принятой на сайте форме,
 * чтобы не выстраивать цепочку из двух редиректов подряд.
 */
final class CanonicalRedirect implements Hookable {

	/**
	 * @param LanguageResolver $resolver Язык текущего запроса.
	 * @param UrlConverter     $urls     Построение языковых адресов.
	 */
	public function __construct(
		private readonly LanguageResolver $resolver,
		private readonly UrlConverter $urls
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// Раньше redirect_canonical() ядра, которое висит на приоритете 10.
		add_action( 'template_redirect', array( $this, 'maybeRedirect' ), 5 );
	}

	/**
	 * Решает, нужен ли редирект или 404.
	 */
	public function maybeRedirect(): void {
		if ( $this->resolver->isUnavailable() ) {
			$this->force404();

			return;
		}

		if ( ! $this->resolver->hasRedundantPrefix() && ! $this->resolver->hasCaseMismatch() ) {
			return;
		}

		$target = $this->targetUrl();

		if ( '' === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Канонический адрес текущего запроса или пустая строка, если он совпадает
	 * с запрошенным (страховка от цикла редиректов).
	 */
	private function targetUrl(): string {
		$path = $this->normalizeTrailingSlash( $this->resolver->pathWithoutLanguage() );
		$url  = $this->urls->absolute( $path, $this->resolver->current() );

		$targetPath = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );

		$requestUri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$currentPath = (string) ( wp_parse_url( $requestUri, PHP_URL_PATH ) ?? '' );

		if ( rawurldecode( $targetPath ) === rawurldecode( $currentPath ) ) {
			return '';
		}

		// Ведущий «/» заставляет esc_url_raw() пропустить проверку протокола; %XX сохраняются.
		$query = isset( $_SERVER['QUERY_STRING'] ) ? substr( esc_url_raw( '/?' . wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ), 2 ) : '';

		return '' !== $query ? $url . '?' . $query : $url;
	}

	/**
	 * Приводит хвостовой слеш к принятой на сайте форме.
	 *
	 * @param string $path Путь без языкового префикса.
	 */
	private function normalizeTrailingSlash( string $path ): string {
		if ( '/' === $path ) {
			return $path;
		}

		// Пути с расширением (`/feed.xml`) слешем не заканчиваются.
		if ( 1 === preg_match( '/\.[a-z0-9]{2,5}$/i', $path ) ) {
			return $path;
		}

		return user_trailingslashit( $path );
	}

	/**
	 * Отдаёт 404 для чернового языка: доступного URL у него быть не должно.
	 */
	private function force404(): void {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}
}
