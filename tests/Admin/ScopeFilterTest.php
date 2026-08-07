<?php
/**
 * Тесты разбора фильтра области поиска строк.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\StringTranslationPage;
use WpMlp\Storage\SourceRepository;

#[CoversClass( StringTranslationPage::class )]
final class ScopeFilterTest extends TestCase {

	public function testEmptyValueMeansWholeSite(): void {
		$this->assertSame(
			array( SourceRepository::SCOPE_ALL, 0 ),
			StringTranslationPage::parseScope( '' )
		);
	}

	public function testGlobalScope(): void {
		$this->assertSame(
			array( SourceRepository::SCOPE_GLOBAL, 0 ),
			StringTranslationPage::parseScope( 'global' )
		);
	}

	public function testObjectScopeCarriesThePostId(): void {
		$this->assertSame(
			array( SourceRepository::SCOPE_OBJECT, 42 ),
			StringTranslationPage::parseScope( 'object:42' )
		);
	}

	/**
	 * Значение приходит из адресной строки, поэтому всё непонятное должно
	 * схлопываться в безопасный режим «весь сайт», а не идти дальше в SQL.
	 *
	 * @param string $value Подозрительное значение параметра.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'untrusted' )]
	public function testUntrustedValuesFallBackToWholeSite( string $value ): void {
		$this->assertSame(
			array( SourceRepository::SCOPE_ALL, 0 ),
			StringTranslationPage::parseScope( $value )
		);
	}

	/**
	 * @return list<array{string}>
	 */
	public static function untrusted(): array {
		return array(
			array( 'object:' ),
			array( 'object:0' ),
			array( 'object:-5' ),
			array( 'object:abc' ),
			array( 'object:1 OR 1=1' ),
			array( 'GLOBAL' ),
			array( 'nonsense' ),
			array( '../../etc/passwd' ),
		);
	}
}
