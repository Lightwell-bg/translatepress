<?php
/**
 * Точка сборки плагина: контейнер, сервисы, хуки жизненного цикла.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas;

// Guard placed before the long import list so direct-access scanners see it near the top.
defined( 'ABSPATH' ) || exit;

use LingoCanvas\Admin\CloudActions;
use LingoCanvas\Admin\CloudSection;
use LingoCanvas\Admin\CloudStatus;
use LingoCanvas\Admin\EditorLink;
use LingoCanvas\Admin\EditorPage;
use LingoCanvas\Admin\EditorPostLinks;
use LingoCanvas\Admin\PostListLanguages;
use LingoCanvas\Admin\HelpTabs;
use LingoCanvas\Admin\InterfaceStringsScreen;
use LingoCanvas\Admin\SettingsPage;
use LingoCanvas\Admin\StringTranslationPage;
use LingoCanvas\Frontend\CommentsFeedTitle;
use LingoCanvas\Frontend\CurrentPageLanguages;
use LingoCanvas\Frontend\InternalLinks;
use LingoCanvas\Frontend\LanguageSwitcher;
use LingoCanvas\Frontend\NavMenu;
use LingoCanvas\Frontend\PostVisibilityGate;
use LingoCanvas\Frontend\SeoMeta;
use LingoCanvas\Frontend\SeoTags;
use LingoCanvas\Frontend\Sitemap;
use LingoCanvas\Frontend\VisibilityAdminBar;
use LingoCanvas\I18n\GettextRegistry;
use LingoCanvas\I18n\LanguagePacks;
use LingoCanvas\I18n\LocaleSwitcher;
use LingoCanvas\Rendering\EditorContext;
use LingoCanvas\Rendering\EditorMarkers;
use LingoCanvas\Rendering\Extractor;
use LingoCanvas\Rendering\OutputBuffer;
use LingoCanvas\Rendering\PostContentExtractor;
use LingoCanvas\Rendering\Translator;
use LingoCanvas\Rest\BlocksController;
use LingoCanvas\Rest\CloudController;
use LingoCanvas\Rest\EditorPagesController;
use LingoCanvas\Rest\OpenAiModelsController;
use LingoCanvas\Rest\PostTranslationController;
use LingoCanvas\Rest\PostVisibilityController;
use LingoCanvas\Rest\TranslationsController;
use LingoCanvas\Routing\CanonicalRedirect;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\Rewrites;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\GettextRepository;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\PostTranslationSnapshot;
use LingoCanvas\Storage\Schema;
use LingoCanvas\Storage\SourceRepository;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Storage\TranslationRepository;
use LingoCanvas\Storage\UsageTracker;
use LingoCanvas\Support\Hookable;
use LingoCanvas\Translation\ProviderFactory;
use LingoCanvas\Translation\ProviderInterface;

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
	 *
	 * Plugin Check предупреждает (load_plugin_textdomainFound): для плагинов из
	 * каталога WordPress.org переводы с translate.wordpress.org подгружаются
	 * ядром сами. Вызов оставлен намеренно: русский перевод поставляется внутри
	 * плагина (languages/lingocanvas-ru_RU.*), а не с translate.wordpress.org,
	 * и без этого вызова он не загрузится. Убрать, когда перевод появится там.
	 */
	public static function loadTextdomain(): void {
		load_plugin_textdomain( 'lingocanvas', false, dirname( LINGOCANVAS_BASENAME ) . '/languages' );
	}

	/**
	 * Активация плагина.
	 */
	public static function activate(): void {
		// Настройки по умолчанию — только если опции ещё нет: существующие не затираются.
		if ( false === get_option( Settings::optionName(), false ) ) {
			add_option( Settings::optionName(), Settings::defaults() );
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

		$c->set(
			PostLanguageVisibility::class,
			static fn( Container $c ): PostLanguageVisibility => new PostLanguageVisibility(
				$c->get( TranslationCache::class ),
				$c->get( OccurrenceRepository::class )
			)
		);

		$c->set(
			CurrentPageLanguages::class,
			static fn( Container $c ): CurrentPageLanguages => new CurrentPageLanguages(
				$c->get( Settings::class ),
				$c->get( PostLanguageVisibility::class )
			)
		);
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
				$c->get( UrlConverter::class ),
				$c->get( CurrentPageLanguages::class )
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
				$c->get( UrlConverter::class ),
				$c->get( CurrentPageLanguages::class )
			)
		);

		$c->set(
			NavMenu::class,
			static fn( Container $c ): NavMenu => new NavMenu(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( UrlConverter::class ),
				$c->get( CurrentPageLanguages::class )
			)
		);

		$c->set(
			Sitemap::class,
			static fn( Container $c ): Sitemap => new Sitemap(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class ),
				$c->get( OccurrenceRepository::class ),
				$c->get( TranslationCache::class ),
				$c->get( PostLanguageVisibility::class )
			)
		);

		$c->set(
			PostVisibilityGate::class,
			static fn( Container $c ): PostVisibilityGate => new PostVisibilityGate(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( PostLanguageVisibility::class ),
				$c->get( EditorContext::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set(
			VisibilityAdminBar::class,
			static fn( Container $c ): VisibilityAdminBar => new VisibilityAdminBar(
				$c->get( Settings::class ),
				$c->get( LanguageResolver::class ),
				$c->get( PostLanguageVisibility::class )
			)
		);

		$c->set(
			PostVisibilityController::class,
			static fn( Container $c ): PostVisibilityController => new PostVisibilityController(
				$c->get( Settings::class ),
				$c->get( PostLanguageVisibility::class )
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
				$c->get( LanguagePacks::class ),
				$c->get( ProviderFactory::class ),
				$c->get( PostLanguageVisibility::class ),
				$c->get( CloudSection::class )
			)
		);

		$c->set(
			CloudStatus::class,
			static fn( Container $c ): CloudStatus => new CloudStatus( $c->get( ProviderFactory::class ) )
		);

		$c->set(
			CloudActions::class,
			static fn( Container $c ): CloudActions => new CloudActions(
				$c->get( ProviderFactory::class ),
				$c->get( Settings::class ),
				$c->get( CloudStatus::class )
			)
		);

		$c->set(
			CloudSection::class,
			static fn( Container $c ): CloudSection => new CloudSection(
				$c->get( Settings::class ),
				$c->get( ProviderFactory::class ),
				$c->get( CloudActions::class )
			)
		);

		$c->set(
			CloudController::class,
			static fn( Container $c ): CloudController => new CloudController(
				$c->get( CloudStatus::class ),
				$c->get( CloudActions::class )
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
			EditorLink::class,
			static fn( Container $c ): EditorLink => new EditorLink(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class )
			)
		);

		$c->set(
			EditorPostLinks::class,
			static fn( Container $c ): EditorPostLinks => new EditorPostLinks(
				$c->get( Settings::class ),
				$c->get( EditorLink::class ),
				$c->get( PostLanguageVisibility::class )
			)
		);

		$c->set(
			PostListLanguages::class,
			static fn( Container $c ): PostListLanguages => new PostListLanguages(
				$c->get( Settings::class ),
				$c->get( EditorLink::class ),
				$c->get( PostLanguageVisibility::class )
			)
		);

		$c->set(
			EditorPagesController::class,
			static fn( Container $c ): EditorPagesController => new EditorPagesController(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class ),
				$c->get( EditorLink::class )
			)
		);

		$c->set(
			OpenAiModelsController::class,
			static fn( Container $c ): OpenAiModelsController => new OpenAiModelsController(
				$c->get( ProviderFactory::class )
			)
		);

		$c->set(
			EditorPage::class,
			static fn( Container $c ): EditorPage => new EditorPage(
				$c->get( Settings::class ),
				$c->get( UrlConverter::class ),
				$c->get( ProviderFactory::class ),
				$c->get( PostLanguageVisibility::class ),
				$c->get( OccurrenceRepository::class )
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
				$c->get( UsageTracker::class ),
				$c->get( ProviderFactory::class )
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
			EditorPagesController::class,
			OpenAiModelsController::class,
			CloudController::class,
			PostVisibilityController::class,
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
			$services[] = CloudSection::class;
			$services[] = HelpTabs::class;
			$services[] = EditorPostLinks::class;
			$services[] = PostListLanguages::class;
			$services[] = StringTranslationPage::class;
		} else {
			$services[] = CanonicalRedirect::class;
			$services[] = SeoTags::class;
			$services[] = EditorContext::class;
			$services[] = PostVisibilityGate::class;
			$services[] = VisibilityAdminBar::class;
			$services[] = Sitemap::class;
			$services[] = OutputBuffer::class;
		}

		return $services;
	}
}
