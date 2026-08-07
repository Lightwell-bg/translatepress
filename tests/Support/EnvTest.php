<?php
/**
 * Тесты разбора .env.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Support\Env;

#[CoversClass( Env::class )]
final class EnvTest extends TestCase {

	public function testParsesSimplePairs(): void {
		$values = Env::parse( "OPENAI_API_KEY=sk-test\nOPENAI_MODEL=gpt-5.6-terra\n" );

		$this->assertSame(
			array(
				'OPENAI_API_KEY' => 'sk-test',
				'OPENAI_MODEL'   => 'gpt-5.6-terra',
			),
			$values
		);
	}

	public function testSkipsCommentsAndBlankLines(): void {
		$values = Env::parse( "# comment\n\nOPENAI_API_KEY=sk-test\n  # indented comment\n" );

		$this->assertSame( array( 'OPENAI_API_KEY' => 'sk-test' ), $values );
	}

	public function testStripsSurroundingQuotes(): void {
		$values = Env::parse( "OPENAI_API_KEY=\"sk-test\"\nOPENAI_MODEL='gpt-5.6-terra'\n" );

		$this->assertSame( 'sk-test', $values['OPENAI_API_KEY'] );
		$this->assertSame( 'gpt-5.6-terra', $values['OPENAI_MODEL'] );
	}

	public function testValueMayContainEqualsSign(): void {
		$values = Env::parse( 'OPENAI_BASE_URL=https://api.openai.com/v1?x=1' );

		$this->assertSame( 'https://api.openai.com/v1?x=1', $values['OPENAI_BASE_URL'] );
	}

	/**
	 * Ключ — только допустимый идентификатор переменной окружения:
	 * никаких пробелов, точек с запятой и прочего, что могло бы означать
	 * что-то иное в окружении процесса.
	 */
	public function testRejectsInvalidKeys(): void {
		$values = Env::parse( "not a key=value\n1STARTS_WITH_DIGIT=value\nVALID_KEY=ok\n" );

		$this->assertSame( array( 'VALID_KEY' => 'ok' ), $values );
	}

	public function testEmptyValueIsAllowed(): void {
		$this->assertSame( array( 'OPENAI_API_KEY' => '' ), Env::parse( 'OPENAI_API_KEY=' ) );
	}

	public function testHandlesWindowsLineEndings(): void {
		$values = Env::parse( "A=1\r\nB=2\r\n" );

		$this->assertSame( array( 'A' => '1', 'B' => '2' ), $values );
	}

	/**
	 * Значение должно читаться, даже если хостинг запретил putenv().
	 *
	 * Раньше get() опирался только на getenv(): при отключённом putenv ключ
	 * из полностью корректного .env считался ненастроенным, и кнопка перевода
	 * молча не появлялась в админке.
	 */
	public function testValueSurvivesWithoutProcessEnvironment(): void {
		$path = tempnam( sys_get_temp_dir(), 'mlp' );

		file_put_contents( $path, "MLP_TEST_KEY=secret-value\n" );

		Env::reset();
		Env::load( $path );

		// Имитируем хостинг без putenv: убираем значение из окружения процесса.
		putenv( 'MLP_TEST_KEY' );

		$this->assertFalse( getenv( 'MLP_TEST_KEY' ) );
		$this->assertSame( 'secret-value', Env::get( 'MLP_TEST_KEY' ) );

		Env::reset();
		unlink( $path );
	}

	public function testMissingFileLeavesDefaults(): void {
		Env::reset();
		Env::load( sys_get_temp_dir() . '/definitely-not-here-' . uniqid() . '.env' );

		$this->assertSame( 'fallback', Env::get( 'MLP_ABSENT_KEY', 'fallback' ) );

		Env::reset();
	}
}
