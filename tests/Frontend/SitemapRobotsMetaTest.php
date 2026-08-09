<?php
/**
 * Тесты решения «noindex ли запись» по meta трёх SEO-плагинов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\SitemapRobotsMeta;

#[CoversClass( SitemapRobotsMeta::class )]
final class SitemapRobotsMetaTest extends TestCase {

	public function testNoMetaAtAllIsNotNoindex(): void {
		$this->assertFalse( SitemapRobotsMeta::isNoindex( '', array(), '' ) );
	}

	public function testYoastExplicitNoindexIsDetected(): void {
		$this->assertTrue( SitemapRobotsMeta::isNoindex( '1', array(), '' ) );
	}

	/**
	 * `'2'` — Yoast «Index» выбран явно (переопределяет умолчание сайта в
	 * другую сторону) — это не noindex.
	 */
	public function testYoastExplicitIndexIsNotNoindex(): void {
		$this->assertFalse( SitemapRobotsMeta::isNoindex( '2', array(), '' ) );
	}

	public function testRankMathNoindexInChecklistIsDetected(): void {
		$this->assertTrue( SitemapRobotsMeta::isNoindex( '', array( 'noindex', 'nofollow' ), '' ) );
	}

	public function testRankMathOtherChecklistValuesAreNotNoindex(): void {
		$this->assertFalse( SitemapRobotsMeta::isNoindex( '', array( 'noarchive', 'nosnippet' ), '' ) );
	}

	public function testRankMathEmptyChecklistIsNotNoindex(): void {
		$this->assertFalse( SitemapRobotsMeta::isNoindex( '', array(), '' ) );
	}

	public function testSeoPressYesIsDetected(): void {
		$this->assertTrue( SitemapRobotsMeta::isNoindex( '', array(), 'yes' ) );
	}

	public function testSeoPressAnythingElseIsNotNoindex(): void {
		$this->assertFalse( SitemapRobotsMeta::isNoindex( '', array(), 'no' ) );
	}

	/**
	 * Пункт 1 жалобы (доработка sitemap): любой из трёх плагинов один
	 * достаточен — не нужно, чтобы все три сразу подтверждали noindex.
	 */
	public function testAnySinglePluginSignalIsEnough(): void {
		$this->assertTrue( SitemapRobotsMeta::isNoindex( '1', array(), '' ) );
		$this->assertTrue( SitemapRobotsMeta::isNoindex( '', array( 'noindex' ), '' ) );
		$this->assertTrue( SitemapRobotsMeta::isNoindex( '', array(), 'yes' ) );
	}

	/**
	 * Значения meta, которых WordPress вообще не хранит (get_post_meta()
	 * без единственного значения возвращает false, а не пустую строку),
	 * не должны давать фатальную ошибку и не считаются noindex.
	 */
	public function testMissingMetaValuesAreHandledGracefully(): void {
		$this->assertFalse( SitemapRobotsMeta::isNoindex( false, false, false ) );
	}
}
