<?php
/**
 * Тесты вкладок «Перевода строк» и статуса gettext-строки.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\InterfaceStringsScreen;
use WpMlp\Admin\StringTranslationPage;
use WpMlp\I18n\DomainLabel;
use WpMlp\Storage\TranslationStatus;

#[CoversClass( StringTranslationPage::class )]
#[CoversClass( InterfaceStringsScreen::class )]
#[CoversClass( DomainLabel::class )]
final class InterfaceScopeTest extends TestCase {

	public function testTabAllowlistAcceptsKnownTabs(): void {
		$this->assertSame( StringTranslationPage::TAB_CONTENT, StringTranslationPage::parseTab( 'content' ) );
		$this->assertSame( StringTranslationPage::TAB_SEO, StringTranslationPage::parseTab( 'seo' ) );
		$this->assertSame( StringTranslationPage::TAB_INTERFACE, StringTranslationPage::parseTab( 'interface' ) );
	}

	/**
	 * Значение приходит из адресной строки, поэтому всё непонятное
	 * схлопывается в «Контент», а не идёт дальше в выборку.
	 */
	public function testTabAllowlistRejectsAnythingElse(): void {
		$this->assertSame( StringTranslationPage::TAB_CONTENT, StringTranslationPage::parseTab( '' ) );
		$this->assertSame( StringTranslationPage::TAB_CONTENT, StringTranslationPage::parseTab( 'INTERFACE' ) );
		$this->assertSame( StringTranslationPage::TAB_CONTENT, StringTranslationPage::parseTab( 'gettext' ) );
		$this->assertSame( StringTranslationPage::TAB_CONTENT, StringTranslationPage::parseTab( "interface'; DROP TABLE wp_mlp_sources" ) );
	}

	/**
	 * Есть наше переопределение — показываем его собственный статус.
	 */
	public function testOverrideKeepsItsStoredStatus(): void {
		$this->assertSame(
			TranslationStatus::MACHINE,
			InterfaceStringsScreen::status( 'Наш перевод', 'Из пакета', TranslationStatus::MACHINE )
		);
	}

	/**
	 * Переопределение есть, а статус в базе повреждён или пуст — считаем
	 * его подтверждённым: перевод ввёл человек руками, других способов
	 * попасть в эту таблицу у gettext-строки нет.
	 */
	public function testOverrideWithBrokenStatusFallsBackToApproved(): void {
		$this->assertSame(
			TranslationStatus::APPROVED,
			InterfaceStringsScreen::status( 'Наш перевод', '', 'нет такого статуса' )
		);
	}

	/**
	 * Своего перевода нет, но языковой пакет строку закрывает — это и есть
	 * вычисляемый статус `locale_file` (ТЗ 4.5, код 4).
	 */
	public function testPackTranslationGivesTheLocaleFileStatus(): void {
		$this->assertSame(
			TranslationStatus::LOCALE_FILE,
			InterfaceStringsScreen::status( '', 'Ответить', '' )
		);
	}

	public function testNothingAnywhereIsMissing(): void {
		$this->assertSame(
			TranslationStatus::MISSING,
			InterfaceStringsScreen::status( '', '', '' )
		);
	}

	/**
	 * `locale_file` не должен попадать в allowlist записи: строки,
	 * переведённой пакетом, в нашей базе нет, и сохранять этот статус
	 * некуда (см. Storage\TranslationStatus::LOCALE_FILE).
	 */
	public function testLocaleFileIsNotAWritableStatus(): void {
		$this->assertFalse( TranslationStatus::isValid( TranslationStatus::LOCALE_FILE ) );
		$this->assertNotContains( TranslationStatus::LOCALE_FILE, TranslationStatus::all() );
	}

	public function testCoreDomainGetsAHumanName(): void {
		$this->assertSame( 'WordPress (ядро)', DomainLabel::format( 'default' ) );
		// Пустой домен WordPress трактует как `default`.
		$this->assertSame( 'WordPress (ядро)', DomainLabel::format( '' ) );
	}

	public function testThemeAndPluginDomainsAreNamed(): void {
		$themes  = array( 'twentytwentyfour' => 'Twenty Twenty-Four' );
		$plugins = array( 'easy-digital-downloads' => 'Easy Digital Downloads' );

		$this->assertSame(
			'Тема: Twenty Twenty-Four',
			DomainLabel::format( 'twentytwentyfour', $themes, $plugins )
		);
		$this->assertSame(
			'Плагин: Easy Digital Downloads',
			DomainLabel::format( 'easy-digital-downloads', $themes, $plugins )
		);
	}

	/**
	 * Плагин отключили или удалили, а его строки остались в словаре:
	 * показываем сам домен — это полезнее пустой ячейки и сразу объясняет,
	 * почему строки больше не видно на сайте.
	 */
	public function testUnknownDomainFallsBackToItsOwnName(): void {
		$this->assertSame( 'some-old-plugin', DomainLabel::format( 'some-old-plugin' ) );
	}
}
