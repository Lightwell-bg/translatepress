<?php
/**
 * Тесты поиска файла флага для языка.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\Flags;

#[CoversClass( Flags::class )]
final class FlagsTest extends TestCase {

	/**
	 * Имя файла собирается из кода языка, который приходит из настроек.
	 * Код там валидируется, но имя файла уходит в файловую систему, и
	 * полагаться на чужую валидацию здесь нельзя: путь наружу каталога
	 * не должен собираться ни при каком входе.
	 *
	 * @param string $locale   Код языка.
	 * @param string $expected Ожидаемое имя файла, `` — файла быть не может.
	 */
	#[DataProvider( 'names' )]
	public function testFileName( string $locale, string $expected ): void {
		$this->assertSame( $expected, Flags::fileName( $locale ) );
	}

	/**
	 * @return list<array{string, string}>
	 */
	public static function names(): array {
		return array(
			array( 'ru', 'ru.svg' ),
			array( 'bg', 'bg.svg' ),
			array( 'en', 'en.svg' ),

			// Код с регионом — тем же правилом, без выдумок.
			array( 'pt-br', 'pt-br.svg' ),

			// Регистр приводится: файловые системы Linux регистрозависимы.
			array( 'RU', 'ru.svg' ),

			// Пробелы по краям и `_` вместо `-` — обычная опечатка в поле,
			// а не попытка обмана: нормализация их исправляет.
			array( ' ru ', 'ru.svg' ),
			array( 'pt_BR', 'pt-br.svg' ),

			// Всё, что не проходит проверку кода языка, файла не получает.
			array( '', '' ),
			array( '../../wp-config.php', '' ),
			array( 'ru/../../etc/passwd', '' ),
			array( 'ru.svg', '' ),
			array( 'ru\\..\\x', '' ),
			array( 'ru%2f..%2f', '' ),
			array( '<script>', '' ),
		);
	}

	/**
	 * Картинка отдаётся, только когда файл действительно лежит на месте.
	 * Иначе переключатель показал бы битую картинку вместо флага, а
	 * запасной вариант (emoji или код) не сработал бы вовсе.
	 */
	public function testUrlOnlyWhenTheFileIsThere(): void {
		$uploads = sys_get_temp_dir() . '/wp-mlp-flags-test-' . uniqid();

		mkdir( $uploads . '/' . Flags::DIRECTORY, 0777, true );
		file_put_contents( $uploads . '/' . Flags::DIRECTORY . '/bg.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );

		wp_mlp_test_uploads( $uploads );

		$this->assertStringEndsWith( '/' . Flags::DIRECTORY . '/bg.svg', Flags::url( 'bg' ) );

		// Файла нет — и адреса нет, вместо него сработает запасной вариант.
		$this->assertSame( '', Flags::url( 'ru' ) );

		// Негодный код языка до файловой системы не доходит вовсе.
		$this->assertSame( '', Flags::url( '../../wp-config.php' ) );

		unlink( $uploads . '/' . Flags::DIRECTORY . '/bg.svg' );
		rmdir( $uploads . '/' . Flags::DIRECTORY );
		rmdir( $uploads );
		wp_mlp_test_uploads( sys_get_temp_dir() . '/wp-mlp-uploads' );
	}

	/**
	 * Имя файла всегда остаётся именем: ни одного разделителя пути.
	 *
	 * @param string $locale   Код языка.
	 * @param string $expected Ожидаемое имя файла.
	 */
	#[DataProvider( 'names' )]
	public function testFileNameNeverContainsPathSeparators( string $locale, string $expected ): void {
		$name = Flags::fileName( $locale );

		unset( $expected );

		$this->assertSame( basename( $name ), $name );
		$this->assertStringNotContainsString( '..', $name );
	}
}
