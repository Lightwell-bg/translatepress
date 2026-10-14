<?php
/**
 * На каких языках текущая страница скрыта вручную.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\PostLanguageVisibility;

defined( 'ABSPATH' ) || exit;

/**
 * Отвечает переключателю языков и hreflang на вопрос «есть ли у этой страницы
 * версия на языке X».
 *
 * Запись, которую владелец не включил для языка (см. PostLanguageVisibility),
 * на этом языке отдаёт редирект на русскую версию. Поэтому ссылка на неё в
 * переключателе — тупик, а hreflang на неё — ложное обещание поисковику.
 * Переключатель ведёт такого посетителя на главную нужного языка, hreflang
 * этот язык пропускает.
 *
 * Работает только в ручном режиме и только на странице одной записи (или
 * странице записей блога); на остальных страницах (рубрики, поиск, архивы)
 * скрывать нечего.
 */
final class CurrentPageLanguages {

	/**
	 * Как узнать запись текущей страницы; подменяется в тестах.
	 *
	 * @var callable|null
	 */
	private $postIdProvider;

	/**
	 * @param Settings               $settings   Настройки плагина.
	 * @param PostLanguageVisibility $visibility Флаги видимости записей.
	 * @param callable|null          $provider   Колбэк `fn(): int` — запись текущей страницы; по умолчанию по условным тегам WordPress.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly PostLanguageVisibility $visibility,
		?callable $provider = null
	) {
		$this->postIdProvider = $provider;
	}

	/**
	 * Скрыта ли текущая страница на указанном языке.
	 *
	 * @param Language $language Целевой язык.
	 */
	public function isHiddenOn( Language $language ): bool {
		if ( $language->isDefault || ! $this->settings->isManualPostVisibility() ) {
			return false;
		}

		$postId = $this->postId();

		if ( $postId <= 0 || ! $this->visibility->isEligiblePost( $postId ) ) {
			return false;
		}

		return ! $this->visibility->isVisible( $postId, $language->locale );
	}

	/**
	 * Запись текущей страницы или 0.
	 */
	private function postId(): int {
		if ( null !== $this->postIdProvider ) {
			return (int) ( $this->postIdProvider )();
		}

		return self::queriedPostId();
	}

	/**
	 * Запись, которую показывает главный запрос, либо 0.
	 *
	 * Одиночная запись/страница (в том числе статическая главная) и страница,
	 * назначенная «страницей записей» блога: у обеих есть собственная запись,
	 * которую владелец мог не включить для языка. Вложения не считаются.
	 * До хука `wp` главного запроса ещё нет, и условные теги вызывать нельзя.
	 */
	public static function queriedPostId(): int {
		if ( ! did_action( 'wp' ) || is_404() || is_search() ) {
			return 0;
		}

		if ( is_singular() && ! is_attachment() ) {
			return (int) get_queried_object_id();
		}

		if ( is_home() && ! is_front_page() ) {
			return (int) get_queried_object_id();
		}

		return 0;
	}
}
