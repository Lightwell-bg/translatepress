<?php
/**
 * Тесты выбора более поздней даты правки для карты сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\SitemapLastmod;

#[CoversClass( SitemapLastmod::class )]
final class SitemapLastmodTest extends TestCase {

	/**
	 * Перевода нет вовсе — используется дата правки самой записи, без
	 * попытки что-либо сравнивать.
	 */
	public function testNoTranslationFallsBackToPostDate(): void {
		$this->assertSame(
			'2026-08-01T10:00:00+00:00',
			SitemapLastmod::newerOf( '2026-08-01T10:00:00+00:00', '' )
		);
	}

	/**
	 * Пункт 3 жалобы (доработка sitemap): перевод обновился ПОЗЖЕ правки
	 * записи — например, запись создана две недели назад, а перевод на
	 * английский добавлен только сегодня. lastmod английской версии обязан
	 * отразить именно сегодняшнюю дату, а не дату записи.
	 */
	public function testNewerTranslationWins(): void {
		$this->assertSame(
			'2026-08-09T12:00:00+00:00',
			SitemapLastmod::newerOf( '2026-07-20T09:00:00+00:00', '2026-08-09 12:00:00' )
		);
	}

	/**
	 * Запись переиздана ПОСЛЕ последнего перевода — тогда правка самой
	 * записи новее, и побеждает она, а не устаревший перевод.
	 */
	public function testNewerPostDateWinsOverOlderTranslation(): void {
		$this->assertSame(
			'2026-08-09T12:00:00+00:00',
			SitemapLastmod::newerOf( '2026-08-09T12:00:00+00:00', '2026-07-20 09:00:00' )
		);
	}

	/**
	 * Равные даты — результат детерминирован (дата записи), без ложного
	 * «перевод новее» на границе сравнения.
	 */
	public function testEqualDatesKeepPostDate(): void {
		$iso = '2026-08-09T12:00:00+00:00';

		$this->assertSame( $iso, SitemapLastmod::newerOf( $iso, '2026-08-09 12:00:00' ) );
	}

	/**
	 * Нераспознаваемая дата перевода не должна ломать сравнение — исходная
	 * дата записи остаётся в силе, как если бы перевода не было.
	 */
	public function testUnparsableTranslationDateFallsBackToPostDate(): void {
		$this->assertSame(
			'2026-08-01T10:00:00+00:00',
			SitemapLastmod::newerOf( '2026-08-01T10:00:00+00:00', 'не дата' )
		);
	}
}
