<?php
/**
 * Тесты локализации ссылок в готовом HTML.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\InternalLinks;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

/**
 * Проверяет ссылки, написанные в шаблоне темы буквальным `<a href>` —
 * то, что `home_url()`-фильтр не видит в принципе, потому что мимо него.
 */
#[CoversClass( InternalLinks::class )]
final class InternalLinksTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'status' => 'published' ),
					),
				),
				'home' => 'https://site.ru',
			)
		);

		$_SERVER['REQUEST_URI'] = '/en/about/';
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		unset( $_SERVER['REQUEST_URI'] );
	}

	/**
	 * Прогоняет фрагмент футера через фильтр и возвращает результат.
	 *
	 * @param string $body Разметка внутри `<body>`.
	 * @param string $locale Целевой язык.
	 */
	private function apply( string $body, string $locale = 'en' ): string {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body>' . $body . '</body></html>' );

		$this->assertNotNull( $document );

		$settings = new Settings();
		$resolver = new LanguageResolver( $settings );
		$filter   = new InternalLinks( new UrlConverter( $settings, $resolver ) );

		$filter->apply( $document, $settings->get( $locale ) );

		return $document->html();
	}

	/**
	 * Ровно тот случай из жалобы: футер темы пишет ссылку буквально,
	 * `home_url()` тут вообще не вызывается.
	 */
	public function testHardcodedRelativeLinkGetsLanguagePrefix(): void {
		$html = $this->apply( '<footer><a href="/kontakty/">Контакты</a></footer>' );

		$this->assertStringContainsString( 'href="/en/kontakty/"', $html );
	}

	public function testAbsoluteLinkOnOwnDomainGetsLanguagePrefix(): void {
		$html = $this->apply( '<a href="https://site.ru/about/">О нас</a>' );

		$this->assertStringContainsString( 'href="https://site.ru/en/about/"', $html );
	}

	public function testAlreadyPrefixedLinkIsNotDoubled(): void {
		$html = $this->apply( '<a href="/en/kontakty/">Контакты</a>' );

		$this->assertStringContainsString( 'href="/en/kontakty/"', $html );
		$this->assertStringNotContainsString( '/en/en/', $html );
	}

	/**
	 * Файл в wp-content/uploads один на все языки — ссылка на PDF-прайс не
	 * должна получить префикс, иначе адрес перестанет существовать.
	 */
	public function testUploadedFileLinkIsNotPrefixed(): void {
		$html = $this->apply( '<a href="/wp-content/uploads/price.pdf">Скачать прайс</a>' );

		$this->assertStringContainsString( 'href="/wp-content/uploads/price.pdf"', $html );
	}

	public function testExternalDomainIsNotTouched(): void {
		$html = $this->apply( '<a href="https://example.com/about/">Внешняя ссылка</a>' );

		$this->assertStringContainsString( 'href="https://example.com/about/"', $html );
	}

	#[DataProvider( 'nonRoutableHrefs' )]
	public function testSkipsHrefsWithoutARoutablePath( string $href ): void {
		$html = $this->apply( '<a href="' . $href . '">Ссылка</a>' );

		$this->assertStringContainsString( 'href="' . $href . '"', $html );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function nonRoutableHrefs(): array {
		return array(
			array( '#section' ),
			array( 'mailto:info@site.ru' ),
			array( 'tel:+79990000000' ),
			array( 'javascript:void(0)' ),
			array( '?filter=new' ),
		);
	}

	/**
	 * Воспроизводит регрессию из жалобы: переключатель языков уже несёт
	 * ссылки на СВОИ языки (ru, bg, en), выбранные не по языку текущей
	 * страницы. Общий проход не должен затирать их префиксом текущего
	 * языка — иначе переключатель перестаёт переключать.
	 */
	public function testLanguageSwitcherLinksAreNotRePrefixed(): void {
		$html = $this->apply(
			'<ul>'
			. '<li><a class="mlp-language-item" href="/kontakty/">RU</a></li>'
			. '<li><a class="mlp-language-item" href="/bg/kontakty/">BG</a></li>'
			. '<li><a class="mlp-language-item" href="/en/kontakty/">EN</a></li>'
			. '</ul>'
		);

		$this->assertStringContainsString( 'href="/kontakty/"', $html );
		$this->assertStringContainsString( 'href="/bg/kontakty/"', $html );
		$this->assertStringContainsString( 'href="/en/kontakty/"', $html );
		$this->assertStringNotContainsString( '/en/en/', $html );
		$this->assertStringNotContainsString( '/en/bg/', $html );
	}

	/**
	 * Класс сравнивается как целый токен: похожий, но чужой класс не должен
	 * случайно выключить защиту.
	 */
	public function testLookalikeClassIsNotTreatedAsSwitcher(): void {
		$html = $this->apply( '<a class="mlp-language-items-wrapper" href="/kontakty/">Контакты</a>' );

		$this->assertStringContainsString( 'href="/en/kontakty/"', $html );
	}

	public function testDefaultLanguageIsNotTouched(): void {
		$html = $this->apply( '<a href="/kontakty/">Контакты</a>', 'ru' );

		$this->assertStringContainsString( 'href="/kontakty/"', $html );
	}

	public function testEligibilityHelperAcceptsRoutablePaths(): void {
		$this->assertTrue( InternalLinks::isEligible( '/about/' ) );
		$this->assertTrue( InternalLinks::isEligible( 'https://site.ru/about/' ) );
		$this->assertTrue( InternalLinks::isEligible( '//site.ru/about/' ) );
	}

	public function testEligibilityHelperRejectsNonPaths(): void {
		$this->assertFalse( InternalLinks::isEligible( '' ) );
		$this->assertFalse( InternalLinks::isEligible( '#top' ) );
		$this->assertFalse( InternalLinks::isEligible( 'mailto:a@b.com' ) );
		$this->assertFalse( InternalLinks::isEligible( '?x=1' ) );
		$this->assertFalse( InternalLinks::isEligible( 'about/' ) );
	}
}
