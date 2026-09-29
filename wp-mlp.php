<?php
/**
 * Plugin Name:       WP Multilang Press
 * Plugin URI:        https://example.com/wp-mlp
 * Description:       Мультиязычность по модели TranslatePress: одна запись WordPress, N языковых URL, переводы строк в собственных таблицах. Без дублирования постов.
 * Version:           0.3.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            —
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mlp
 * Domain Path:       /languages
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WP_MLP_VERSION = '0.3.4';
const WP_MLP_MIN_PHP = '8.1';

define( 'WP_MLP_FILE', __FILE__ );
define( 'WP_MLP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MLP_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_MLP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Плагин не имеет зависимостей в рантайме, поэтому vendor/ на хостинг заливать
 * не обязательно: если автозагрузчика Composer нет, используем собственный
 * PSR-4 загрузчик. Composer нужен только для dev-инструментов (PHPUnit, PHPCS).
 */
if ( is_readable( WP_MLP_DIR . 'vendor/autoload.php' ) ) {
	require_once WP_MLP_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'WpMlp\\';

			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$path     = WP_MLP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

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
if ( version_compare( PHP_VERSION, WP_MLP_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'WP Multilang Press требует PHP %1$s или новее. На сервере установлен PHP %2$s — плагин отключён.', 'wp-mlp' ),
						WP_MLP_MIN_PHP,
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
 * 1. wp-content/wp-mlp.env.php — PHP-файл с защитной первой строкой
 *    (Env::GUARD): внутри веб-корня, поэтому без неё он не читается (иначе его
 *    можно скачать по прямой ссылке);
 * 2. wp-mlp.env на уровень выше корня WordPress — из веба недоступен;
 * 3. прежний .env в папке плагина — удаляется при замене плагина.
 *
 * Сам закрывающий тег PHP в `//`-комментарии писать нельзя: он завершает
 * PHP-код прямо посреди комментария, и остаток файла уходит в вывод.
 */
$mlp_content_env = \WpMlp\Translation\ProviderFactory::contentEnvPath();
$mlp_parent_env  = \WpMlp\Translation\ProviderFactory::parentEnvPath();

if ( null !== $mlp_content_env ) {
	\WpMlp\Support\Env::loadGuarded( $mlp_content_env );
}

if ( null !== $mlp_parent_env ) {
	\WpMlp\Support\Env::load( $mlp_parent_env );
}

\WpMlp\Support\Env::load( WP_MLP_DIR . '.env' );

unset( $mlp_content_env, $mlp_parent_env );

register_activation_hook( __FILE__, array( \WpMlp\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WpMlp\Plugin::class, 'deactivate' ) );

/**
 * Загружаемся на plugins_loaded (ТЗ 11): к этому моменту доступны опции и $wpdb,
 * но ещё не выполнены init, parse_request и рендеринг.
 */
add_action( 'plugins_loaded', array( \WpMlp\Plugin::class, 'boot' ), 5 );
