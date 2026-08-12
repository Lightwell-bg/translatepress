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
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\Rewrites;
use WpMlp\Routing\UrlConverter;

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
