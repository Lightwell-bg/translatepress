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

	/**
	 * @param string $contents Содержимое временного файла.
	 */
	private function tempEnv( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'mlp' );
		file_put_contents( $path, $contents );

		return $path;
	}

	public function testFirstLoadedFileWins(): void {
		$first  = $this->tempEnv( "MLP_TEST_ORDER=from-first\n" );
		$second = $this->tempEnv( "MLP_TEST_ORDER=from-second\nMLP_TEST_ONLY_SECOND=two\n" );

		Env::reset();
		Env::load( $first );
		Env::load( $second );

		$this->assertSame( 'from-first', Env::get( 'MLP_TEST_ORDER' ) );
		$this->assertSame( 'two', Env::get( 'MLP_TEST_ONLY_SECOND' ) );
		$this->assertSame( $first, Env::sourceOf( 'MLP_TEST_ORDER' ) );
		$this->assertSame( $second, Env::sourceOf( 'MLP_TEST_ONLY_SECOND' ) );

		Env::reset();
		unlink( $first );
		unlink( $second );
	}

	public function testEmptyValueInFirstFileDoesNotShadowSecond(): void {
		$first  = $this->tempEnv( "MLP_TEST_EMPTY=\n" );
		$second = $this->tempEnv( "MLP_TEST_EMPTY=real\n" );

		Env::reset();
		Env::load( $first );
		Env::load( $second );

		$this->assertSame( 'real', Env::get( 'MLP_TEST_EMPTY' ) );

		Env::reset();
		unlink( $first );
		unlink( $second );
	}

	public function testProcessEnvironmentWinsAndIsReportedAsProcess(): void {
		$path = $this->tempEnv( "MLP_TEST_PROC=from-file\n" );

		putenv( 'MLP_TEST_PROC=from-process' );
		Env::reset();
		Env::load( $path );

		$this->assertSame( 'from-process', Env::get( 'MLP_TEST_PROC' ) );
		$this->assertSame( 'process', Env::sourceOf( 'MLP_TEST_PROC' ) );

		putenv( 'MLP_TEST_PROC' );
		Env::reset();
		unlink( $path );
	}

	public function testSourceOfUnknownKeyIsNull(): void {
		Env::reset();

		$this->assertNull( Env::sourceOf( 'MLP_TEST_NEVER_SET' ) );
	}

	public function testParseIgnoresPhpGuardLineAndTrimsValues(): void {
		$values = Env::parse( "<?php exit; ?>\nA=  spaced  \nB=\" quoted \"\nC=   \n" );

		$this->assertSame( array( 'A' => 'spaced', 'B' => 'quoted', 'C' => '' ), $values );
	}

	public function testGuardedFileIsLoadedWhenFirstLineIsExitGuard(): void {
		$path = $this->tempEnv( "<?php exit; ?>\nMLP_TEST_GUARDED=ok\n" );

		Env::reset();
		Env::loadGuarded( $path );

		$this->assertSame( 'ok', Env::get( 'MLP_TEST_GUARDED' ) );
		$this->assertSame( array(), Env::unguardedFiles() );

		Env::reset();
		unlink( $path );
	}

	public function testUnguardedFileIsSkippedAndFlagged(): void {
		$path = $this->tempEnv( "MLP_TEST_UNGUARDED=leak\n<?php exit; ?>\n" );

		Env::reset();
		Env::loadGuarded( $path );

		$this->assertSame( '', Env::get( 'MLP_TEST_UNGUARDED' ) );
		$this->assertSame( array( $path ), Env::unguardedFiles() );

		Env::reset();
		$this->assertSame( array(), Env::unguardedFiles() );
		unlink( $path );
	}

	public function testFileOutsideWebRootIsLoadedPlain(): void {
		$dir = sys_get_temp_dir() . '/mlp-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/wp-mlp.env', "MLP_TEST_OUTSIDE=yes\n" );

		Env::reset();
		Env::load( $dir . '/wp-mlp.env' );

		$this->assertSame( 'yes', Env::get( 'MLP_TEST_OUTSIDE' ) );

		Env::reset();
		unlink( $dir . '/wp-mlp.env' );
		rmdir( $dir );
	}

	public function testWhitespaceOnlyValueDoesNotBlockNextFile(): void {
		$first  = $this->tempEnv( "MLP_TEST_WS=   \n" );
		$second = $this->tempEnv( "MLP_TEST_WS= real \n" );

		Env::reset();
		Env::load( $first );
		Env::load( $second );

		$this->assertSame( 'real', Env::get( 'MLP_TEST_WS' ) );
		$this->assertSame( $second, Env::sourceOf( 'MLP_TEST_WS' ) );

		Env::reset();
		unlink( $first );
		unlink( $second );
	}

	public function testSourceOfReportsProcessWhenEnvironmentWasOverriddenAfterLoad(): void {
		$path = $this->tempEnv( "MLP_TEST_OVR=from-file\n" );

		Env::reset();
		Env::load( $path );
		$this->assertSame( $path, Env::sourceOf( 'MLP_TEST_OVR' ) );

		putenv( 'MLP_TEST_OVR=from-other-plugin' );

		$this->assertSame( 'from-other-plugin', Env::get( 'MLP_TEST_OVR' ) );
		$this->assertSame( 'process', Env::sourceOf( 'MLP_TEST_OVR' ) );

		putenv( 'MLP_TEST_OVR' );
		Env::reset();
		unlink( $path );
	}
}
