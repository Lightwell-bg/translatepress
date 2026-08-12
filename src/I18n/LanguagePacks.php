<?php
/**
 * Языковые пакеты WordPress: наличие и установка.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\I18n;

use WpMlp\Support\Locale;

/**
 * Тонкая обёртка над механизмом переводов WordPress.
 *
 * Весь смысл подмены локали (см. {@see LocaleSwitcher}) держится на том,
 * что для целевой локали НА САЙТЕ ЛЕЖИТ языковой пакет — файлы `.mo` в
 * `wp-content/languages/`. Без пакета ядро, тема и плагины вернут
 * английские оригиналы, подмена локали внешне «не сработает», и понять
 * почему по одному лишь фронтенду невозможно: страница просто останется
 * на английском. Поэтому наличие пакета показывается в админке явно, а
 * не подразумевается.
 */
final class LanguagePacks {

	/**
	 * Установлен ли языковой пакет для локали.
	 *
	 * @param string $wpLocale Локаль WordPress, например `bg_BG`.
	 */
	public function isInstalled( string $wpLocale ): bool {
		if ( ! self::needsPack( $wpLocale ) ) {
			return true;
		}

		return in_array( $wpLocale, (array) get_available_languages(), true );
	}

	/**
	 * Скачивает и ставит языковой пакет. Возвращает false, если не вышло.
	 *
	 * @param string $wpLocale Локаль WordPress.
	 */
	public function install( string $wpLocale ): bool {
		if ( ! Locale::isValidWpLocale( $wpLocale ) ) {
			return false;
		}

		if ( ! self::needsPack( $wpLocale ) ) {
			// Для en_US качать нечего — считаем, что всё уже на месте.
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		if ( ! wp_can_install_language_pack() ) {
			return false;
		}

		// Возвращает саму локаль при успехе и false при любой неудаче.
		return false !== wp_download_language_pack( $wpLocale );
	}

	/**
	 * Может ли сайт вообще ставить языковые пакеты.
	 *
	 * На хостингах без прав на запись в `wp-content/languages` или с
	 * заблокированным исходящим соединением к api.wordpress.org установка
	 * невозможна — кнопку в таком случае показывать бессмысленно, нужно
	 * объяснить, что пакет придётся положить файлом вручную.
	 */
	public function canInstall(): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		return (bool) wp_can_install_language_pack();
	}

	/**
	 * Нужен ли локали пакет вообще. Чистая функция.
	 *
	 * `en_US` — встроенная локаль ядра: файлов перевода для неё нет и не
	 * будет, `get_available_languages()` её не возвращает никогда.
	 *
	 * @param string $wpLocale Локаль WordPress.
	 */
	public static function needsPack( string $wpLocale ): bool {
		return 'en_US' !== $wpLocale;
	}
}
