<?php
/**
 * Plugin Name:       LingoCanvas
 * Plugin URI:        https://github.com/Lightwell-bg/translatepress
 * Description:       Multilingual websites without duplicate posts: one WordPress post, a URL per language, string translations stored in dedicated tables, optional AI translation via OpenAI.
 * Version:           0.3.14
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            CenterAI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lingocanvas
 * Domain Path:       /languages
 *
 * @package WpMlp
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Переход с прежнего плагина «WP Multilang Press» (папка wp-mlp/, главный файл
 * wp-mlp.php). Для WordPress это другой плагин, поэтому оба могут оказаться
 * активными одновременно. Код у них общий (namespace WpMlp, таблицы mlp_*,
 * опции mlp_*, REST mlp/v1), и второй экземпляр продублировал бы хуки, буфер
 * вывода и REST-маршруты.
 *
 * Правило: пока прежний плагин активен, LingoCanvas не загружает ничего —
 * ни констант, ни автозагрузчика, ни классов (иначе классы WpMlp\ подгружались
 * бы вперемешку из двух папок). Работает прежний плагин; LingoCanvas показывает
 * уведомление. Активация LingoCanvas сама выключает прежний плагин, а данные
 * общие, поэтому переносить их не нужно — переводы и настройки подхватываются
 * как есть.
 *
 * Всё ниже до проверки — без классов плагина и без глобальных переменных.
 */

/**
 * Активные копии прежнего плагина (базовые имена вида wp-mlp/wp-mlp.php).
 *
 * Папка могла называться иначе (например, после распаковки архива с GitHub),
 * поэтому сравнивается имя главного файла, а не путь целиком.
 *
 * @return list<string>
 */
function lingocanvas_active_legacy_plugins(): array {
	$active = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$legacy = array();

	foreach ( $active as $basename ) {
		if ( is_string( $basename ) && 'wp-mlp.php' === basename( $basename ) ) {
			$legacy[] = $basename;
		}
	}

	return array_values( array_unique( $legacy ) );
}

/**
 * Активен ли прежний плагин на всю сеть (мультисайт).
 */
