<?php
/**
 * Адреса и версии файлов стилей и скриптов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Считает версию ассета по времени изменения файла.
 *
 * Одной константы версии плагина мало: сайт обновляют копированием файлов,
 * не меняя номер версии, — и тогда адрес `admin.js?ver=0.1.0` остаётся тем же,
 * браузер отдаёт скрипт из кэша, а разметку PHP рендерит уже новую. Внешне это
 * выглядит как «кнопка не работает»: обработчика в старом файле просто нет.
 *
 * Время изменения файла решает это раз и навсегда, без ручного бампа версии.
 */
final class Assets {

	/**
	 * Полный URL файла в папке плагина.
	 *
	 * @param string $relativePath Путь относительно корня плагина, например `assets/admin.js`.
	 */
	public static function url( string $relativePath ): string {
		return WP_MLP_URL . ltrim( $relativePath, '/' );
	}

	/**
	 * Версия для параметра `ver`.
	 *
	 * @param string $relativePath Путь относительно корня плагина.
	 */
	public static function version( string $relativePath ): string {
		$path = WP_MLP_DIR . ltrim( $relativePath, '/' );

		if ( ! is_readable( $path ) ) {
			return WP_MLP_VERSION;
		}

		$modified = filemtime( $path );

		return false !== $modified ? WP_MLP_VERSION . '.' . $modified : WP_MLP_VERSION;
	}
}
