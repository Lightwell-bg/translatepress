<?php
/**
 * Тесты чтения REQUEST_URI / QUERY_STRING с санитизацией на месте.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Routing\CanonicalRedirect;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;

#[CoversClass( CanonicalRedirect::class )]
#[CoversClass( LanguageResolver::class )]
#[CoversClass( UrlConverter::class )]
final class RequestSanitizationTest extends TestCase {

	protected function setUp(): void {
		lingocanvas_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::optionName() => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
					),
				),
			)
		);

		unset( $_SERVER['QUERY_STRING'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'] );
		lingocanvas_test_options( array() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function uriProvider(): array {
		return array(
			'пробел'                => array( '/page/a b/' ),
			'угловая скобка'        => array( '/page/a<b/' ),
			'кавычка'               => array( '/page/a"b/' ),
			'кириллица в %-виде'    => array( '/page/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82/' ),
			'сырая кириллица UTF-8' => array( '/page/привет/' ),
		);
	}

	/**
	 * Адрес без языкового префикса и с вторичным языком не даёт ни редиректа,
	 * ни 404 — независимо от «грязных» символов.
	 */
	#[DataProvider( 'uriProvider' )]
	public function testNoSpuriousRedirectForSanitizedUri( string $path ): void {
		foreach ( array( $path, '/en' . $path ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;

			$resolver = new LanguageResolver( new Settings() );

			$this->assertFalse( $resolver->hasRedundantPrefix(), $uri );
			$this->assertFalse( $resolver->hasCaseMismatch(), $uri );
			$this->assertFalse( $resolver->isUnavailable(), $uri );
		}
	}

	/**
	 * Путь, очищенный при чтении, одинаков при повторном разборе: нет «прыгающего» значения.
	 */
	#[DataProvider( 'uriProvider' )]
	public function testResolvedPathIsStable( string $path ): void {
		$_SERVER['REQUEST_URI'] = '/en' . $path;

		$first  = ( new LanguageResolver( new Settings() ) )->pathWithoutLanguage();
		$second = ( new LanguageResolver( new Settings() ) )->pathWithoutLanguage();

		$this->assertSame( $first, $second );
		$this->assertStringStartsWith( '/page/', $first );
		$this->assertStringNotContainsString( '<', $first );
		$this->assertStringNotContainsString( '"', $first );
		$this->assertStringNotContainsString( ' ', $first );
	}

	/**
	 * Лишний префикс языка по умолчанию даёт один 301, а целевой адрес уже
	 * не требует редиректа (цикла нет).
	 */
	#[DataProvider( 'uriProvider' )]
	public function testRedundantPrefixRedirectsOnceAndTargetIsStable( string $path ): void {
		$_SERVER['REQUEST_URI'] = '/ru' . $path;

		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$target   = $this->targetUrl( new CanonicalRedirect( $resolver, new UrlConverter( $settings, $resolver ) ) );

		$this->assertTrue( $resolver->hasRedundantPrefix() );
		$this->assertStringStartsWith( 'https://example.test/page/', $target );

		// Запрос по целевому адресу: редиректа больше нет.
		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( $target, PHP_URL_PATH );

		$again = new LanguageResolver( new Settings() );

		$this->assertFalse( $again->hasRedundantPrefix() );
		$this->assertFalse( $again->hasCaseMismatch() );
	}

	public function testNoRedirectWhenPathsAlreadyEqual(): void {
		$_SERVER['REQUEST_URI'] = '/page/a b/';

		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );

		$this->assertSame( '', $this->targetUrl( new CanonicalRedirect( $resolver, new UrlConverter( $settings, $resolver ) ) ) );
	}

	public function testQueryStringKeepsPercentEncodingAndDropsMarkup(): void {
		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$urls     = new UrlConverter( $settings, $resolver );
		$english  = $settings->anyBySlug( 'en' );

		$this->assertNotNull( $english );

		$_SERVER['REQUEST_URI']  = '/en/search/';
		$_SERVER['QUERY_STRING'] = 's=a%26b&x=%D0%BF';

		$this->assertSame( 'https://example.test/en/search/?s=a%26b&x=%D0%BF', $urls->switchUrlFor( $english ) );

		$_SERVER['QUERY_STRING'] = 's=a"b<c';

		$this->assertSame( 'https://example.test/en/search/?s=abc', $urls->switchUrlFor( $english ) );

		$_SERVER['QUERY_STRING'] = '';

		$this->assertSame( 'https://example.test/en/search/', $urls->switchUrlFor( $english ) );
	}

	public function testQueryStringIsPreservedByCanonicalRedirect(): void {
		$_SERVER['REQUEST_URI']  = '/ru/page/';
		$_SERVER['QUERY_STRING'] = 'q=a%26b';

		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$target   = $this->targetUrl( new CanonicalRedirect( $resolver, new UrlConverter( $settings, $resolver ) ) );

		$this->assertSame( 'https://example.test/page/?q=a%26b', $target );
	}

	/**
	 * Вызывает приватный targetUrl().
	 */
	private function targetUrl( CanonicalRedirect $redirect ): string {
		$method = new \ReflectionMethod( $redirect, 'targetUrl' );

		return (string) $method->invoke( $redirect );
	}
}
