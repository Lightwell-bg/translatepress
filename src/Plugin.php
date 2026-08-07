<?php
/**
 * Точка сборки плагина: контейнер, сервисы, хуки жизненного цикла.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp;

use WpMlp\Admin\EditorPage;
use WpMlp\Admin\SettingsPage;
use WpMlp\Admin\StringTranslationPage;
use WpMlp\Frontend\LanguageSwitcher;
use WpMlp\Frontend\SeoTags;
use WpMlp\Rendering\EditorContext;
use WpMlp\Rendering\EditorMarkers;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\OutputBuffer;
use WpMlp\Rendering\Translator;
use WpMlp\Rest\BlocksController;
use WpMlp\Rest\TranslationsController;
use WpMlp\Routing\CanonicalRedirect;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\Rewrites;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\Schema;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Storage\TranslationRepository;
use WpMlp\Storage\UsageTracker;
use WpMlp\Support\Env;
use WpMlp\Support\Hookable;
use WpMlp\Translation\ManualProvider;
use WpMlp\Translation\OpenAiProvider;
use WpMlp\Translation\ProviderInterface;

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

		// Схема могла отстать, если плагин обновили копированием файлов.
		if ( is_admin() ) {
			add_action( 'admin_init', array( Schema::class, 'maybeUpgrade' ) );
		}

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

		Schema::install();

		/*
		 * К моменту активации `plugins_loaded` уже прошёл, значит boot() не
		 * выполнялся и фильтр rewrite-правил не навешан. Без этой строки
		 * flush собрал бы правила без языковых префиксов, и /en/ отдавал бы 404
		 * до первого сохранения настроек.
		 */
		self::container()->get( Rewrites::class )->register();

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

		$c->set( SourceRepository::class, static fn(): SourceRepository => new SourceRepository() );
		$c->set( TranslationRepository::class, static fn(): TranslationRepository => new TranslationRepository() );
		$c->set( OccurrenceRepository::class, static fn(): OccurrenceRepository => new OccurrenceRepository() );
		$c->set( TranslationCache::class, static fn(): TranslationCache => new TranslationCache() );
		$c->set( Extractor::class, static fn(): Extractor => new Extractor() );

		$c->set( UsageTracker::class, static fn(): UsageTracker => new UsageTracker() );

		/*
		 * Провайдер перевода. Ключ есть в .env — используем OpenAI, иначе
		 * заглушка: без ключа кнопка «Перевести с ИИ» просто не подключится.
		 * Перевод по-прежнему запускается только вручную, кнопкой.
		 */
		$c->set(
			ProviderInterface::class,
			static function (): ProviderInterface {
				$apiKey = Env::get( 'OPENAI_API_KEY' );

				$provider = '' !== $apiKey
					? new OpenAiProvider(
						$apiKey,
						Env::get( 'OPENAI_MODEL' ),
						rtrim( Env::get( 'OPENAI_BASE_URL', 'https://api.openai.com/v1' ), '/' )
					)
					: new ManualProvider();

				return apply_filters( 'mlp_translation_provider', $provider );
			}
		);

		$c->set(
			SeoTags::class,
			static fn( Container $c ): SeoTags => new SeoTags(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set( EditorMarkers::class, static fn(): EditorMarkers => new EditorMarkers() );

		$c->set(
			EditorContext::class,
			static fn( Container $c ): EditorContext => new EditorContext( $c->get( LanguageResolver::class ) )
		);

		$c->set(
			Translator::class,
			static fn( Container $c ): Translator => new Translator(
				$c->get( Extractor::class ),
				$c->get( SourceRepository::class ),
				$c->get( OccurrenceRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( Settings::class ),
				$c->get( EditorContext::class ),
				$c->get( EditorMarkers::class ),
				array( $c->get( SeoTags::class ) )
			)
		);

		$c->set(
			OutputBuffer::class,
			static fn( Container $c ): OutputBuffer => new OutputBuffer(
				$c->get( LanguageResolver::class ),
				$c->get( Translator::class )
			)
		);

		$c->set(
			LanguageResolver::class,
			static fn( Container $c ): LanguageResolver => new LanguageResolver( $c->get( Settings::class ) )
		);

		$c->set(
			UrlConverter::class,
			static fn( Container $c ): UrlConverter => new UrlConverter(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class )
			)
		);

		$c->set(
			Rewrites::class,
			static fn( Container $c ): Rewrites => new Rewrites( $c->get( Settings::class ) )
		);

		$c->set(
			CanonicalRedirect::class,
			static fn( Container $c ): CanonicalRedirect => new CanonicalRedirect(
				$c->get( LanguageResolver::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set(
			LanguageSwitcher::class,
			static fn( Container $c ): LanguageSwitcher => new LanguageSwitcher(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set(
			SettingsPage::class,
			static fn( Container $c ): SettingsPage => new SettingsPage( $c->get( Settings::class ) )
		);

		$c->set(
			StringTranslationPage::class,
			static fn( Container $c ): StringTranslationPage => new StringTranslationPage(
				$c->get( Settings::class ),
				$c->get( SourceRepository::class ),
				$c->get( TranslationRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( ProviderInterface::class ),
				$c->get( OccurrenceRepository::class )
			)
		);

		$c->set(
			EditorPage::class,
			static fn( Container $c ): EditorPage => new EditorPage(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class ),
				$c->get( ProviderInterface::class )
			)
		);

		$c->set(
			BlocksController::class,
			static fn( Container $c ): BlocksController => new BlocksController(
				$c->get( SourceRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( Settings::class )
			)
		);

		$c->set(
			TranslationsController::class,
			static fn( Container $c ): TranslationsController => new TranslationsController(
				$c->get( SourceRepository::class ),
				$c->get( TranslationRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( Settings::class ),
				$c->get( ProviderInterface::class ),
				$c->get( UsageTracker::class )
			)
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
		$services = array(
			// Rewrites нужен и в админке: правила пересобираются при flush.
			Rewrites::class,
			UrlConverter::class,
			// rest_api_init не сработает, если маршрут не зарегистрирован заранее.
			TranslationsController::class,
			BlocksController::class,
			// Ссылка «Перевести страницу» живёт в админ-баре на фронтенде.
			EditorPage::class,
			// Шорткод и виджет нужны и в админке: превью виджетов, редактор.
			LanguageSwitcher::class,
		);

		if ( is_admin() ) {
			// Админские сервисы не нужны фронтенду: не создаём их лишний раз.
			$services[] = SettingsPage::class;
			$services[] = StringTranslationPage::class;
		} else {
			$services[] = CanonicalRedirect::class;
			$services[] = SeoTags::class;
			$services[] = EditorContext::class;
			$services[] = OutputBuffer::class;
		}

		return $services;
	}
}
