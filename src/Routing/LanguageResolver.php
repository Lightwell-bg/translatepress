<?php
/**
 * Определение языка текущего запроса по URL.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Routing;

use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;

/**
 * Разбирает путь запроса и решает, на каком языке отдавать страницу.
 *
 * Язык определяется ТОЛЬКО по URL (ТЗ 7.1): ни cookie, ни Accept-Language не
 * используются, иначе один адрес отдавал бы разный контент и ломал индексацию.
 *
 * Класс намеренно не трогает $_SERVER: он только читает. Подмена REQUEST_URI
 * ломает плагины кэширования и логи, поэтому маршрутизация сделана на
 * rewrite-правилах (см. Rewrites).
 */
final class LanguageResolver {

	/**
	 * Язык текущего запроса.
	 */
	private ?Language $current = null;

	/**
	 * Языковой сегмент в том виде, в каком он пришёл в URL (может быть `EN`).
	 */
	private ?string $matchedSegment = null;

	/**
	 * Путь запроса без языкового префикса и без базового пути установки.
	 */
	private string $pathWithoutLanguage = '/';

	/**
	 * В URL стоит префикс языка по умолчанию — нужен 301 на адрес без префикса.
	 */
	private bool $redundantPrefix = false;

	/**
	 * URL указывает на черновой язык — страницы быть не должно (404).
	 */
	private bool $unavailable = false;

	/**
	 * @param Settings $settings Настройки плагина.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Язык текущего запроса. Для неизвестного префикса — язык по умолчанию.
	 */
	public function current(): Language {
		$this->resolve();

		return $this->current ?? $this->settings->defaultLanguage();
	}

	/**
	 * Код языка текущего запроса.
	 */
	public function currentLocale(): string {
		return $this->current()->locale;
	}

	/**
	 * Запрошен ли язык по умолчанию (страница отдаётся без перевода).
	 */
	public function isDefault(): bool {
		return $this->current()->isDefault;
	}

	/**
	 * Путь запроса без языкового префикса, всегда с ведущим слешем.
	 */
	public function pathWithoutLanguage(): string {
		$this->resolve();

		return $this->pathWithoutLanguage;
	}

	/**
	 * Языковой сегмент так, как он записан в URL, либо null.
	 */
	public function matchedSegment(): ?string {
		$this->resolve();

		return $this->matchedSegment;
	}

	/**
	 * Стоит ли в URL лишний префикс языка по умолчанию.
	 */
	public function hasRedundantPrefix(): bool {
		$this->resolve();

		return $this->redundantPrefix;
	}

	/**
	 * Запрошен ли черновой язык.
	 */
	public function isUnavailable(): bool {
		$this->resolve();

		return $this->unavailable;
	}

	/**
	 * Записан ли языковой сегмент не в том регистре (`/EN/` вместо `/en/`).
	 */
	public function hasCaseMismatch(): bool {
		$this->resolve();

		return null !== $this->matchedSegment && strtolower( $this->matchedSegment ) !== $this->matchedSegment;
	}

	/**
	 * Базовый путь установки WordPress: `` для корня, `/blog` для подкаталога.
	 *
	 * Используется get_option('home'), а НЕ home_url(): home_url мы сами
	 * фильтруем в UrlConverter, и вызов отсюда дал бы бесконечную рекурсию.
	 */
	public static function basePath(): string {
		$home = (string) get_option( 'home' );
		$path = (string) ( wp_parse_url( $home, PHP_URL_PATH ) ?? '' );

		return untrailingslashit( $path );
	}

	/**
	 * Путь текущего запроса относительно базового пути установки.
	 */
	public static function currentPath(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- сравнивается только первый сегмент, по allowlist.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';

		return self::relativePath( $uri, self::basePath() );
	}

	/**
	 * Вычитает базовый путь установки из адреса. Чистая функция.
	 *
	 * @param string $requestUri Значение вида `/blog/en/page/?x=1`.
	 * @param string $basePath   Базовый путь без хвостового слеша, например `/blog`.
	 */
	public static function relativePath( string $requestUri, string $basePath ): string {
		$path = (string) ( wp_parse_url( $requestUri, PHP_URL_PATH ) ?? '' );

		if ( '' !== $basePath && ( $path === $basePath || str_starts_with( $path, $basePath . '/' ) ) ) {
			$path = substr( $path, strlen( $basePath ) );
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Делит путь на первый сегмент и остаток. Чистая функция.
	 *
	 * @param string $path Путь с ведущим слешем.
	 * @return array{0: string, 1: string} Первый сегмент и остаток пути с ведущим слешем.
	 */
	public static function splitFirstSegment( string $path ): array {
		$trimmed = ltrim( $path, '/' );

		if ( '' === $trimmed ) {
			return array( '', '/' );
		}

		$position = strpos( $trimmed, '/' );

		if ( false === $position ) {
			return array( $trimmed, '/' );
		}

		return array( substr( $trimmed, 0, $position ), '/' . substr( $trimmed, $position + 1 ) );
	}

	/**
	 * Один раз за запрос разбирает URL.
	 */
	private function resolve(): void {
		if ( null !== $this->current ) {
			return;
		}

		$path                      = self::currentPath();
		$this->pathWithoutLanguage = $path;
		$this->current             = $this->settings->defaultLanguage();

		list( $segment, $rest ) = self::splitFirstSegment( $path );

		if ( '' === $segment ) {
			return;
		}

		$language = $this->settings->anyBySlug( strtolower( $segment ) );

		if ( null === $language ) {
			// Обычный сегмент пути, не язык.
			return;
		}

		$this->matchedSegment      = $segment;
		$this->pathWithoutLanguage = $rest;

		if ( $language->isDefault ) {
			// `/ru/page/` при русском по умолчанию — дубль, нужен 301.
			$this->redundantPrefix = true;

			return;
		}

		if ( ! $language->isPublished() ) {
			// Черновой язык не должен иметь доступного URL.
			$this->unavailable = true;

			return;
		}

		$this->current = $language;
	}
}
