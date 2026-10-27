<?php
/**
 * Все строки LingoCanvas Cloud, которые видит владелец сайта, переведены на русский
 * и попали в скомпилированный .l10n.php и в .pot.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\I18n;

use PHPUnit\Framework\TestCase;

final class CloudStringsTranslatedTest extends TestCase {

	/**
	 * Сообщения CloudException::messageFor() для всех кодов, ProviderFactory и контроллеров.
	 *
	 * @return array<string, array{string}>
	 */
	public static function msgids(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array(
			'src/Translation/CloudException.php',
			'src/Translation/ProviderFactory.php',
			'src/Admin/CloudActions.php',
			'src/Admin/CloudSection.php',
			'src/Admin/CloudStatus.php',
			'src/Rest/TranslationsController.php',
			'src/Rest/PostTranslationController.php',
		);
		$out   = array();

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file );

			if ( preg_match_all( "/\b(?:__|esc_html__|esc_attr__)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'lingocanvas'/", $source, $matches ) ) {
				foreach ( $matches[1] as $raw ) {
					$id         = stripslashes( $raw );
					$out[ $id ] = array( $id );
				}
			}
		}

		return $out;
	}

	/**
	 * @dataProvider msgids
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'msgids' )]
	public function testStringHasRussianTranslationAndIsInPot( string $msgid ): void {
		$l10n = require dirname( __DIR__, 2 ) . '/languages/lingocanvas-ru_RU.l10n.php';

		$this->assertArrayHasKey( $msgid, $l10n['messages'], 'Нет перевода: ' . $msgid );
		$this->assertNotSame( '', $l10n['messages'][ $msgid ] );

		$pot = (string) file_get_contents( dirname( __DIR__, 2 ) . '/languages/lingocanvas.pot' );
		$this->assertStringContainsString( 'msgid "' . addcslashes( $msgid, "\"\\" ) . '"', $pot );
	}

	public function testNoStaleOpenAiOnlyErrorMessagesRemain(): void {
		$root = dirname( __DIR__, 2 );

		foreach ( array( 'src/Rest/TranslationsController.php', 'src/Rest/PostTranslationController.php' ) as $file ) {
			$this->assertStringNotContainsString( 'Could not get a translation from OpenAI', (string) file_get_contents( $root . '/' . $file ) );
		}
	}
}
