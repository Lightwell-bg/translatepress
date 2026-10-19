<?php
/**
 * Флаги: загрузка, чтение и удаление идут в каталоге lingocanvas-flags.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Storage;

use LingoCanvas\Admin\FlagUpload;
use LingoCanvas\Frontend\Flags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( FlagUpload::class )]
#[CoversClass( Flags::class )]
final class FlagsWritePathTest extends TestCase {

	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="3" height="2"><rect width="3" height="2" fill="#123456"/></svg>';

	private string $uploads;

	protected function setUp(): void {
		unset( $GLOBALS['wpdb'] );
		$this->uploads = sys_get_temp_dir() . '/lingocanvas-flagwrite-' . uniqid();
		mkdir( $this->uploads, 0777, true );
		lingocanvas_test_uploads( $this->uploads );
		lingocanvas_test_options( array() );
	}

	protected function tearDown(): void {
		$this->remove( $this->uploads );
		lingocanvas_test_uploads( sys_get_temp_dir() . '/lingocanvas-uploads' );
		lingocanvas_test_options( array() );
	}

	private function remove( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $name ) {
				if ( '.' !== $name && '..' !== $name ) {
					$this->remove( $path . '/' . $name );
				}
			}

			rmdir( $path );
		} elseif ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	public function test_the_directory_name_is_fixed(): void {
		$this->assertSame( 'lingocanvas-flags', Flags::DIRECTORY );
		$this->assertSame( $this->uploads . '/lingocanvas-flags', Flags::directoryPath() );
	}

	public function test_upload_creates_the_folder_and_the_flag_is_read_back(): void {
		$this->assertDirectoryDoesNotExist( $this->uploads . '/lingocanvas-flags' );
		$this->assertTrue( FlagUpload::store( 'ru', self::SVG ), 'Папка создаётся при первой загрузке.' );

		$this->assertFileExists( $this->uploads . '/lingocanvas-flags/ru.svg' );
		$this->assertSame( 'https://example.test/wp-content/uploads/lingocanvas-flags/ru.svg', Flags::url( 'ru' ) );
		$this->assertSame( $this->uploads . '/lingocanvas-flags/ru.svg', Flags::filePath( 'ru' ) );
		$this->assertSame( '', Flags::url( 'de' ) );
	}

	public function test_a_file_in_any_other_folder_is_not_read(): void {
		mkdir( $this->uploads . '/other-flags' );
		file_put_contents( $this->uploads . '/other-flags/ru.svg', self::SVG );

		$this->assertSame( '', Flags::url( 'ru' ) );
		$this->assertSame( '', Flags::filePath( 'ru' ) );
	}

	public function test_removing_a_flag_deletes_the_file(): void {
		$this->assertTrue( FlagUpload::store( 'ru', self::SVG ) );
		$this->assertTrue( FlagUpload::remove( 'ru' ) );

		$this->assertFileDoesNotExist( $this->uploads . '/lingocanvas-flags/ru.svg' );
		$this->assertSame( '', Flags::url( 'ru' ) );
	}

	public function test_removing_a_flag_that_does_not_exist_is_a_no_op(): void {
		$this->assertFalse( FlagUpload::remove( 'fr' ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/lingocanvas-flags' );
	}

	public function test_an_invalid_locale_or_empty_svg_is_rejected(): void {
		$this->assertFalse( FlagUpload::store( '../x', self::SVG ) );
		$this->assertFalse( FlagUpload::store( 'ru', 'not an svg' ) );
		$this->assertFalse( FlagUpload::remove( '../x' ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/lingocanvas-flags' );
	}
}
