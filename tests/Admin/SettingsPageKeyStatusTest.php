<?php
/**
 * Тесты строки статуса ключа OpenAI на странице настроек.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\SettingsPage;
use WpMlp\I18n\LanguagePacks;
use WpMlp\Settings\Settings;
use WpMlp\Support\Env;
use WpMlp\Translation\ProviderFactory;

#[CoversClass( SettingsPage::class )]
final class SettingsPageKeyStatusTest extends TestCase {

	protected function setUp(): void {
		Env::reset();
		wp_mlp_test_options( array( Settings::OPTION => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		Env::reset();
		wp_mlp_test_options( array() );
	}

	/**
	 * @param array<string, string> $constants Константы wp-config.php.
	 * @param array<string, mixed>  $overrides Настройки поверх значений по умолчанию.
	 * @param array{content?: ?string, parent?: ?string}|null $envPaths Пути env-файлов.
	 */
	private function page( array $constants = array(), array $overrides = array(), ?array $envPaths = null ): SettingsPage {
		wp_mlp_test_options( array( Settings::OPTION => array_merge( Settings::defaults(), $overrides ) ) );

		$settings = new Settings();

		return new SettingsPage(
			$settings,
			new LanguagePacks(),
			new ProviderFactory( $settings, static fn( string $name ): ?string => $constants[ $name ] ?? null, $envPaths )
		);
	}

	public function testConstantStatusNamesConstantWithoutKey(): void {
		$html = $this->page( array( ProviderFactory::CONST_API_KEY => 'sk-secret-value' ) )->keyStatusHtml();

		$this->assertStringContainsString( 'wp-config.php', $html );
		$this->assertStringContainsString( ProviderFactory::CONST_API_KEY, $html );
		$this->assertStringNotContainsString( 'sk-secret-value', $html );
	}

	public function testClearCheckboxStaysVisibleWhenConstantOverridesSavedKey(): void {
		// render() тянет десятки WP-функций, поэтому проверяем разметку по исходнику:
		// условие показа галочки не должно зависеть от константы.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php' );

		$this->assertSame( 1, preg_match( '/if \( \$this->settings->openAiApiKey\(\) !== \'\' \) : \?>\s*<label>\s*<input type="checkbox" name="openai_api_key_clear"/', $source ) );
	}

	public function testClearCheckboxWipesDbKeyEvenUnderConstant(): void {
		wp_mlp_test_options( array( Settings::OPTION => array_merge( Settings::defaults(), array( 'openai_api_key' => 'sk-old-db-key' ) ) ) );

		$result = ( new Settings() )->sanitize(
			array(
				'default_locale'       => 'ru',
				'openai_api_key'       => '',
				'openai_api_key_clear' => '1',
				'languages'            => array( array( 'locale' => 'ru', 'slug' => 'ru' ) ),
			)
		);

		$this->assertSame( '', $result['settings']['openai_api_key'] );
	}

	public function testDatabaseStatusShowsOnlyLastFourCharacters(): void {
		$html = $this->page( array(), array( 'openai_api_key' => 'sk-secret-abcd' ) )->keyStatusHtml();

		$this->assertStringContainsString( 'переживает обновление плагина', $html );
		$this->assertStringContainsString( 'abcd', $html );
		$this->assertStringNotContainsString( 'sk-secret', $html );
	}

	public function testPluginEnvShowsWarningNotice(): void {
		$dir = sys_get_temp_dir() . '/mlp-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/.env', "OPENAI_API_KEY=sk-plugin-secret\n" );
		Env::load( $dir . '/.env' );

		$html = $this->page()->keyStatusHtml();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'будет удалён при замене плагина', $html );
		$this->assertStringNotContainsString( 'sk-plugin-secret', $html );

		unlink( $dir . '/.env' );
		rmdir( $dir );
	}

	public function testContentEnvAndNoneStatuses(): void {
		$dir = sys_get_temp_dir() . '/mlp-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/wp-mlp.env.php', "<?php exit; ?>\nOPENAI_API_KEY=sk-content\n" );
		Env::loadGuarded( $dir . '/wp-mlp.env.php' );

		$html = $this->page( array(), array(), array( 'content' => $dir . '/wp-mlp.env.php' ) )->keyStatusHtml();

		$this->assertStringContainsString( 'wp-content/wp-mlp.env.php', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );

		unlink( $dir . '/wp-mlp.env.php' );
		rmdir( $dir );
		Env::reset();

		$this->assertStringContainsString( 'Ключ не сохранён', $this->page()->keyStatusHtml() );
	}

	public function testUnguardedContentFileShowsWarning(): void {
		$dir = sys_get_temp_dir() . '/mlp-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/wp-mlp.env.php', "OPENAI_API_KEY=sk-leak\n" );
		Env::loadGuarded( $dir . '/wp-mlp.env.php' );

		$html = $this->page()->keyStatusHtml();

		$this->assertStringContainsString( 'без защитной первой строки', $html );
		$this->assertStringContainsString( 'Ключ не сохранён', $html );
		$this->assertStringNotContainsString( 'sk-leak', $html );

		unlink( $dir . '/wp-mlp.env.php' );
		rmdir( $dir );
	}
}
