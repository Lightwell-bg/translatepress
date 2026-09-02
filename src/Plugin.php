<?php
/**
 * Точка сборки плагина: контейнер, сервисы, хуки жизненного цикла.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp;

use WpMlp\Admin\EditorPage;
use WpMlp\Admin\HelpTabs;
use WpMlp\Admin\InterfaceStringsScreen;
use WpMlp\Admin\SettingsPage;
use WpMlp\Admin\StringTranslationPage;
use WpMlp\Frontend\CommentsFeedTitle;
use WpMlp\Frontend\InternalLinks;
use WpMlp\Frontend\LanguageSwitcher;
use WpMlp\Frontend\NavMenu;
use WpMlp\Frontend\SeoMeta;
use WpMlp\Frontend\SeoTags;
use WpMlp\Frontend\Sitemap;
use WpMlp\Frontend\UntranslatedFilter;
use WpMlp\I18n\GettextRegistry;
use WpMlp\I18n\LanguagePacks;
use WpMlp\I18n\LocaleSwitcher;
use WpMlp\Rendering\EditorContext;
use WpMlp\Rendering\EditorMarkers;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\OutputBuffer;
use WpMlp\Rendering\PostContentExtractor;
use WpMlp\Rendering\Translator;
use WpMlp\Rest\BlocksController;
use WpMlp\Rest\PostTranslationController;
use WpMlp\Rest\TranslationsController;
use WpMlp\Routing\CanonicalRedirect;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\Rewrites;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Storage\GettextRepository;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\PostTranslationSnapshot;
use WpMlp\Storage\Schema;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Storage\TranslationRepository;
use WpMlp\Storage\UsageTracker;
use WpMlp\Support\Hookable;
use WpMlp\Translation\ProviderFactory;
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

		$c->set(
			PostContentExtractor::class,
			static fn( Container $c ): PostContentExtractor => new PostContentExtractor( $c->get( Extractor::class ) )
		);

		$c->set( PostTranslationSnapshot::class, static fn(): PostTranslationSnapshot => new PostTranslationSnapshot() );

		$c->set( UsageTracker::class, static fn(): UsageTracker => new UsageTracker() );

		/*
		 * Провайдер перевода. Ключ вводится в «Мультиязычность → Языки» и
		 * хранится в БД — так владельцу сайта не нужен доступ к файлам
		 * хостинга. `.env` остаётся резервным способом (для тех, кто всё же
		 * предпочитает хранить ключ вне базы) и используется, только если
		 * поле в настройках пустое. Без ключа — заглушка: кнопка «Перевести
		 * с ИИ» просто не появится. Перевод по-прежнему запускается только
		 * вручную, кнопкой.
		 */
		$c->set(
			ProviderFactory::class,
			static fn( Container $c ): ProviderFactory => new ProviderFactory( $c->get( Settings::class ) )
		);

		$c->set(
			ProviderInterface::class,
			static fn( Container $c ): ProviderInterface => $c->get( ProviderFactory::class )->create()
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
			SeoMeta::class,
			static fn( Container $c ): SeoMeta => new SeoMeta(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set(
			InternalLinks::class,
			static fn( Container $c ): InternalLinks => new InternalLinks( $c->get( UrlConverter::class ) )
		);

		$c->set(
			CommentsFeedTitle::class,
			static fn( Container $c ): CommentsFeedTitle => new CommentsFeedTitle(
				$c->get( Settings::class ),
				$c->get( SourceRepository::class )
			)
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
				$c->get( GettextRegistry::class ),
				array( $c->get( SeoTags::class ), $c->get( SeoMeta::class ), $c->get( InternalLinks::class ), $c->get( CommentsFeedTitle::class ) )
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
			NavMenu::class,
			static fn( Container $c ): NavMenu => new NavMenu(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set(
			Sitemap::class,
			static fn( Container $c ): Sitemap => new Sitemap(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class ),
				$c->get( OccurrenceRepository::class ),
				$c->get( TranslationCache::class )
			)
		);

		$c->set(
			UntranslatedFilter::class,
			static fn( Container $c ): UntranslatedFilter => new UntranslatedFilter(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( OccurrenceRepository::class ),
				$c->get( TranslationCache::class )
			)
		);

		$c->set( LanguagePacks::class, static fn(): LanguagePacks => new LanguagePacks() );

		$c->set(
			LocaleSwitcher::class,
			static fn( Container $c ): LocaleSwitcher => new LocaleSwitcher( $c->get( LanguageResolver::class ) )
		);

		$c->set( GettextRepository::class, static fn(): GettextRepository => new GettextRepository() );

		$c->set(
			GettextRegistry::class,
			static fn( Container $c ): GettextRegistry => new GettextRegistry(
				$c->get( LocaleSwitcher::class ),
				$c->get( GettextRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( Settings::class ),
				$c->get( LanguagePacks::class )
			)
		);

		$c->set(
			SettingsPage::class,
			static fn( Container $c ): SettingsPage => new SettingsPage(
				$c->get( Settings::class ),
				$c->get( LanguagePacks::class )
			)
		);

		$c->set( HelpTabs::class, static fn(): HelpTabs => new HelpTabs() );

		$c->set(
			InterfaceStringsScreen::class,
			static fn( Container $c ): InterfaceStringsScreen => new InterfaceStringsScreen(
				$c->get( GettextRepository::class ),
				$c->get( LanguagePacks::class )
			)
		);

		$c->set(
			StringTranslationPage::class,
			static fn( Container $c ): StringTranslationPage => new StringTranslationPage(
				$c->get( Settings::class ),
				$c->get( SourceRepository::class ),
				$c->get( TranslationRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( ProviderFactory::class ),
				$c->get( OccurrenceRepository::class ),
				$c->get( InterfaceStringsScreen::class ),
				$c->get( GettextRepository::class )
			)
		);

		$c->set(
			EditorPage::class,
			static fn( Container $c ): EditorPage => new EditorPage(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class ),
				$c->get( ProviderFactory::class )
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

		$c->set(
			PostTranslationController::class,
			static fn( Container $c ): PostTranslationController => new PostTranslationController(
				$c->get( Settings::class ),
				$c->get( PostContentExtractor::class ),
				$c->get( SourceRepository::class ),
				$c->get( OccurrenceRepository::class ),
				$c->get( TranslationRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( ProviderInterface::class ),
				$c->get( UsageTracker::class ),
				$c->get( PostTranslationSnapshot::class )
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
			/*
			 * Подмена локали — самой первой: её фильтры обязаны стоять
			 * раньше, чем ядро, тема и плагины начнут грузить свои файлы
			 * перевода (это происходит уже после `plugins_loaded`). Всё
			 * остальное в списке к этому моменту нечувствительно.
			 */
			LocaleSwitcher::class,
			/*
			 * Сразу за ней — gettext-контур: он тоже вешает фильтры,
			 * которые обязаны стоять до загрузки файлов перевода, и сам
			 * решает по LocaleSwitcher, нужно ли вообще включаться.
			 */
			GettextRegistry::class,
			// Rewrites нужен и в админке: правила пересобираются при flush.
			Rewrites::class,
			UrlConverter::class,
			// rest_api_init не сработает, если маршрут не зарегистрирован заранее.
			TranslationsController::class,
			BlocksController::class,
			PostTranslationController::class,
			// Ссылка «Перевести страницу» живёт в админ-баре на фронтенде.
			EditorPage::class,
			// Шорткод и виджет нужны и в админке: превью виджетов, редактор.
			LanguageSwitcher::class,
			// Метабокс живёт в админке, подстановка адресов — на фронтенде.
			NavMenu::class,
		);

		if ( is_admin() ) {
			// Админские сервисы не нужны фронтенду: не создаём их лишний раз.
			$services[] = SettingsPage::class;
			$services[] = HelpTabs::class;
			$services[] = StringTranslationPage::class;
		} else {
			$services[] = CanonicalRedirect::class;
			$services[] = SeoTags::class;
			$services[] = EditorContext::class;
			$services[] = UntranslatedFilter::class;
			$services[] = Sitemap::class;
			$services[] = OutputBuffer::class;
		}

		return $services;
	}
}
