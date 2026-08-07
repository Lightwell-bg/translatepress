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
}