if ( ! function_exists( 'lingocanvas_legacy_network_active' ) ) {
	function lingocanvas_legacy_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $basename ) {
			if ( is_string( $basename ) && 'wp-mlp.php' === basename( $basename ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Запоминает имя папки прежнего плагина (опция lingocanvas_legacy_dir): оттуда
 * LingoCanvas читает старый .env. Имя проверяется — оно попадёт в путь к файлу.
 * Всё это до проверки-заглушки, поэтому без классов плагина.
 *
 * @param list<string> $basenames Базовые имена активных копий прежнего плагина.
 * @param bool         $overwrite Перезаписывать ли уже сохранённое значение.
 */
if ( ! function_exists( 'lingocanvas_remember_legacy_dir' ) ) {
	function lingocanvas_remember_legacy_dir( array $basenames, bool $overwrite ): void {
		if ( ! $overwrite && false !== get_option( 'lingocanvas_legacy_dir', false ) ) {
			return;
		}

		foreach ( $basenames as $basename ) {
			$dir = dirname( str_replace( '\\', '/', $basename ) );

			if ( 1 === preg_match( '/^[A-Za-z0-9._-]+$/D', $dir ) && '.' !== $dir && '..' !== $dir ) {
				update_option( 'lingocanvas_legacy_dir', $dir, true );

				return;
			}
		}
	}
}

/*
 * Удаление прежнего плагина через «Плагины → Удалить» запускает его
 * uninstall.php. Если в общих настройках включено «удалять данные при
 * удалении», он снёс бы таблицы, которыми теперь пользуется LingoCanvas.
 * На время удаления прежнего плагина эта галочка для него не видна.
 * Хук регистрируется до проверки ниже: удалить можно только неактивный плагин,
 * а значит, в этот момент LingoCanvas работает.
 */
add_action(
	'pre_uninstall_plugin',
	static function ( $plugin ): void {
		if ( ! is_string( $plugin ) || 'wp-mlp.php' !== basename( $plugin ) ) {
			return;
		}

		add_filter(
			'option_mlp_settings',
			static function ( $value ) {
				if ( is_array( $value ) ) {
					$value['delete_data_on_uninstall'] = false;
				}

				return $value;
			}
		);
	}
);

/*
 * Активация LingoCanvas, когда прежний плагин ещё активен: в этом запросе
 * классы WpMlp\ уже загружены из старой папки, поэтому здесь только выключаем
 * прежний плагин (без его хука деактивации — данные и так не трогаются) и
 * откладываем собственную активацию (схема, правила ссылок) до следующего
 * запроса, где работает только новый код.
 */
register_activation_hook(
	__FILE__,
	static function ( $network_wide = false ): void {
		$network_wide = (bool) $network_wide;
		$legacy       = lingocanvas_active_legacy_plugins();

		if ( array() === $legacy && ! defined( 'WP_MLP_FILE' ) ) {
			\WpMlp\Plugin::activate();
			delete_option( 'lingocanvas_pending_activation' );

			return;
		}

		lingocanvas_remember_legacy_dir( $legacy, true );

		foreach ( $legacy as $basename ) {
			$network = is_multisite() && is_plugin_active_for_network( $basename );

			/*
			 * Прежний плагин включён на всю сеть, а LingoCanvas — на одном сайте:
			 * выключение затронуло бы остальные сайты сети. Оставляем как есть,
			 * LingoCanvas остаётся на паузе (уведомление объясняет, что делать).
			 */
			if ( $network && ! $network_wide ) {
				continue;
			}

			deactivate_plugins( $basename, true, $network );
		}

		update_option( 'lingocanvas_pending_activation', 1, true );
	}
);

if ( defined( 'WP_MLP_FILE' ) || array() !== lingocanvas_active_legacy_plugins() ) {
	/*
	 * Прежний плагин могут выключить и вручную, минуя активацию LingoCanvas.
	 * Тогда в первом же запросе без него LingoCanvas доделает активацию сам.
	 */
	if ( false === get_option( 'lingocanvas_pending_activation', false ) ) {
		add_option( 'lingocanvas_pending_activation', 1, '', true );
	}

	lingocanvas_remember_legacy_dir( lingocanvas_active_legacy_plugins(), false );

	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( lingocanvas_legacy_network_active() ) {
				$message = __( 'LingoCanvas is paused because the previous version of this plugin (WP Multilang Press) is network-activated. LingoCanvas must be network-activated by a network administrator (Network Admin → Plugins) to take over; activating it on a single site does not switch the old plugin off.', 'lingocanvas' );
			} else {
				$message = __( 'LingoCanvas is paused because the previous version of this plugin (WP Multilang Press) is still active. Deactivate WP Multilang Press: LingoCanvas will continue with the same translations and settings. Do not delete the old plugin until LingoCanvas is active.', 'lingocanvas' );
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);

	return;
}

const LINGOCANVAS_VERSION = '0.3.14';
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
			$prefix = 'WpMlp\\';

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
 * плагина через ZIP, кроме двух последних:
 * 1. wp-content/wp-mlp.env.php — PHP-файл с защитной первой строкой
 *    (Env::GUARD): внутри веб-корня, поэтому без неё он не читается (иначе его
 *    можно скачать по прямой ссылке);
 * 2. wp-mlp.env на уровень выше корня WordPress — из веба недоступен;
 * 3. прежний .env в папке плагина — удаляется при замене плагина;
 * 4. .env в папке прежнего плагина wp-mlp/ — чтобы AI-перевод не пропал сразу
 *    после перехода; исчезнет вместе со старой папкой.
 * Имена файлов wp-mlp.env* сохранены: на сайтах они уже лежат.
 *
 * Сам закрывающий тег PHP в `//`-комментарии писать нельзя: он завершает
 * PHP-код прямо посреди комментария, и остаток файла уходит в вывод.
 */
$lingocanvas_content_env = \WpMlp\Translation\ProviderFactory::contentEnvPath();
$lingocanvas_parent_env  = \WpMlp\Translation\ProviderFactory::parentEnvPath();

if ( null !== $lingocanvas_content_env ) {
	\WpMlp\Support\Env::loadGuarded( $lingocanvas_content_env );
}

if ( null !== $lingocanvas_parent_env ) {
	\WpMlp\Support\Env::load( $lingocanvas_parent_env );
}

\WpMlp\Support\Env::load( LINGOCANVAS_DIR . '.env' );
$lingocanvas_legacy_env = \WpMlp\Translation\ProviderFactory::legacyPluginEnvPath();

if ( null !== $lingocanvas_legacy_env ) {
	\WpMlp\Support\Env::load( $lingocanvas_legacy_env );
}

unset( $lingocanvas_content_env, $lingocanvas_parent_env, $lingocanvas_legacy_env );

register_deactivation_hook( __FILE__, array( \WpMlp\Plugin::class, 'deactivate' ) );

/**
 * Загружаемся на plugins_loaded (ТЗ 11): к этому моменту доступны опции и $wpdb,
 * но ещё не выполнены init, parse_request и рендеринг.
 */
add_action( 'plugins_loaded', array( \WpMlp\Plugin::class, 'boot' ), 5 );
