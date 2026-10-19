<?php
/**
 * Тесты компилятора переводов bin/i18n-compile.php.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\I18n;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/i18n-compile.php';

final class I18nCompileTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/lingocanvas-i18n-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $f ) {
			unlink( $f );
		}
		rmdir( $this->dir );
	}

	private function writePo( string $body ): string {
		$path = $this->dir . '/lingocanvas-ru_RU.po';
		file_put_contents( $path, $body );
		return $path;
	}

	private function fixture(): string {
		return <<<'PO'
msgid ""
msgstr ""
"Project-Id-Version: LingoCanvas\n"
"Language: ru_RU\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n"

#: src/a.php:1
msgid "Save"
msgstr "Сохранить"

msgctxt "menu"
msgid "Save"
msgstr "Сохранить (меню)"

msgid "%d file"
msgid_plural "%d files"
msgstr[0] "%d файл"
msgstr[1] "%d файла"
msgstr[2] "%d файлов"

msgid "Say \"hi\"\n"
msgstr "Скажи \"привет\"\n"

#, fuzzy
msgid "Fuzzy"
msgstr "Неточно"

msgid "Empty"
msgstr ""

#~ msgid "Old"
#~ msgstr "Старое"

PO;
	}

	/**
	 * Мини-читатель .mo.
	 *
	 * @return array<string,string>
	 */
	private function readMo( string $bin ): array {
		$this->assertSame( 'de120495', bin2hex( substr( $bin, 0, 4 ) ) );
		$h = unpack( 'Vmagic/Vrev/Vn/Vo/Vt', $bin );
		$this->assertSame( 0, $h['rev'] );
		$out = array();
		for ( $i = 0; $i < $h['n']; $i++ ) {
			$on = unpack( 'Vlen/Voff', substr( $bin, $h['o'] + 8 * $i, 8 ) );
			$tn = unpack( 'Vlen/Voff', substr( $bin, $h['t'] + 8 * $i, 8 ) );
			$out[ substr( $bin, $on['off'], $on['len'] ) ] = substr( $bin, $tn['off'], $tn['len'] );
		}
		return $out;
	}

	public function test_mo_round_trip(): void {
		$r = lingocanvas_i18n_process( $this->writePo( $this->fixture() ), false );
		$this->assertSame( array(), $r['errors'] );
		$mo = $this->readMo( (string) file_get_contents( $this->dir . '/lingocanvas-ru_RU.mo' ) );

		$this->assertSame( 'Сохранить', $mo['Save'] );
		$this->assertSame( 'Сохранить (меню)', $mo["menu\x04Save"] );
		$this->assertSame( "%d файл\0%d файла\0%d файлов", $mo["%d file\0%d files"] );
		$this->assertSame( "Скажи \"привет\"\n", $mo["Say \"hi\"\n"] );
		$this->assertArrayNotHasKey( 'Fuzzy', $mo );
		$this->assertArrayNotHasKey( 'Empty', $mo );
		$this->assertArrayNotHasKey( 'Old', $mo );
		$this->assertStringContainsString( 'nplurals=3', $mo[''] );
		$this->assertSame( '', array_key_first( $mo ) );

		$keys   = array_keys( $mo );
		$sorted = $keys;
		usort( $sorted, 'strcmp' );
		$this->assertSame( $sorted, $keys );
	}

	public function test_l10n_php_returns_expected_array(): void {
		lingocanvas_i18n_process( $this->writePo( $this->fixture() ), false );
		$data = include $this->dir . '/lingocanvas-ru_RU.l10n.php';

		$this->assertIsArray( $data );
		$this->assertSame( 'ru_RU', $data['language'] );
		$this->assertSame( 'lingocanvas', $data['domain'] );
		$this->assertStringStartsWith( 'nplurals=3;', $data['plural-forms'] );
		$this->assertSame( 'LingoCanvas', $data['project-id-version'] );
		$this->assertSame(
			array(
				"%d file\0%d files" => "%d файл\0%d файла\0%d файлов",
				'Save'              => 'Сохранить',
				"Say \"hi\"\n"      => "Скажи \"привет\"\n",
				"menu\x04Save"      => 'Сохранить (меню)',
			),
			$data['messages']
		);
	}

	public function test_placeholder_mismatch_is_detected(): void {
		$po = <<<'PO'
msgid ""
msgstr ""
"Language: ru_RU\n"

msgid "Hello %s"
msgstr "Привет"

msgid "Count %1$d of %2$d"
msgstr "Счёт %1$d из %2$d"
PO;
		$r = lingocanvas_i18n_process( $this->writePo( $po ), false );
		$this->assertCount( 1, $r['errors'] );
		$this->assertStringContainsString( 'Hello %s', $r['errors'][0] );
	}

	public function test_extra_unnumbered_placeholder_is_detected(): void {
		$po = <<<'PO'
msgid ""
msgstr ""
"Language: ru_RU\n"

msgid "Hello %s"
msgstr "Привет %s %s"
PO;
		$r  = lingocanvas_i18n_process( $this->writePo( $po ), false );
		$this->assertCount( 1, $r['errors'] );
		$this->assertStringContainsString( 'Hello %s', $r['errors'][0] );
	}

	public function test_reordered_numbered_placeholders_are_allowed(): void {
		$po = <<<'PO'
msgid ""
msgstr ""
"Language: ru_RU\n"

msgid "Count %1$d of %2$s"
msgstr "Из %2$s счёт %1$d"
PO;
		$r  = lingocanvas_i18n_process( $this->writePo( $po ), false );
		$this->assertSame( array(), $r['errors'] );
	}

	public function test_numbered_placeholder_type_change_is_detected(): void {
		$po = <<<'PO'
msgid ""
msgstr ""
"Language: ru_RU\n"

msgid "Count %1$d of %2$s"
msgstr "Из %2$d счёт %1$d"
PO;
		$r  = lingocanvas_i18n_process( $this->writePo( $po ), false );
		$this->assertCount( 1, $r['errors'] );
	}

	public function test_conflicting_duplicates_are_an_error(): void {
		$po = <<<'PO'
msgid "A"
msgstr "Б"

msgid "A"
msgstr "В"
PO;
		$r = lingocanvas_i18n_process( $this->writePo( $po ), false );
		$this->assertCount( 1, $r['errors'] );
	}

	public function test_pot_generation(): void {
		$r = lingocanvas_i18n_process( $this->writePo( $this->fixture() ), true );
		$this->assertSame( array( $this->dir . '/lingocanvas.pot' ), $r['written'] );
		$pot    = (string) file_get_contents( $this->dir . '/lingocanvas.pot' );
		$parsed = lingocanvas_i18n_parse_po( $pot );

		$this->assertSame( 'LingoCanvas', $parsed['header']['Project-Id-Version'] );
		$this->assertSame( 'lingocanvas', $parsed['header']['X-Domain'] );
		$this->assertStringContainsString( '#: src/a.php:1', $pot );
		$this->assertStringContainsString( 'msgctxt "menu"', $pot );
		$this->assertStringContainsString( 'msgstr[2] ""', $pot );
		$this->assertStringNotContainsString( 'Сохранить', $pot );
	}
}
