<?php
/**
 * Plugin Name:       LingoCanvas
 * Plugin URI:        https://github.com/Lightwell-bg/translatepress
 * Description:       Multilingual websites without duplicate posts: one WordPress post, a URL per language, string translations stored in dedicated tables, optional AI translation via OpenAI.
 * Version:           0.5.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            CenterAI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lingocanvas
 * Domain Path:       /languages
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LINGOCANVAS_VERSION = '0.5.4';
const LINGOCANVAS_MIN_PHP = '8.1';

define( 'LINGOCANVAS_FILE', __FILE__ );
define( 'LINGOCANVAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINGOCANVAS_URL', plugin_dir_url( __FILE__ ) );
define( 'LINGOCANVAS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Плагин не имеет зависимостей в рантайме, поэтому vendor/ на хостинг заливать
 * не обязательно: если автозагрузчика Composer нет, используем собственный
 * PSR-4 загрузчик. Composer нужен только для dev-инструментов (PHPUnit, PHPCS).
 */
if ( is_readable( LINGOCANVAS_DIR . 'vendor/autoload.php' ) ) {
	require_once LINGOCANVAS_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'LingoCanvas\\';

			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$path     = LINGOCANVAS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/**
 * Не даём активировать плагин на слишком старом PHP: без этого пользователь
 * получил бы parse error вместо понятного сообщения.
 */
if ( version_compare( PHP_VERSION, LINGOCANVAS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'LingoCanvas requires PHP %1$s or newer. The server runs PHP %2$s, so the plugin is disabled.', 'lingocanvas' ),
						LINGOCANVAS_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

/*
 * Файлы окружения (побеждает файл, загруженный раньше). Все переживают замену
 * плагина через ZIP, кроме последнего:
 * 1. wp-content/lingocanvas.env.php — PHP-файл с защитной первой строкой
 *    (Env::GUARD): внутри веб-корня, поэтому без неё он не читается (иначе его
 *    можно скачать по прямой ссылке);
 * 2. lingocanvas.env на уровень выше корня WordPress — из веба недоступен;
 * 3. .env в папке плагина — удаляется при замене плагина.
 *
 * Сам закрывающий тег PHP в `//`-комментарии писать нельзя: он завершает
 * PHP-код прямо посреди комментария, и остаток файла уходит в вывод.
 */
$lingocanvas_content_env = \LingoCanvas\Translation\ProviderFactory::contentEnvPath();
$lingocanvas_parent_env  = \LingoCanvas\Translation\ProviderFactory::parentEnvPath();

if ( null !== $lingocanvas_content_env ) {
	\LingoCanvas\Support\Env::loadGuarded( $lingocanvas_content_env );
}

if ( null !== $lingocanvas_parent_env ) {
	\LingoCanvas\Support\Env::load( $lingocanvas_parent_env );
}

\LingoCanvas\Support\Env::load( LINGOCANVAS_DIR . '.env' );

unset( $lingocanvas_content_env, $lingocanvas_parent_env );

register_activation_hook( __FILE__, array( \LingoCanvas\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \LingoCanvas\Plugin::class, 'deactivate' ) );

/**
 * Загружаемся на plugins_loaded (ТЗ 11): к этому моменту доступны опции и $wpdb,
 * но ещё не выполнены init, parse_request и рендеринг.
 */
add_action( 'plugins_loaded', array( \LingoCanvas\Plugin::class, 'boot' ), 5 );
