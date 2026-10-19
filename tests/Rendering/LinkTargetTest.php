<?php
/**
 * Тесты отбора адресов ссылок для словаря.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rendering\Extractor;
use LingoCanvas\Rendering\HtmlDocument;
use LingoCanvas\Rendering\LinkTarget;
use LingoCanvas\Rendering\Segment;

#[CoversClass( LinkTarget::class )]
final class LinkTargetTest extends TestCase {

	#[DataProvider( 'translatable' )]
	public function testAcceptsRealPageAddresses( string $href ): void {
		$this->assertTrue( LinkTarget::isTranslatable( $href ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function translatable(): array {
		return array(
			array( 'https://centerai.eu/' ),
			array( 'http://example.com/page/' ),
			array( '/kontakty/' ),
			array( '/blog/bg/statya/' ),
			array( '//cdn.example.com/page/' ),
			array( 'statya/' ),
			array( '/search/?q=x&page=2' ),
			// Двоеточие внутри пути — не схема.
			array( '/2026/08:12/report/' ),
		);
	}

	#[DataProvider( 'notTranslatable' )]
	public function testRejectsWhatIsNotAPage( string $href ): void {
		$this->assertFalse( LinkTarget::isTranslatable( $href ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function notTranslatable(): array {
		return array(
			array( '' ),
			array( '   ' ),
			// Якорь внутри этой же страницы — она уже переведена целиком.
			array( '#comments' ),
			array( '#' ),
			array( 'mailto:info@centerai.eu' ),
			array( 'MAILTO:info@centerai.eu' ),
			array( 'tel:+359888123456' ),
			array( 'javascript:void(0)' ),
			array( 'data:text/plain;base64,AAAA' ),
			array( 'sms:+359888' ),
			// Трекинговые простыни руками никто не переводит.
			array( 'https://example.com/?x=' . str_repeat( 'a', 2100 ) ),
		);
	}

	/**
	 * С живого сайта: ссылка «Выйти»/«Редактировать» в форме комментариев
	 * видна только вошедшему администратору и ведёт в админку. Языковой
	 * версии у административного адреса не бывает в принципе, а строка,
	 * которую видит только сам владелец сайта, — чистый шум в словаре.
	 */
	#[DataProvider( 'adminUrls' )]
	public function testRejectsWordPressAdminAddresses( string $href ): void {
		$this->assertFalse( LinkTarget::isTranslatable( $href ) );
	}

	/**
	 * @return list<array{string}>
	 */
	public static function adminUrls(): array {
		return array(
			array( 'https://centerai.eu/blog/wp-admin/post.php?post=66&action=edit' ),
			array( 'https://centerai.eu/blog/wp-login.php?action=logout&redirect_to=https%3A%2F%2Fx' ),
			array( 'https://centerai.eu/blog/wp-admin/comment.php?action=editcomment&c=2' ),
			array( '/wp-admin/' ),
			// Без хвостового слеша: адрес всё равно указывает на каталог.
			array( '/wp-admin' ),
		);
	}

	/**
	 * Путь, который лишь СОДЕРЖИТ «wp-admin» как часть чего-то другого, не
	 * должен зацепиться за проверку — вырезается ровно сегмент пути.
	 */
	public function testDoesNotRejectPathsThatMerelyContainTheWord(): void {
		$this->assertTrue( LinkTarget::isTranslatable( '/o-nas/wp-administrirovanie/' ) );
	}

	/**
	 * Адрес только обрезается по краям. Схлопывать внутри нельзя: любой
	 * изменённый символ ведёт уже на другую страницу.
	 */
	public function testNormalizeOnlyTrimsEdges(): void {
		$this->assertSame( '/a/b/', LinkTarget::normalize( "  /a/b/\n" ) );
		$this->assertSame( '/a%20b/', LinkTarget::normalize( '/a%20b/' ) );
	}

	/**
	 * @return list<array{string, string}>
	 */
	public static function editorArgs(): array {
		return array(
			'all three' => array(
				'https://centerai.eu/blog/en/page/2/?lingocanvas_editor=1&lingocanvas_nonce=f5b3290520&lingocanvas_channel=ue1lpPg628Wr0bB9xo1UsLFQ1a4tdTMO',
				'https://centerai.eu/blog/en/page/2/',
			),
			'only editor args, no dangling ?' => array( '/page/2/?lingocanvas_editor=1&lingocanvas_nonce=x', '/page/2/' ),
			'other args kept' => array( '/s/?q=a&lingocanvas_editor=1&page=2&lingocanvas_nonce=x', '/s/?q=a&page=2' ),
			'fragment kept' => array( '/p/?lingocanvas_editor=1#comments', '/p/#comments' ),
			'fragment and other args' => array( '/p/?a=1&lingocanvas_channel=zz#top', '/p/?a=1#top' ),
			'relative url' => array( 'page/2/?lingocanvas_nonce=abc', 'page/2/' ),
			'untouched without editor args' => array( '/p/?q=a%20b&x=', '/p/?q=a%20b&x=' ),
			'similar name is not stripped' => array( '/p/?lingocanvas_editor_x=1', '/p/?lingocanvas_editor_x=1' ),
			'question mark inside fragment' => array( '/p/#a?lingocanvas_editor=1', '/p/#a?lingocanvas_editor=1' ),
		);
	}

	#[DataProvider( 'editorArgs' )]
	public function testNormalizeStripsEditorPreviewArgs( string $href, string $expected ): void {
		$this->assertSame( $expected, LinkTarget::normalize( $href ) );
	}

	/**
	 * Ссылка, от которой после очистки ничего не осталось, адресом страницы
	 * не является и в словарь не попадает.
	 */
	public function testHrefThatBecomesEmptyIsNotCollected(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body><a href="?lingocanvas_editor=1&lingocanvas_nonce=x">Наверх</a>'
			. '<a href="?lingocanvas_editor=1#top">Якорь</a></body></html>'
		);

		foreach ( $segments as $segment ) {
			$this->assertNotSame( 'href', $segment->attribute );
		}
	}

	/**
	 * Ссылка пагинации в предпросмотре: в словарь идёт адрес без параметров
	 * редактора, а непереведённая ссылка в DOM остаётся прежней — иначе
	 * навигация внутри предпросмотра потеряла бы режим редактора.
	 */
	public function testPreviewPaginationHrefIsCollectedWithoutEditorArgs(): void {
		$html     = '<!DOCTYPE html><html><body><a href="https://centerai.eu/blog/en/page/2/?lingocanvas_editor=1&amp;lingocanvas_nonce=f5b3290520&amp;lingocanvas_channel=ue1lpPg628Wr0bB9xo1UsLFQ1a4tdTMO">2</a></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$hrefs    = array_values(
			array_filter( $segments, static fn( Segment $s ): bool => 'href' === $s->attribute )
		);

		$this->assertCount( 1, $hrefs );
		$this->assertSame( 'https://centerai.eu/blog/en/page/2/', $hrefs[0]->text );
		$this->assertStringContainsString( 'lingocanvas_editor=1', $document->html() );
	}

	/**
	 * Ровно случай с живого сайта: ссылка занимает абзац целиком, поэтому
	 * блоком не становится, и адрес нужно достать отдельной строкой —
	 * иначе перевести можно только надпись, а вёл бы он всё равно туда же.
	 */
	public function testAnchorHrefBecomesItsOwnSegment(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body><p><a href="https://centerai.eu/">Обсудить проект</a></p></body></html>'
		);

		$hrefs = array_values(
			array_filter(
				$segments,
				static fn( Segment $s ): bool => Segment::KIND_ATTRIBUTE === $s->kind && 'href' === $s->attribute
			)
		);

		$this->assertCount( 1, $hrefs );
		$this->assertSame( 'https://centerai.eu/', $hrefs[0]->text );
	}

	/**
	 * С живого сайта: строки словаря на bg-вкладке содержали адреса вида
	 * `.../blog/en/...` — это оказались собственные ссылки переключателя
	 * языков, а не что-то, что владелец сайта написал руками. Их адрес
	 * пересчитывается заново для каждой страницы через
	 * `UrlConverter::localize()`, переводить (то есть переопределять)
	 * его как обычную ссылку бессмысленно, а хранить — чистый шум:
	 * на многоязычном сайте таких ссылок по три-четыре на страницу.
	 */
	public function testLanguageSwitcherLinksAreNotCollected(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body>'
			. '<a class="mlp-language-item" href="https://centerai.eu/blog/en/x/">EN</a>'
			. '</body></html>'
		);

		foreach ( $segments as $segment ) {
			$this->assertNotSame( 'href', $segment->attribute );
		}
	}

	/**
	 * А обычная ссылка меню темы, даже если по случайности ведёт на
	 * другой язык или за пределы установки, собирается как всегда:
	 * пометку переключателя ставит только сам переключатель.
	 */
	public function testOrdinaryLinkWithoutSwitcherClassIsStillCollected(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body>'
			. '<a class="ct-menu-link" href="https://centerai.eu/ru/">Начало</a>'
			. '</body></html>'
		);

		$hrefs = array_values(
			array_filter(
				$segments,
				static fn( Segment $s ): bool => Segment::KIND_ATTRIBUTE === $s->kind && 'href' === $s->attribute
			)
		);

		$this->assertCount( 1, $hrefs );
		$this->assertSame( 'https://centerai.eu/ru/', $hrefs[0]->text );
	}

	public function testTechnicalHrefsAreNotCollected(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body>'
			. '<a href="#top">Наверх</a><a href="mailto:a@b.c">Почта</a>'
			. '</body></html>'
		);

		foreach ( $segments as $segment ) {
			$this->assertNotSame( 'href', $segment->attribute );
		}
	}

	/**
	 * Внутри translation block адрес отдельной строкой НЕ заводится: там он
	 * часть переведённой разметки блока и правится полем в редакторе.
	 * Иначе один и тот же адрес правился бы в двух местах сразу.
	 */
	public function testHrefInsideTranslationBlockIsNotCollectedSeparately(): void {
		$html     = '<!DOCTYPE html><html><body><p>Читайте <a href="/blog/x/">наш</a> блог</p></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$paragraph = $document->document()->getElementsByTagName( 'p' )->item( 0 );
		$blockHash = \LingoCanvas\Support\Hash::of(
			\LingoCanvas\Rendering\BlockSanitizer::sanitize( $document->innerHtml( $paragraph ) )
		);

		$segments = ( new Extractor() )->extract( $document, 'ru', array( $blockHash => true ) );

		foreach ( $segments as $segment ) {
			$this->assertNotSame( 'href', $segment->attribute );
		}
	}

	/**
	 * @return list<Segment>
	 */
	private function segments( string $html ): array {
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		return ( new Extractor() )->extract( $document, 'ru' );
	}
}
