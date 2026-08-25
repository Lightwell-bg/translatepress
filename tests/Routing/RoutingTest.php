<?php
/**
 * Тесты чистой логики маршрутизации.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\InternalLinks;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\Rewrites;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;

#[CoversClass( LanguageResolver::class )]
#[CoversClass( UrlConverter::class )]
#[CoversClass( Rewrites::class )]
final class RoutingTest extends TestCase {

	public function testRelativePathStripsInstallSubdirectory(): void {
		$this->assertSame( '/en/about/', LanguageResolver::relativePath( '/blog/en/about/', '/blog' ) );
		$this->assertSame( '/', LanguageResolver::relativePath( '/blog/', '/blog' ) );
		$this->assertSame( '/', LanguageResolver::relativePath( '/blog', '/blog' ) );
	}

	public function testRelativePathKeepsPathWhenInstalledInRoot(): void {
		$this->assertSame( '/en/about/', LanguageResolver::relativePath( '/en/about/', '' ) );
		$this->assertSame( '/', LanguageResolver::relativePath( '/', '' ) );
	}

	public function testRelativePathDropsQueryString(): void {
		$this->assertSame( '/en/search/', LanguageResolver::relativePath( '/en/search/?s=cat', '' ) );
	}

	/**
	 * Подкаталог `/blogger/` не должен считаться установкой в `/blog`.
	 */
	public function testRelativePathDoesNotStripPartialMatch(): void {
		$this->assertSame( '/blogger/post/', LanguageResolver::relativePath( '/blogger/post/', '/blog' ) );
	}

	public function testSplitFirstSegment(): void {
		$this->assertSame( array( 'en', '/about/' ), LanguageResolver::splitFirstSegment( '/en/about/' ) );
		$this->assertSame( array( 'en', '/' ), LanguageResolver::splitFirstSegment( '/en/' ) );
		$this->assertSame( array( 'en', '/' ), LanguageResolver::splitFirstSegment( '/en' ) );
		$this->assertSame( array( '', '/' ), LanguageResolver::splitFirstSegment( '/' ) );
	}

	public function testAddPrefixToPath(): void {
		$this->assertSame( '/en/about/', UrlConverter::addPrefixToPath( '/about/', '', 'en' ) );
		$this->assertSame( '/en/', UrlConverter::addPrefixToPath( '/', '', 'en' ) );
		$this->assertSame( '/blog/en/about/', UrlConverter::addPrefixToPath( '/blog/about/', '/blog', 'en' ) );
	}

	public function testAddPrefixToPathIsIdempotent(): void {
		$once  = UrlConverter::addPrefixToPath( '/about/', '', 'en' );
		$twice = UrlConverter::addPrefixToPath( $once, '', 'en' );

		$this->assertSame( $once, $twice );
	}

	/**
	 * Служебные адреса ядра префикс получать не должны, иначе ломается REST,
	 * загрузка файлов и вход в админку.
	 */
	public function testAddPrefixToPathSkipsReservedSegments(): void {
		$this->assertSame( '/wp-json/wp/v2/posts', UrlConverter::addPrefixToPath( '/wp-json/wp/v2/posts', '', 'en' ) );
		$this->assertSame( '/wp-admin/', UrlConverter::addPrefixToPath( '/wp-admin/', '', 'en' ) );
		$this->assertSame( '/wp-content/uploads/a.png', UrlConverter::addPrefixToPath( '/wp-content/uploads/a.png', '', 'en' ) );
	}

	/**
	 * Живой баг: WordPress стоит в `/blog`, а на корне домена — отдельный,
	 * не-WordPress раздел сайта со своими собственными `/ru/`, `/en/`,
	 * `/bg/`. Автор написал в тексте статьи `href="/ru/"`, имея в виду
	 * именно его — ведущий слеш в браузере всегда означает корень домена.
	 * Замена по слагу считала «ru» своим языковым сегментом и переписывала
	 * путь в `/blog/en/» — адрес внутри блога вместо корня домена.
	 */
	public function testBareLanguageSegmentOutsideInstallStaysAtDomainRoot(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame( '/en/', UrlConverter::addPrefixToPath( '/ru/', '/blog', 'en', $slugs ) );
		$this->assertSame( '/bg/', UrlConverter::addPrefixToPath( '/ru/', '/blog', 'bg', $slugs ) );
		// Без хвостового слеша — то же самое: splitFirstSegment() его не требует.
		$this->assertSame( '/en/', UrlConverter::addPrefixToPath( '/ru', '/blog', 'en', $slugs ) );
	}

	/**
	 * Тот же голый языковой сегмент, но WordPress стоит в корне домена
	 * (`$basePath` пуст) — там «снаружи установки» просто не существует,
	 * и поведение остаётся прежним: путь получает язык напрямую.
	 */
	public function testBareLanguageSegmentAtDomainRootInstallIsUnaffected(): void {
		$this->assertSame(
			'/en/',
			UrlConverter::addPrefixToPath( '/ru/', '', 'en', array( 'ru', 'bg', 'en' ) )
		);
	}

	/**
	 * Два легаси-сценария, ради которых замену по слагу вообще сделали, —
	 * оба должны остаться нетронутыми моей правкой:
	 *
	 * 1. Ссылка на реальную страницу ВНУТРИ установки, написанная без
	 *    базового пути (`/ru/discuss-the-task/`) — у неё есть хвост после
	 *    языка, значит это не корень домена, а страница по слагу.
	 * 2. Ссылка, где базовый путь уже стоит явно (`/blog/ru/`), просто с
	 *    устаревшим языком, — раз путь и так начинается с `/blog`, о корне
	 *    домена речи нет вообще.
	 */
	public function testLegacyInstallLinksWithKnownSlugAreStillRewrittenWithBasePath(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame(
			'/blog/en/discuss-the-task/',
			UrlConverter::addPrefixToPath( '/ru/discuss-the-task/', '/blog', 'en', $slugs )
		);
		$this->assertSame(
			'/blog/bg/',
			UrlConverter::addPrefixToPath( '/blog/ru/', '/blog', 'bg', $slugs )
		);
	}

	/**
	 * Куда ведёт ссылка — свойство самой ссылки, а не языка страницы, на
	 * которой её встретили. Проверка «первый сегмент совпал с целевым
	 * слагом» стояла ВЫШЕ разбора происхождения и ломала это: `/en/o-nas/`
	 * на болгарской странице считалась легаси-ссылкой внутрь установки и
	 * ехала в `/blog/bg/o-nas/`, а на английской — досрочно возвращалась
	 * как есть и оставалась на корне домена, то есть на ДРУГОМ сайте.
	 * Один и тот же `href` уводил посетителя в два разных места.
	 */
	public function testLinkDestinationDoesNotDependOnPageLanguage(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame(
			'/blog/bg/o-nas/',
			UrlConverter::addPrefixToPath( '/en/o-nas/', '/blog', 'bg', $slugs )
		);
		$this->assertSame(
			'/blog/en/o-nas/',
			UrlConverter::addPrefixToPath( '/en/o-nas/', '/blog', 'en', $slugs ),
			'Та же ссылка на другом языке уехала на другой сайт.'
		);
	}

	/**
	 * `isOutsideInstallation()` смотрела только на путь, вопреки своему
	 * имени: чужой домен считался «внутри установки», если его путь
	 * случайно начинался с `/blog`. Защита держалась исключительно на том,
	 * что вызывающие сверяют хост сами.
	 */
	public function testForeignHostIsNeverRewritten(): void {
		$slugs = array( 'ru', 'bg', 'en' );
		$home  = 'centerai.eu';

		$this->assertSame(
			'https://other.com/blog/post/',
			UrlConverter::withLanguagePrefix( 'https://other.com/blog/post/', '/blog', 'en', $slugs, $home )
		);
		$this->assertSame(
			'https://other.com/ru/',
			UrlConverter::withLanguagePrefix( 'https://other.com/ru/', '/blog', 'en', $slugs, $home )
		);
		$this->assertSame(
			'//evil.example/ru/',
			UrlConverter::withLanguagePrefix( '//evil.example/ru/', '/blog', 'en', $slugs, $home )
		);

		// Свой хост при этом обрабатывается как обычно.
		$this->assertSame(
			'https://centerai.eu/blog/en/post/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/blog/post/', '/blog', 'en', $slugs, $home )
		);
	}

	/**
	 * Двойной слеш приходит из конкатенации в темах. Две независимые
	 * реализации понятия «голый языковой сегмент» расходились на нём:
	 * одна разбирала сырой путь и говорила «это корень домена», вторая
	 * сравнивала с уже нормализованным и говорила «нет, это ссылка внутрь
	 * установки», — и адрес уезжал в блог вместо корня домена.
	 */
	public function testDoubleSlashIsClassifiedConsistently(): void {
		$this->assertSame(
			'https://centerai.eu/en/',
			UrlConverter::withLanguagePrefix(
				'https://centerai.eu//ru/',
				'/blog',
				'en',
				array( 'ru', 'bg', 'en' ),
				'centerai.eu'
			)
		);
	}

	/**
	 * Подменять путь поиском по строке нельзя: в `https://site.ru/` первый
	 * найденный слеш принадлежит схеме, а не пути.
	 */
	public function testWithLanguagePrefixRebuildsUrlCorrectly(): void {
		$this->assertSame(
			'https://site.ru/en/',
			UrlConverter::withLanguagePrefix( 'https://site.ru/', '', 'en' )
		);
		$this->assertSame(
			'https://site.ru/en/about/',
			UrlConverter::withLanguagePrefix( 'https://site.ru/about/', '', 'en' )
		);
		$this->assertSame(
			'https://site.ru:8080/en/about/?x=1#top',
			UrlConverter::withLanguagePrefix( 'https://site.ru:8080/about/?x=1#top', '', 'en' )
		);
		$this->assertSame(
			'//cdn.site.ru/en/about/',
			UrlConverter::withLanguagePrefix( '//cdn.site.ru/about/', '', 'en' )
		);
		$this->assertSame(
			'https://site.ru/blog/en/about/',
			UrlConverter::withLanguagePrefix( 'https://site.ru/blog/about/', '/blog', 'en' )
		);
	}

	public function testWithLanguagePrefixLeavesServiceUrlsAlone(): void {
		$this->assertSame(
			'https://site.ru/wp-json/wp/v2/posts',
			UrlConverter::withLanguagePrefix( 'https://site.ru/wp-json/wp/v2/posts', '', 'en' )
		);
		$this->assertSame(
			'https://site.ru/en/about/',
			UrlConverter::withLanguagePrefix( 'https://site.ru/en/about/', '', 'en' )
		);
	}

	public function testBuildRulesPrefixesEveryRuleAndKeepsMatchNumbering(): void {
		$rules = array(
			'category/(.+?)/?$'       => 'index.php?category_name=$matches[1]',
			'(.?.+?)/page/?([0-9]+)/?$' => 'index.php?pagename=$matches[1]&paged=$matches[2]',
		);

		$result = Rewrites::buildRules( $rules, array( 'en' ) );

		// Правая часть не меняется: слаг подставлен литералом, групп не добавилось.
		$this->assertSame(
			'index.php?category_name=$matches[1]',
			$result['en/category/(.+?)/?$']
		);
		$this->assertSame(
			'index.php?pagename=$matches[1]&paged=$matches[2]',
			$result['en/(.?.+?)/page/?([0-9]+)/?$']
		);

		// Главная страница языка.
		$this->assertSame( 'index.php', $result['en/?$'] );

		// Исходные правила остались.
		$this->assertSame( 'index.php?category_name=$matches[1]', $result['category/(.+?)/?$'] );
	}

	public function testBuildRulesPutsLanguageRulesFirst(): void {
		$rules  = array( '(.?.+?)/?$' => 'index.php?pagename=$matches[1]' );
		$result = Rewrites::buildRules( $rules, array( 'en' ) );

		$keys = array_keys( $result );

		$this->assertSame( 'en/?$', $keys[0] );
		$this->assertLessThan(
			array_search( '(.?.+?)/?$', $keys, true ),
			array_search( 'en/(.?.+?)/?$', $keys, true ),
			'Языковое правило должно проверяться раньше общего шаблона страниц.'
		);
	}

	public function testBuildRulesWithoutSecondaryLanguagesChangesNothing(): void {
		$rules = array( '(.?.+?)/?$' => 'index.php?pagename=$matches[1]' );

		$this->assertSame( $rules, Rewrites::buildRules( $rules, array() ) );
	}

	/**
	 * Жалоба с живого сайта: WordPress стоит в `/blog`, а ссылка ведёт на
	 * корень домена — на лендинг, который к блогу отношения не имеет.
	 * Префикс превращал её в `https://centerai.eu/blog/bg/`, то есть
	 * посетитель уезжал в блог вместо целевой страницы.
	 */
	public function testAbsoluteLinkOutsideInstallationIsLeftAlone(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame(
			'https://centerai.eu/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/', '/blog', 'bg', $slugs )
		);
		$this->assertSame(
			'https://centerai.eu/about/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/about/', '/blog', 'bg', $slugs )
		);
	}

	/**
	 * Живой баг: тот же голый языковой сегмент на корне домена
	 * (`addPrefixToPath()` уже умеет его распознавать, см.
	 * `testBareLanguageSegmentOutsideInstallStaysAtDomainRoot`), но автор
	 * написал ссылку не относительным путём, а ПОЛНЫМ адресом —
	 * `href="https://centerai.eu/ru/#services"`. `isOutsideInstallation()`
	 * смотрит только на то, начинается ли путь с `$basePath`, и отсекает
	 * такой адрес как «чужой домен» ещё до того, как `addPrefixToPath()`
	 * получает шанс распознать в нём корень домена другого языка — то есть
	 * ветка в `addPrefixToPath()` для этого написания попросту недостижима,
	 * и такие ссылки застревают на исходном языке навсегда.
	 */
	public function testAbsoluteLinkToBareLanguageSegmentIsRewrittenAtDomainRoot(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame(
			'https://centerai.eu/en/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/ru/', '/blog', 'en', $slugs )
		);
		$this->assertSame(
			'https://centerai.eu/en/#services',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/ru/#services', '/blog', 'en', $slugs )
		);

		// А реальная страница на корне домена (не голый сегмент) — как и раньше, не трогается.
		$this->assertSame(
			'https://centerai.eu/ru/o-nas/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/ru/o-nas/', '/blog', 'en', $slugs )
		);
	}

	/**
	 * А внутри установки всё работает как раньше — иначе «починка»
	 * отключила бы перевод ссылок вообще.
	 */
	public function testAbsoluteLinkInsideInstallationStillGetsThePrefix(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame(
			'https://centerai.eu/blog/bg/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/blog/', '/blog', 'bg', $slugs )
		);
		$this->assertSame(
			'https://centerai.eu/blog/bg/some-post/',
			UrlConverter::withLanguagePrefix( 'https://centerai.eu/blog/some-post/', '/blog', 'bg', $slugs )
		);
	}

	/**
	 * У относительного пути автор не сказал, куда он ведёт: так выглядят
	 * пункты меню, сохранённые до появления плагина. Их поведение не
	 * меняется — они по-прежнему считаются ссылками внутрь установки.
	 */
	public function testRelativePathKeepsItsPreviousBehaviour(): void {
		$slugs = array( 'ru', 'bg', 'en' );

		$this->assertSame(
			'/blog/bg/kontakty/',
			UrlConverter::withLanguagePrefix( '/kontakty/', '/blog', 'bg', $slugs )
		);
		$this->assertSame(
			'/blog/bg/',
			UrlConverter::withLanguagePrefix( '/blog/ru/', '/blog', 'bg', $slugs )
		);
	}

	/**
	 * Адрес, заданный вручную для этого языка, локализатор трогать не
	 * должен. Без пометки правка не доживает до посетителя: сразу за
	 * подстановкой идёт этот проход и добавляет префикс текущего языка —
	 * то есть затирает ровно то, что владелец сайта задал намеренно.
	 */
	public function testManuallyTranslatedLinkIsNotPrefixedAgain(): void {
		/*
		 * Адрес взят такой, который локализатор ГАРАНТИРОВАННО переписал бы
		 * без пометки: ссылка с болгарской страницы на русскую версию —
		 * `ru` сменилось бы на `bg`. Первая версия этого теста брала адрес,
		 * уже содержавший нужный префикс, и потому проходила даже с
		 * отключённой защитой, то есть не проверяла ничего.
		 */
		$html = '<!DOCTYPE html><html><body>'
			. '<a ' . InternalLinks::TRANSLATED_ATTRIBUTE . '="1" href="/blog/ru/special/">на русскую версию</a>'
			. '<a href="/blog/other/">обычная</a>'
			. '</body></html>';

		$hrefs = $this->localizedHrefs( $html );

		$this->assertSame( '/blog/ru/special/', $hrefs[0], 'Заданный вручную адрес изменился.' );
		$this->assertSame( '/blog/bg/other/', $hrefs[1], 'Обычная ссылка перестала локализоваться.' );
	}

	/**
	 * `InternalLinks` держала СВОЙ, регистрозависимый предохранитель
	 * «чужой хост» ещё до вызова `UrlConverter::withLanguagePrefix()` —
	 * хотя та уже получает `$homeHost` и сама делает эту проверку
	 * регистронезависимо. Домен в DNS регистронезависим, а ссылка с
	 * заглавными буквами в хосте — обычное дело (автозамена в Word,
	 * скопированное из письма, вставленное вручную). Такая ссылка молча
	 * оставалась без языкового префикса, хотя вела ровно на свою же
	 * установку.
	 */
	public function testHostComparisonIsNotCaseSensitive(): void {
		$html = '<!DOCTYPE html><html><body>'
			. '<a href="https://CENTERAI.EU/blog/other/">другой регистр хоста</a>'
			. '</body></html>';

		$hrefs = $this->localizedHrefs( $html );

		$this->assertSame( 'https://CENTERAI.EU/blog/bg/other/', $hrefs[0] );
	}

	/**
	 * Прогоняет документ через локализатор и возвращает адреса ссылок.
	 *
	 * @param string $html Разметка страницы.
	 * @return list<string>
	 */
	private function localizedHrefs( string $html ): array {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						'bg' => array( 'locale' => 'bg', 'slug' => 'bg', 'status' => 'published' ),
					),
				),
				'home'           => 'https://centerai.eu/blog',
			)
		);

		$_SERVER['REQUEST_URI'] = '/blog/bg/x/';

		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$settings = new Settings();
		$target   = new Language( 'bg', 'bg', 'BG', Language::STATUS_PUBLISHED, false, '', 'bg_BG' );

		( new InternalLinks( new UrlConverter( $settings, new LanguageResolver( $settings ) ) ) )
			->apply( $document, $target );

		$hrefs = array();

		foreach ( $document->document()->getElementsByTagName( 'a' ) as $link ) {
			$hrefs[] = (string) $link->getAttribute( 'href' );
		}

		wp_mlp_test_options( array() );
		unset( $_SERVER['REQUEST_URI'] );

		return $hrefs;
	}

	/**
	 * Когда WordPress стоит в корне домена, «снаружи установки» просто не
	 * существует — проверка не должна отключать префикс на таком сайте.
	 */
	public function testInstallationAtDomainRootLocalisesEverything(): void {
		$this->assertSame(
			'https://site.ru/bg/about/',
			UrlConverter::withLanguagePrefix( 'https://site.ru/about/', '', 'bg', array( 'ru', 'bg' ) )
		);
	}
}
