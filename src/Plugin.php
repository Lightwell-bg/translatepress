<?php
/**
 * Точка сборки плагина: контейнер, сервисы, хуки жизненного цикла.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp;

use WpMlp\Admin\SettingsPage;
use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * Композиционный корень.
 *
 * Держит единственный экземпляр контейнера и разворачивает сервисы на
 * plugins_loaded. Ничего не делает сам — только связывает модули.
 */
final class Plugin {

	/**
	 * Контейнер текущего запроса.
	 */
	private static ?Container $container = null;

	/**
	 * Собирает контейнер и регистрирует хуки всех сервисов.
	 */
	public static function boot(): void {
		if ( null !== self::$container ) {
			return;
		}

		self::$container = self::buildContainer();

		add_action( 'init', array( self::class, 'loadTextdomain' ), 0 );

		foreach ( self::hookableServices() as $id ) {
			$service = self::$container->get( $id );

			if ( $service instanceof Hookable ) {
				$service->register();
			}
		}
	}

	/**
	 * Доступ к контейнеру для точек, куда его нельзя пробросить аргументом
	 * (шорткоды, виджеты, uninstall).
	 */
	public static function container(): Container {
		if ( null === self::$container ) {
			self::$container = self::buildContainer();
		}

		return self::$container;
	}

	/**
	 * Подключает переводы интерфейса самого плагина.
	 */
	public static function loadTextdomain(): void {
		load_plugin_textdomain( 'wp-mlp', false, dirname( WP_MLP_BASENAME ) . '/languages' );
	}

	/**
	 * Активация плагина.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		// Схема БД появится здесь на следующем шаге.
		flush_rewrite_rules();
	}

	/**
	 * Деактивация плагина: снимаем языковые rewrite-правила, данные не трогаем.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Описывает все сервисы плагина.
	 */
	private static function buildContainer(): Container {
		$container = new Container();

		self::defineServices( $container );

		return $container;
	}

	/**
	 * Регистрация фабрик.
	 *
	 * @param Container $c Контейнер.
	 */
	private static function defineServices( Container $c ): void {
		$c->set( Settings::class, static fn(): Settings => new Settings() );

		$c->set(
			SettingsPage::class,
			static fn( Container $c ): SettingsPage => new SettingsPage( $c->get( Settings::class ) )
		);
	}

	/**
	 * Сервисы, у которых нужно вызвать register().
	 *
	 * Порядок важен: маршрутизация должна встать раньше рендеринга.
	 *
	 * @return list<string>
	 */
	private static function hookableServices(): array {
		$services = array();

		// Админские сервисы не нужны фронтенду: не создаём их на каждый показ страницы.
		if ( is_admin() ) {
			$services[] = SettingsPage::class;
		}

		return $services;
	}
}
