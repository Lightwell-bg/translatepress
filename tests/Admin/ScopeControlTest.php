<?php
/**
 * Тесты разметки фильтра «Где встречается» (поиск записи вместо списка всех записей).
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
final class ScopeControlTest extends TestCase {

	public function testSelectHasExactlyThreeOptionsAndNoPerPostList(): void {
		$html = StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_ALL, 0, '' );

		$this->assertSame( 3, substr_count( $html, '<option ' ) );
		$this->assertStringNotContainsString( '<optgroup', $html );
		$this->assertStringContainsString( 'name="mlp_scope"', $html );
		$this->assertSame( 1, substr_count( $html, 'name="mlp_scope"' ) );
	}

	public function testSearchBoxIsHiddenUnlessObjectScope(): void {
		$html = StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_GLOBAL, 0, '' );

		$this->assertStringContainsString( 'id="mlp-scope-picker" hidden', $html );
		$this->assertStringContainsString( 'value="global" selected="selected"', $html );
	}

	public function testObjectScopePrefillsTitleAndSelectsObjectOption(): void {
		$html = StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_OBJECT, 42, 'Про "нас" <b>' );

		$this->assertStringContainsString( 'value="object:42" data-mlp-object="1" selected="selected"', $html );
		$this->assertStringNotContainsString( 'id="mlp-scope-picker" hidden', $html );
		$this->assertStringContainsString( 'id="mlp-scope-search"', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'Про', $html );
	}

	public function testObjectOptionWithoutSelectionParsesAsWholeSite(): void {
		$html = StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_ALL, 0, '' );

		$this->assertStringContainsString( 'value="object:" data-mlp-object="1"', $html );
		$this->assertSame(
			array( SourceRepository::SCOPE_ALL, 0 ),
			StringTranslationPage::parseScope( 'object:' )
		);
	}

	public function testOldBookmarkedFormatStillParses(): void {
		$this->assertSame(
			array( SourceRepository::SCOPE_OBJECT, 123 ),
			StringTranslationPage::parseScope( 'object:123' )
		);
	}
}
