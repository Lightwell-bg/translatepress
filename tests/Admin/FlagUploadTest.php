<?php
/**
 * Тесты очистки загружаемого файла флага.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\FlagUpload;

#[CoversClass( FlagUpload::class )]
final class FlagUploadTest extends TestCase {

	/**
	 * SVG — исполняемый формат: внутри может быть скрипт, и файл в
	 * загрузках открывается по прямой ссылке, то есть скрипт выполнится
	 * на домене сайта со всеми правами открывшего. Именно поэтому
	 * WordPress не даёт грузить SVG без плагина. Чистим сами.
	 *
	 * @param string $svg      Загруженное содержимое.
	 * @param string $mustLose Фрагмент, которого в результате быть не должно.
	 */
	#[DataProvider( 'dangerous' )]
	public function testDangerousContentIsStripped( string $svg, string $mustLose ): void {
		$clean = FlagUpload::sanitize( $svg );

		$this->assertNotSame( '', $clean, 'Картинка не должна пропадать целиком.' );
		$this->assertStringNotContainsStringIgnoringCase( $mustLose, $clean );
	}

	/**
	 * @return list<array{string, string}>
	 */
	public static function dangerous(): array {
		return array(
			array(
				'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="9" height="6"/></svg>',
				'<script',
			),
			array(
				'<svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="6" onload="alert(1)"/></svg>',
				'onload',
			),
			array(
				'<svg xmlns="http://www.w3.org/2000/svg" onclick="alert(1)"><rect width="9" height="6"/></svg>',
				'onclick',
			),
			array(
				'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><rect width="9" height="6"/></a></svg>',
				'javascript:',
			),
			array(
				'<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject><rect width="9" height="6"/></svg>',
				'foreignObject',
			),
			array(
				'<svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="6" style="background:url(javascript:alert(1))"/></svg>',
				'javascript:',
			),
		);
	}

	/**
	 * Внешние сущности читают файлы сервера и ходят по сети. Флагу они не
	 * нужны ни при каком раскладе.
	 */
	public function testExternalEntitiesAreRefused(): void {
		/*
		 * Файл создаётся настоящий: ссылка на заведомо отсутствующий путь
		 * (вроде /etc/passwd на Windows) прошла бы и без защиты — подставлять
		 * было бы нечего, и тест бы ничего не проверял. Это и показала
		 * мутационная проверка.
		 */
		$secret = tempnam( sys_get_temp_dir(), 'mlp-xxe' );

		file_put_contents( $secret, 'СЕКРЕТНОЕ-СОДЕРЖИМОЕ-ФАЙЛА' );

		$xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file://'
			. str_replace( '\\', '/', $secret ) . '">]>'
			. '<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';

		$clean = FlagUpload::sanitize( $xxe );

		unlink( $secret );

		// Файл с объявлением сущности отвергается целиком.
		$this->assertSame( '', $clean );
		$this->assertStringNotContainsString( 'СЕКРЕТНОЕ-СОДЕРЖИМОЕ-ФАЙЛА', $clean, 'Содержимое файла сервера утекло в картинку.' );
	}

	/**
	 * Обычный DOCTYPE без объявления сущностей встречается у старых
	 * редакторов и ничем не опасен — из-за него терять флаг незачем.
	 */
	public function testPlainDoctypeIsAccepted(): void {
		$svg = '<?xml version="1.0"?>'
			. '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
			. '<svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="6" fill="#d52b1e"/></svg>';

		$this->assertStringContainsString( '#d52b1e', FlagUpload::sanitize( $svg ) );
	}

	/**
	 * Настоящий флаг обязан пережить очистку без потерь: если вырезать
	 * лишнее, вместо флага получится пустой прямоугольник.
	 */
	public function testRealFlagSurvives(): void {
		$flag = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480">'
			. '<path fill="#fff" d="M0 0h640v160H0z"/>'
			. '<path fill="#0039a6" d="M0 160h640v160H0z"/>'
			. '<path fill="#d52b1e" d="M0 320h640v160H0z"/>'
			. '</svg>';

		$clean = FlagUpload::sanitize( $flag );

		$this->assertStringContainsString( '#0039a6', $clean );
		$this->assertStringContainsString( 'viewBox="0 0 640 480"', $clean );
		$this->assertSame( 3, substr_count( $clean, '<path' ) );
	}

	/**
	 * Не-SVG отвергается целиком: пустая строка означает «не сохранять».
	 *
	 * @param string $content Загруженное содержимое.
	 */
	#[DataProvider( 'notSvg' )]
	public function testNonSvgIsRejected( string $content ): void {
		$this->assertSame( '', FlagUpload::sanitize( $content ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function notSvg(): array {
		return array(
			array( '' ),
			array( '   ' ),
			array( 'просто текст' ),
			array( '<html><body>не картинка</body></html>' ),
			array( "\x89PNG\r\n\x1a\n" ),
			array( '<?php echo 1; ?>' ),
			array( '<svg' ),
		);
	}

	/**
	 * Флаги весят единицы килобайт. Ограничение отсекает и случайную
	 * загрузку не того файла, и попытку занять место.
	 */
	public function testOversizedFileIsRejected(): void {
		$huge = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<!-- ' . str_repeat( 'x', FlagUpload::MAX_BYTES ) . ' -->'
			. '</svg>';

		$this->assertSame( '', FlagUpload::sanitize( $huge ) );
	}
}
