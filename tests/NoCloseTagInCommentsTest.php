<?php
/**
 * Регрессия: закрывающий тег PHP внутри однострочного комментария.
 *
 * `// ... ?> ...` завершает PHP-режим, и остаток файла выводится как HTML
 * (так лёг живой сайт из-за wp-mlp.php).
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests;

use PHPUnit\Framework\TestCase;

final class NoCloseTagInCommentsTest extends TestCase {

	/**
	 * Ищет однострочные комментарии (`//`, `#`) с закрывающим тегом.
	 *
	 * Токенайзер обрывает такой комментарий перед `?>` и отдаёт T_CLOSE_TAG,
	 * поэтому ловим T_COMMENT, сразу за которым идёт T_CLOSE_TAG в той же строке.
	 * Исключение: строка вида `<?php // ... ?>` (однострочный сниппет шаблона).
	 *
	 * @param string $code Исходный код PHP.
	 * @return int[] Номера строк с нарушением.
	 */
	public static function findCloseTagInLineComments( string $code ): array {
		$tokens = token_get_all( $code );
		$lines  = explode( "\n", $code );
		$found  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_COMMENT !== $token[0] ) {
				continue;
			}
			$text = $token[1];
			if ( ! str_starts_with( $text, '//' ) && ! str_starts_with( $text, '#' ) ) {
				continue;
			}
			$line = $token[2];

			// Старые версии токенайзера могли оставлять закрывающий тег внутри комментария.
			if ( str_contains( $text, '?>' ) ) {
				$found[] = $line;
				continue;
			}

			$next = $tokens[ $i + 1 ] ?? null;
			if ( ! is_array( $next ) || T_CLOSE_TAG !== $next[0] ) {
				continue;
			}
			// Комментарий с переводом строки в конце: закрывающий тег уже на другой строке.
			if ( preg_match( '/[\r\n]$/', $text ) ) {
				continue;
			}
			$source_line = $lines[ $line - 1 ] ?? '';
			if ( preg_match( '/^\s*<\?php\b/', $source_line ) ) {
				continue;
			}
			$found[] = $line;
		}

		return $found;
	}

	/**
	 * Токены T_CLOSE_TAG / T_INLINE_HTML в файле: их не должно быть в чистом PHP.
	 *
	 * @param string $code Исходный код PHP.
	 * @return int[] Номера строк.
	 */
	public static function findHtmlTokens( string $code ): array {
		$found = array();
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && ( T_CLOSE_TAG === $token[0] || T_INLINE_HTML === $token[0] ) ) {
				$found[] = $token[2];
			}
		}
		return $found;
	}

	/**
	 * @param string $dir     Каталог.
	 * @param bool   $recurse Обходить вложенные каталоги.
	 * @return string[]
	 */
	private static function phpFiles( string $dir, bool $recurse ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = array();
		if ( $recurse ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		} else {
			$files = glob( $dir . '/*.php' ) ?: array();
		}
		sort( $files );
		return $files;
	}

	/**
	 * @return string[] Файлы, которые обязаны быть чистым PHP.
	 */
	private static function purePhpFiles(): array {
		$root  = dirname( __DIR__ );
		$files = self::phpFiles( $root . '/bin', false );
		foreach ( array( 'wp-mlp.php', 'uninstall.php' ) as $name ) {
			if ( is_file( $root . '/' . $name ) ) {
				$files[] = $root . '/' . $name;
			}
		}
		return $files;
	}

	public function testDetectorFlagsCloseTagInLineComment(): void {
		$this->assertSame( array( 2 ), self::findCloseTagInLineComments( "<?php\n// a ?> b\n\$x=1;" ) );
		$this->assertSame( array( 2 ), self::findCloseTagInLineComments( "<?php\n# a ?> b\n\$x=1;" ) );
	}

	public function testDetectorAllowsLegitTemplateSnippets(): void {
		$this->assertSame( array(), self::findCloseTagInLineComments( "<p>x</p>\n\t<?php // note ?>\n<b>y</b>\n" ) );
		$this->assertSame( array(), self::findCloseTagInLineComments( "<?php\n// plain comment\n\$x = 1;\n" ) );
	}

	public function testHtmlTokenDetector(): void {
		$this->assertSame( array( 2, 2 ), self::findHtmlTokens( "<?php\n// a ?> b\n\$x=1;" ) );
		$this->assertSame( array(), self::findHtmlTokens( "<?php\n\$x = 1;\n" ) );
	}

	public function testNoCloseTagInLineCommentsInPluginFiles(): void {
		$root  = dirname( __DIR__ );
		$files = array_merge(
			self::purePhpFiles(),
			self::phpFiles( $root . '/src', true )
		);
		$this->assertNotEmpty( $files );

		$errors = array();
		foreach ( $files as $file ) {
			foreach ( self::findCloseTagInLineComments( (string) file_get_contents( $file ) ) as $line ) {
				$errors[] = $file . ':' . $line;
			}
		}
		$this->assertSame( array(), $errors, "`?>` в однострочном комментарии:\n" . implode( "\n", $errors ) );
	}

	public function testPurePhpFilesHaveNoCloseTagOrInlineHtml(): void {
		$files = self::purePhpFiles();
		$this->assertNotEmpty( $files );

		$errors = array();
		foreach ( $files as $file ) {
			foreach ( self::findHtmlTokens( (string) file_get_contents( $file ) ) as $line ) {
				$errors[] = $file . ':' . $line;
			}
		}
		$this->assertSame( array(), $errors, "T_CLOSE_TAG/T_INLINE_HTML в чистом PHP:\n" . implode( "\n", $errors ) );
	}
}
