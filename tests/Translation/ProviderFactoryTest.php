<?php
/**
 * Тесты сборки провайдера перевода из настроек.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Translation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Settings\Settings;
use WpMlp\Support\Env;
use WpMlp\Translation\ManualProvider;
use WpMlp\Translation\OpenAiProvider;
use WpMlp\Translation\ProviderFactory;

#[CoversClass( ProviderFactory::class )]
final class ProviderFactoryTest extends TestCase {

	protected function setUp(): void {
		Env::reset();
		wp_mlp_test_options( array( Settings::OPTION => Settings::defaults() ) );
	}

	protected function tearDown(): void {
		Env::reset();
		wp_mlp_test_options( array() );
	}

	/**
	 * @param array<string, mixed> $overrides Значения поверх настроек по умолчанию.
	 */
	private function factory( array $overrides = array() ): ProviderFactory {
		wp_mlp_test_options( array( Settings::OPTION => array_merge( Settings::defaults(), $overrides ) ) );

		return new ProviderFactory( new Settings() );
	}

	public function testNothingConfiguredReportsBothFieldsMissing(): void {
		$factory = $this->factory();

		$this->assertSame(
			array( ProviderFactory::FIELD_KEY, ProviderFactory::FIELD_MODEL ),
			$factory->missing()
		);
		$this->assertFalse( $factory->isReady() );
		$this->assertInstanceOf( ManualProvider::class, $factory->create() );
	}

	/**
	 * Ровно та ситуация, из-за которой кнопка «Перевести с ИИ» не появлялась:
	 * ключ сохранён, модель пустая. Раньше интерфейс сообщал, что не настроен
	 * ключ, и владелец сайта проверял не то поле.
	 */
	public function testKeyWithoutModelReportsExactlyTheModelAsMissing(): void {
		$factory = $this->factory( array( 'openai_api_key' => 'sk-test' ) );

		$this->assertSame( array( ProviderFactory::FIELD_MODEL ), $factory->missing() );
		$this->assertFalse( $factory->isReady() );
		$this->assertInstanceOf( ManualProvider::class, $factory->create() );
	}

	public function testModelWithoutKeyReportsExactlyTheKeyAsMissing(): void {
		$factory = $this->factory( array( 'openai_model' => 'gpt-4o-mini' ) );

		$this->assertSame( array( ProviderFactory::FIELD_KEY ), $factory->missing() );
	}

	public function testFullyConfiguredBuildsRealProvider(): void {
		$factory = $this->factory(
			array(
				'openai_api_key' => 'sk-test',
				'openai_model'   => 'gpt-4o-mini',
			)
		);

		$this->assertTrue( $factory->isReady() );
		$this->assertInstanceOf( OpenAiProvider::class, $factory->create() );
	}

	/**
	 * Откат к .env рассматривается по каждому полю отдельно. Раньше он был
	 * «всё или ничего»: перенёс ключ в базу — модель из файла молча пропала.
	 */
	public function testEnvFillsInOnlyTheFieldsMissingFromSettings(): void {
		$path = tempnam( sys_get_temp_dir(), 'mlp' );
		file_put_contents( $path, "OPENAI_MODEL=model-from-env\n" );

		Env::reset();
		Env::load( $path );

		$factory = $this->factory( array( 'openai_api_key' => 'sk-from-db' ) );

		$this->assertSame( 'sk-from-db', $factory->apiKey() );
		$this->assertSame( 'model-from-env', $factory->model() );
		$this->assertTrue( $factory->isReady() );

		unlink( $path );
	}

	public function testSettingsWinOverEnv(): void {
		$path = tempnam( sys_get_temp_dir(), 'mlp' );
		file_put_contents( $path, "OPENAI_API_KEY=sk-from-env\nOPENAI_MODEL=model-from-env\n" );

		Env::reset();
		Env::load( $path );

		$factory = $this->factory(
			array(
				'openai_api_key' => 'sk-from-db',
				'openai_model'   => 'model-from-db',
			)
		);

		$this->assertSame( 'sk-from-db', $factory->apiKey() );
		$this->assertSame( 'model-from-db', $factory->model() );

		unlink( $path );
	}

	public function testBaseUrlDefaultsDoNotShadowEnvGateway(): void {
		$path = tempnam( sys_get_temp_dir(), 'mlp' );
		file_put_contents( $path, "OPENAI_BASE_URL=https://gateway.test/v1\n" );

		Env::reset();
		Env::load( $path );

		// В настройках адрес остался значением по умолчанию — это «не задан».
		$factory = $this->factory();

		$this->assertSame( 'https://gateway.test/v1', $factory->baseUrl() );

		unlink( $path );
	}

	public function testExplicitBaseUrlInSettingsWins(): void {
		$factory = $this->factory( array( 'openai_base_url' => 'https://own.test/v1' ) );

		$this->assertSame( 'https://own.test/v1', $factory->baseUrl() );
	}

	public function testWhitespaceOnlyValuesCountAsMissing(): void {
		$factory = $this->factory(
			array(
				'openai_api_key' => '   ',
				'openai_model'   => "\t",
			)
		);

		$this->assertSame(
			array( ProviderFactory::FIELD_KEY, ProviderFactory::FIELD_MODEL ),
			$factory->missing()
		);
	}

	/**
	 * Фабрика с подменённым поиском констант wp-config.php.
	 *
	 * @param array<string, string> $constants Имя константы => значение.
	 * @param array<string, mixed>  $settings  Значения поверх настроек по умолчанию.
	 */
	private function factoryWithConstants( array $constants, array $settings = array() ): ProviderFactory {
		wp_mlp_test_options( array( Settings::OPTION => array_merge( Settings::defaults(), $settings ) ) );

		return new ProviderFactory( new Settings(), static fn( string $name ): ?string => $constants[ $name ] ?? null );
	}

	/**
	 * Записывает временный env-файл и загружает его.
	 *
	 * @param string $contents Содержимое файла.
	 * @param string $name     Имя файла (по нему определяется источник).
	 */
	private function loadEnvFile( string $contents, string $name ): string {
		$dir = sys_get_temp_dir() . '/mlp-' . uniqid();
		mkdir( $dir );
		$path = $dir . '/' . $name;
		file_put_contents( $path, $contents );
		Env::load( $path );

		return $path;
	}

	private function removeEnvFile( string $path ): void {
		unlink( $path );
		rmdir( dirname( $path ) );
	}

	public function testConstantsWinOverSettingsAndEnv(): void {
		$path = $this->loadEnvFile( "OPENAI_API_KEY=sk-env\nOPENAI_MODEL=m-env\nOPENAI_BASE_URL=https://env.test/v1\n", '.env' );

		$factory = $this->factoryWithConstants(
			array(
				ProviderFactory::CONST_API_KEY  => 'sk-const',
				ProviderFactory::CONST_MODEL    => 'm-const',
				ProviderFactory::CONST_BASE_URL => 'https://const.test/v1',
			),
			array(
				'openai_api_key'  => 'sk-db',
				'openai_model'    => 'm-db',
				'openai_base_url' => 'https://db.test/v1',
			)
		);

		$this->assertSame( 'sk-const', $factory->apiKey() );
		$this->assertSame( 'm-const', $factory->model() );
		$this->assertSame( 'https://const.test/v1', $factory->baseUrl() );
		$this->assertTrue( $factory->isOverriddenByConstant( ProviderFactory::CONST_MODEL ) );

		$this->removeEnvFile( $path );
	}

	public function testEmptyConstantIsIgnored(): void {
		$factory = $this->factoryWithConstants(
			array( ProviderFactory::CONST_API_KEY => '   ' ),
			array( 'openai_api_key' => 'sk-db' )
		);

		$this->assertSame( 'sk-db', $factory->apiKey() );
		$this->assertSame( ProviderFactory::SOURCE_DATABASE, $factory->apiKeySource() );
		$this->assertFalse( $factory->isOverriddenByConstant( ProviderFactory::CONST_API_KEY ) );
	}

	public function testConstantsFallBackPerField(): void {
		$factory = $this->factoryWithConstants(
			array( ProviderFactory::CONST_API_KEY => 'sk-const' ),
			array( 'openai_model' => 'm-db' )
		);

		$this->assertSame( 'sk-const', $factory->apiKey() );
		$this->assertSame( 'm-db', $factory->model() );
		$this->assertSame( Settings::DEFAULT_OPENAI_BASE_URL, $factory->baseUrl() );
	}

	public function testContentEnvWinsOverPluginEnvWhenLoadedFirst(): void {
		$content = $this->loadEnvFile( "OPENAI_API_KEY=sk-content\n", 'wp-mlp.env.php' );
		$plugin  = $this->loadEnvFile( "OPENAI_API_KEY=sk-plugin\nOPENAI_MODEL=m-plugin\n", '.env' );

		$factory = $this->factoryWithPaths( array( 'content' => $content ) );

		$this->assertSame( 'sk-content', $factory->apiKey() );
		$this->assertSame( 'm-plugin', $factory->model() );
		$this->assertSame( ProviderFactory::SOURCE_CONTENT_ENV, $factory->apiKeySource() );

		$this->removeEnvFile( $content );
		$this->removeEnvFile( $plugin );
	}

	public function testApiKeySourceForEachSource(): void {
		$this->assertSame( ProviderFactory::SOURCE_NONE, $this->factory()->apiKeySource() );

		$this->assertSame( ProviderFactory::SOURCE_DATABASE, $this->factory( array( 'openai_api_key' => 'sk-db' ) )->apiKeySource() );

		// Константа приоритетнее базы.
		$this->assertSame(
			ProviderFactory::SOURCE_CONSTANT,
			$this->factoryWithConstants( array( ProviderFactory::CONST_API_KEY => 'k' ), array( 'openai_api_key' => 'sk-db' ) )->apiKeySource()
		);

		Env::reset();
		$content = $this->loadEnvFile( "OPENAI_API_KEY=sk-content\n", 'wp-mlp.env.php' );
		$this->assertSame( ProviderFactory::SOURCE_CONTENT_ENV, $this->factoryWithPaths( array( 'content' => $content ) )->apiKeySource() );
		$this->removeEnvFile( $content );

		Env::reset();
		$plugin = $this->loadEnvFile( "OPENAI_API_KEY=sk-plugin\n", '.env' );
		$this->assertSame( ProviderFactory::SOURCE_PLUGIN_ENV, $this->factory()->apiKeySource() );
		$this->removeEnvFile( $plugin );

		Env::reset();
		putenv( 'OPENAI_API_KEY=sk-process' );
		$this->assertSame( ProviderFactory::SOURCE_PROCESS_ENV, $this->factory()->apiKeySource() );
		putenv( 'OPENAI_API_KEY' );
	}

	/**
	 * @param array{content?: ?string, parent?: ?string} $paths    Пути env-файлов.
	 * @param array<string, mixed>                       $settings Значения поверх настроек по умолчанию.
	 */
	private function factoryWithPaths( array $paths, array $settings = array() ): ProviderFactory {
		wp_mlp_test_options( array( Settings::OPTION => array_merge( Settings::defaults(), $settings ) ) );

		return new ProviderFactory( new Settings(), static fn( string $name ): ?string => null, $paths );
	}

	public function testSourceIsClassifiedByExactPathNotBasename(): void {
		// Файл с «защищённым» именем, но не по настоящему пути — это не источник A.
		$fake = $this->loadEnvFile( "OPENAI_API_KEY=sk-fake\n", 'wp-mlp.env.php' );

		$factory = $this->factoryWithPaths( array( 'content' => '/somewhere/else/wp-mlp.env.php' ) );

		$this->assertSame( ProviderFactory::SOURCE_PLUGIN_ENV, $factory->apiKeySource() );

		$this->removeEnvFile( $fake );
	}

	public function testParentEnvIsClassifiedAndOrderIsContentParentPlugin(): void {
		$content = $this->loadEnvFile( "OPENAI_MODEL=m-content\n", 'wp-mlp.env.php' );
		$parent  = $this->loadEnvFile( "OPENAI_API_KEY=sk-parent\nOPENAI_MODEL=m-parent\n", 'wp-mlp.env' );
		$plugin  = $this->loadEnvFile( "OPENAI_API_KEY=sk-plugin\nOPENAI_BASE_URL=https://plugin.test/v1\n", '.env' );

		$factory = $this->factoryWithPaths( array( 'content' => $content, 'parent' => $parent ) );

		$this->assertSame( 'm-content', $factory->model() );
		$this->assertSame( 'sk-parent', $factory->apiKey() );
		$this->assertSame( 'https://plugin.test/v1', $factory->baseUrl() );
		$this->assertSame( ProviderFactory::SOURCE_PARENT_ENV, $factory->apiKeySource() );

		$this->removeEnvFile( $content );
		$this->removeEnvFile( $parent );
		$this->removeEnvFile( $plugin );
	}

	public function testExplicitDefaultBaseUrlBeatsEnvGateway(): void {
		$path = $this->loadEnvFile( "OPENAI_BASE_URL=https://gateway.test/v1\n", '.env' );

		$factory = $this->factory(
			array(
				'openai_base_url'          => Settings::DEFAULT_OPENAI_BASE_URL,
				'openai_base_url_explicit' => true,
			)
		);

		$this->assertSame( Settings::DEFAULT_OPENAI_BASE_URL, $factory->baseUrl() );

		$this->removeEnvFile( $path );
	}

	public function testLegacyStoredDefaultWithoutFlagLosesToEnv(): void {
		$path = $this->loadEnvFile( "OPENAI_BASE_URL=https://gateway.test/v1\n", '.env' );

		$factory = $this->factory( array( 'openai_base_url' => Settings::DEFAULT_OPENAI_BASE_URL ) );

		$this->assertSame( 'https://gateway.test/v1', $factory->baseUrl() );

		$this->removeEnvFile( $path );
	}

	public function testEmptyStoredBaseUrlFallsToEnvThenDefault(): void {
		$factory = $this->factory( array( 'openai_base_url' => '' ) );

		$this->assertSame( Settings::DEFAULT_OPENAI_BASE_URL, $factory->baseUrl() );

		$path = $this->loadEnvFile( "OPENAI_BASE_URL=https://gateway.test/v1\n", '.env' );

		$this->assertSame( 'https://gateway.test/v1', $factory->baseUrl() );

		$this->removeEnvFile( $path );
	}

	public function testWhitespaceEnvAndDbValuesCountAsMissing(): void {
		$path = $this->loadEnvFile( "OPENAI_API_KEY=   \nOPENAI_MODEL=\"  \"\n", '.env' );

		$factory = $this->factory( array( 'openai_base_url' => '   ' ) );

		$this->assertSame( array( ProviderFactory::FIELD_KEY, ProviderFactory::FIELD_MODEL ), $factory->missing() );
		$this->assertSame( Settings::DEFAULT_OPENAI_BASE_URL, $factory->baseUrl() );

		$this->removeEnvFile( $path );
	}

	public function testWhitespaceConstantIsTrimmedAndBlankOneIgnored(): void {
		$factory = $this->factoryWithConstants(
			array(
				ProviderFactory::CONST_API_KEY => "  sk-const \n",
				ProviderFactory::CONST_MODEL   => '  ',
			),
			array( 'openai_model' => ' m-db ' )
		);

		$this->assertSame( 'sk-const', $factory->apiKey() );
		$this->assertSame( 'm-db', $factory->model() );
	}

	public function testUnavailableMessageNamesExactlyTheMissingFields(): void {
		$key   = ProviderFactory::unavailableMessage( array( ProviderFactory::FIELD_KEY ) );
		$model = ProviderFactory::unavailableMessage( array( ProviderFactory::FIELD_MODEL ) );
		$both  = ProviderFactory::unavailableMessage( array( ProviderFactory::FIELD_KEY, ProviderFactory::FIELD_MODEL ) );

		$this->assertStringContainsString( 'укажите ключ в настройках плагина', $key );
		$this->assertStringNotContainsString( 'модель', $key );
		$this->assertStringContainsString( 'укажите модель в настройках плагина', $model );
		$this->assertStringNotContainsString( 'ключ', $model );
		$this->assertStringContainsString( 'ключ и модель', $both );
	}
}
