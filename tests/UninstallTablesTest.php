<?php
/**
 * Список таблиц в uninstall.php не должен расходиться со Schema::TABLES.
 *
 * uninstall.php не объявляет класс Schema (иначе при удалении обоих плагинов
 * за один запрос прежний uninstall.php упал бы на повторном объявлении класса),
 * а удаляет таблицы по собственному списку.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests;

use PHPUnit\Framework\TestCase;
use WpMlp\Storage\Schema;

final class UninstallTablesTest extends TestCase {

	public function testInlineTableListMatchesSchema(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		$this->assertIsString( $source );
		$this->assertSame( 1, preg_match( '/foreach \( array\( ([^)]*) \) as \$name \)/', $source, $m ), 'inline table list not found' );

		preg_match_all( "/'([a-z_]+)'/", $m[1], $names );

		$schema = ( new \ReflectionClassConstant( Schema::class, 'TABLES' ) )->getValue();

		$this->assertSame( $schema, $names[1] );
	}

	public function testUninstallDoesNotDeclareSchemaClassOrLegacyNamedFunctions(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		$this->assertStringNotContainsString( "require_once __DIR__ . '/src/Storage/Schema.php'", $source );
		$this->assertDoesNotMatchRegularExpression( '/function\s+wp_mlp_uninstall/', $source );
	}
}
