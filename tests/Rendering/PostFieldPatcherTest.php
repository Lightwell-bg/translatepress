<?php
/**
 * Тесты подстановки переводов в сырое поле записи.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\PostContentExtractor;
use WpMlp\Rendering\PostFieldPatcher;

#[CoversClass( PostFieldPatcher::class )]
final class PostFieldPatcherTest extends TestCase {

	private function extractor(): PostContentExtractor {
		return new PostContentExtractor( new Extractor() );
	}

	public function testAppliesTranslationsWithoutTouchingSurroundingMarkup(): void {
		$raw = '<div class="highlight" style="color:red" data-x="1"><p>Первый</p><p>Второй</p></div>';

		$result = $this->extractor()->extract( $this->post( '', '', $raw ) );

		$byText = array( 'Первый' => 'First', 'Второй' => 'Second' );

		$translations = array();

		foreach ( $result->segments as $postSegment ) {
			$translations[ $postSegment->segment->uniqHash ] = $byText[ $postSegment->segment->text ];
		}

		$patched = PostFieldPatcher::apply( $raw, $result->segments, $translations );

		$this->assertSame(
			'<div class="highlight" style="color:red" data-x="1"><p>First</p><p>Second</p></div>',
			$patched
		);
	}

	public function testUntranslatedSegmentsAreLeftAsIs(): void {
		$raw    = '<p>Первый</p><p>Второй</p>';
		$result = $this->extractor()->extract( $this->post( '', '', $raw ) );

		// Переводим только "Первый" — "Второй" остаётся как в исходнике,
		// потому что для его хеша в карте переводов ничего нет.
		$firstHash = $result->segments[0]->segment->text === 'Первый'
			? $result->segments[0]->segment->uniqHash
			: $result->segments[1]->segment->uniqHash;

		$patched = PostFieldPatcher::apply( $raw, $result->segments, array( $firstHash => 'First' ) );

		$this->assertSame( '<p>First</p><p>Второй</p>', $patched );
	}

	public function testAttributeTranslationIsEscapedForAttributeContext(): void {
		$raw    = '<img src="a.png" alt="Alt & more">';
		$result = $this->extractor()->extract( $this->post( '', '', $raw ) );

		$hash = $result->segments[0]->segment->uniqHash;

		$patched = PostFieldPatcher::apply( $raw, $result->segments, array( $hash => 'Translated & safe "value"' ) );

		$this->assertSame( '<img src="a.png" alt="Translated &amp; safe &quot;value&quot;">', $patched );
	}

	/**
	 * Перевод, попавший в текстовый узел, экранируется только по
	 * & — кавычки в обычном тексте (не в атрибуте) экранировать не нужно.
	 */
	public function testTextTranslationEscapesOnlyAmpersandLtGt(): void {
		$raw    = '<p>Оригинал</p>';
		$result = $this->extractor()->extract( $this->post( '', '', $raw ) );

		$hash    = $result->segments[0]->segment->uniqHash;
		$patched = PostFieldPatcher::apply( $raw, $result->segments, array( $hash => 'Tom & "Jerry"' ) );

		$this->assertSame( '<p>Tom &amp; "Jerry"</p>', $patched );
	}

	/**
	 * Шорткод не имеет собственного сегмента вовсе (Text::isTranslatable()
	 * отбрасывает голый шорткод) — патчер его просто не видит и не трогает.
	 */
	public function testStandaloneShortcodeHasNoSegmentAndSurvivesUntouched(): void {
		$raw    = '<p>[gallery ids="1,2,3"]</p>';
		$result = $this->extractor()->extract( $this->post( '', '', $raw ) );

		$this->assertSame( array(), $result->segments );

		$patched = PostFieldPatcher::apply( $raw, $result->segments, array() );

		$this->assertSame( $raw, $patched );
	}

	/**
	 * @param string $title   Заголовок.
	 * @param string $excerpt Анонс.
	 * @param string $content `post_content`.
	 */
	private function post( string $title, string $excerpt, string $content ): object {
		$post               = new \stdClass();
		$post->post_title   = $title;
		$post->post_excerpt = $excerpt;
		$post->post_content = $content;

		return $post;
	}
}
