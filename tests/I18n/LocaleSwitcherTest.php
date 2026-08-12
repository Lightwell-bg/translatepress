<?php
/**
 * Тесты подмены локали WordPress.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\I18n\LocaleSwitcher;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Settings\Settings;
use WpMlp\Support\FrontendRequest;

#[CoversClass( LocaleSwitcher::class )]
#[CoversClass( FrontendRequest::class )]
final class LocaleSwitcherTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published', 'wp_locale' => 'ru_RU' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'status' => 'published', 'wp_locale' => 'en_US' ),
						'bg' => array( 'locale' => 'bg', 'slug' => 'bg', 'status' => 'published', 'wp_locale' => 'bg_BG' ),
					),
				),
				'home' => 'https://site.example',
			)
		);

		wp_mlp_test_request( array() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		wp_mlp_test_request( array() );
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * Свежий переключатель для указанного адреса: LanguageResolver
	 * мемоизирует разбор пути, поэтому на каждый запрос нужен новый.
	 */
	private function switcherFor( string $requestUri ): LocaleSwitcher {
		$_SERVER['REQUEST_URI'] = $requestUri;

		return new LocaleSwitcher( new LanguageResolver( new Settings() ) );
	}

	public function testSecondaryLanguageSwitchesToItsWordPressLocale(): void {
		$switcher = $this->switcherFor( '/en/about/' );

		$this->assertSame( 'en_US', $switcher->targetLocale() );
		$this->assertSame( 'en_US', $switcher->filterLocale( 'ru_RU' ) );
	}

	public function testEachLanguageGetsItsOwnLocale(): void {
		$this->assertSame( 'bg_BG', $this->switcherFor( '/bg/about/' )->targetLocale() );
		$this->assertSame( 'en_US', $this->switcherFor( '/en/about/' )->targetLocale() );
	}

	/**
	 * На языке по умолчанию менять нечего: локаль сайта и так его локаль.
	 * Фильтр обязан вернуть значение WordPress нетронутым.
	 */
	public function testDefaultLanguageLeavesTheLocaleAlone(): void {
		$switcher = $this->switcherFor( '/about/' );

		$this->assertNull( $switcher->targetLocale() );
		$this->assertSame( 'ru_RU', $switcher->filterLocale( 'ru_RU' ) );
	}

	/**
	 * Главная проверка раздела 7: в админке подмены быть не должно, иначе
	 * wp-admin у владельца сайта внезапно станет англоязычным.
	 */
	public function testAdminIsNeverSwitched(): void {
		wp_mlp_test_request( array( 'is_admin' => true ) );

		$switcher = $this->switcherFor( '/en/about/' );

		$this->assertNull( $switcher->targetLocale() );
		$this->assertSame( 'ru_RU', $switcher->filterLocale( 'ru_RU' ) );
	}

	public function testAjaxIsNeverSwitched(): void {
		wp_mlp_test_request( array( 'ajax' => true ) );

		$this->assertNull( $this->switcherFor( '/en/about/' )->targetLocale() );
	}

	public function testCronIsNeverSwitched(): void {
		wp_mlp_test_request( array( 'cron' => true ) );

		$this->assertNull( $this->switcherFor( '/en/about/' )->targetLocale() );
	}

	/**
	 * POST не переключается намеренно: именно на нём WordPress шлёт
	 * транзакционные письма (уведомление о комментарии), и подмена языка
	 * не должна в них протечь — язык писем в этой версии не меняется.
	 */
	public function testPostRequestIsNeverSwitched(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertNull( $this->switcherFor( '/en/about/' )->targetLocale() );
	}

	public function testHeadRequestIsSwitchedLikeGet(): void {
		$_SERVER['REQUEST_METHOD'] = 'HEAD';

		$this->assertSame( 'en_US', $this->switcherFor( '/en/about/' )->targetLocale() );
	}

	/**
	 * Язык, у которого локаль почему-то пуста (повреждённые настройки),
	 * не должен подменять локаль на пустую строку — WordPress от такого
	 * значения не найдёт вообще никаких переводов, включая исходные.
	 */
	public function testEmptyWordPressLocaleFallsBackToTheSiteLocale(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published' ),
						// Пустая строка тут не сохранится через sanitize(), но
						// настройки могли прийти из импорта или чужого кода.
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'status' => 'published', 'wp_locale' => '' ),
					),
				),
				'home' => 'https://site.example',
			)
		);

		// Language::fromArray() выводит локаль из кода, когда её нет, —
		// поэтому даже здесь получается осмысленное значение, а не пустота.
		$this->assertSame( 'en_US', $this->switcherFor( '/en/about/' )->targetLocale() );
	}

	/**
	 * Черновой язык публичного адреса не имеет вовсе (LanguageResolver
	 * отдаёт для него язык по умолчанию), значит и локаль не меняется.
	 */
	public function testDraftLanguageDoesNotSwitchTheLocale(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published', 'wp_locale' => 'ru_RU' ),
						'de' => array( 'locale' => 'de', 'slug' => 'de', 'status' => 'draft', 'wp_locale' => 'de_DE' ),
					),
				),
				'home' => 'https://site.example',
			)
		);

		$this->assertNull( $this->switcherFor( '/de/about/' )->targetLocale() );
	}
}
