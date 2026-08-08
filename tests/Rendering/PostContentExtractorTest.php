<?php
/**
 * Тесты извлечения переводимых сегментов записи целиком.
 *
 * Здесь же — интеграционные тесты «Перевести весь материал с ИИ» для двух
 * реальных сценариев хранения `post_content`: классический редактор (голый
 * текст вперемешку с ручным HTML) и Gutenberg (валидный HTML с
 * комментариями-маркерами блоков). Оба теста подтверждают одной проверкой
 * ровно то, что требовалось: одной операцией переводится ВЕСЬ видимый
 * текст записи, а разметка, Gutenberg-блоки, ссылки, изображения, классы
 * и инлайн-стили не меняются ни на символ.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\PostContentExtractor;
use WpMlp\Rendering\PostExtractionResult;
use WpMlp\Rendering\PostSegment;
use WpMlp\Rendering\Segment;

#[CoversClass( PostContentExtractor::class )]
#[CoversClass( PostExtractionResult::class )]
#[CoversClass( PostSegment::class )]
final class PostContentExtractorTest extends TestCase {

	private function extractor(): PostContentExtractor {
		return new PostContentExtractor( new Extractor() );
	}

	/**
	 * @param string $title   Заголовок.
	 * @param string $excerpt Анонс.
	 * @param string $content `post_content`.
	 */
	private function post( string $title = '', string $excerpt = '', string $content = '' ): object {
		$post                = new \stdClass();
		$post->post_title    = $title;
		$post->post_excerpt  = $excerpt;
		$post->post_content  = $content;

		return $post;
	}

	/**
	 * Применяет карту переводов ко всем сегментам одного поля.
	 *
	 * @param list<PostSegment>    $segments    Все сегменты записи.
	 * @param string                $field      PostSegment::FIELD_*.
	 * @param array<string, string> $translations Исходный текст => перевод.
	 */
	private function translateField( array $segments, string $field, array $translations ): void {
		foreach ( $segments as $postSegment ) {
			if ( $field !== $postSegment->field ) {
				continue;
			}

			$translation = $translations[ $postSegment->segment->text ] ?? null;

			$this->assertNotNull( $translation, "Нет перевода в карте для: \"{$postSegment->segment->text}\"" );

			$postSegment->segment->apply( (string) $translation );
		}
	}

	public function testTitleAndExcerptSegmentsAreTaggedWithTheirField(): void {
		$result = $this->extractor()->extract( $this->post( 'Заголовок', 'Анонс записи' ) );

		$fields = array_map( static fn( PostSegment $s ): string => $s->field, $result->segments );

		$this->assertContains( PostSegment::FIELD_TITLE, $fields );
		$this->assertContains( PostSegment::FIELD_EXCERPT, $fields );
	}

	public function testEmptyExcerptProducesNoExcerptSegment(): void {
		$result = $this->extractor()->extract( $this->post( 'Заголовок', '' ) );

		foreach ( $result->segments as $segment ) {
			$this->assertNotSame( PostSegment::FIELD_EXCERPT, $segment->field );
		}
	}

	public function testCompletelyEmptyPostYieldsNoSegmentsAndNoDocuments(): void {
		$result = $this->extractor()->extract( $this->post() );

		$this->assertSame( array(), $result->segments );
		$this->assertNull( $result->titleDocument );
		$this->assertNull( $result->excerptDocument );
		$this->assertNull( $result->contentDocument );
	}

	/**
	 * Голый текст классического редактора (без блоков Gutenberg) должен
	 * пройти через wpautop() — ровно как это происходит на обычной
	 * странице через фильтр the_content, иначе абзацы разбирались бы не
	 * так, как их вообще когда-либо видит посетитель.
	 */
	public function testClassicContentIsAutoParagraphed(): void {
		$result = $this->extractor()->extract( $this->post( '', '', "Первый абзац.\n\nВторой абзац." ) );

		$html = PostExtractionResult::bodyHtml( $result->contentDocument );

		$this->assertStringContainsString( '<p>Первый абзац.</p>', $html );
		$this->assertStringContainsString( '<p>Второй абзац.</p>', $html );
	}

	/**
	 * А вот Gutenberg-содержимое трогать wpautop() нельзя вовсе: пустые
	 * строки между блоками — обычное дело, и авто-параграфер вставил бы
	 * лишние `<p>` вокруг комментариев `<!-- wp:... -->`.
	 */
	public function testGutenbergContentSkipsAutoParagraphing(): void {
		$content = "<!-- wp:paragraph -->\n<p>Текст.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Ещё текст.</p>\n<!-- /wp:paragraph -->";

		$result = $this->extractor()->extract( $this->post( '', '', $content ) );
		$html   = PostExtractionResult::bodyHtml( $result->contentDocument );

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $html );
		$this->assertStringContainsString( '<!-- /wp:paragraph -->', $html );
		// wpautop дала бы <p><p>Текст.</p></p> или лишний абзац на пустой строке.
		$this->assertStringNotContainsString( '<p><p>', $html );
	}

	/**
	 * Уже заведённый translation block (абзац, разорванный инлайновыми
	 * тегами, который прежде явно перевели целиком) должен остаться
	 * блоком и здесь, а не рассыпаться обратно на куски.
	 */
	public function testRespectsAlreadyRegisteredTranslationBlocks(): void {
		$content = '<p>Читайте <b>наш</b> блог</p>';

		// Хеш translation block считается от СОДЕРЖИМОГО элемента, без его
		// собственных тегов <p></p> — ровно как это делает Extractor::blockSegment().
		$hash = \WpMlp\Support\Hash::of( \WpMlp\Rendering\BlockSanitizer::sanitize( 'Читайте <b>наш</b> блог' ) );

		$result = $this->extractor()->extract( $this->post( '', '', $content ), 'ru', array( $hash => true ) );

		$kinds = array_map( static fn( PostSegment $s ): string => $s->segment->kind, $result->segments );

		$this->assertContains( Segment::KIND_HTML_BLOCK, $kinds );
	}

	/**
	 * Сценарий из требования: классический редактор — голый текст вперемешку
	 * с ручным HTML (заголовок, абзац со ссылкой, список, таблица, цитата с
	 * классом и инлайн-стилем, шорткод вперемешку с текстом, изображение).
	 * Одной операцией переводится ВЕСЬ видимый текст; разметка, ссылки,
	 * изображение и класс/стиль не меняются.
	 */
	public function testClassicEditorPostTranslatesAllVisibleTextPreservingStructure(): void {
		$title   = 'Первый пост в классическом редакторе';
		$excerpt = 'Краткое описание записи для анонса';
		$content = <<<'HTML'
Первый абзац классического редактора без какой-либо разметки.

Нажмите [rsvp_button event="5"], чтобы записаться на мероприятие.

<h2>Подзаголовок раздела</h2>

<p>Абзац с <em>акцентом</em> и <a href="https://example.com/more/">ссылкой на подробности</a> внутри одного предложения.</p>

<ul>
<li>Первый пункт списка</li>
<li>Второй пункт списка</li>
</ul>

<table>
<tr><th>Колонка</th><th>Значение</th></tr>
<tr><td>Имя</td><td>Иван</td></tr>
</table>

<blockquote class="highlight" style="color:red;">Цитата с классом и инлайн-стилем.</blockquote>

<p><img src="https://example.com/wp-content/uploads/photo.jpg" alt="Описание фото" width="600" height="400" class="aligncenter"></p>
HTML;

		$post   = $this->post( $title, $excerpt, $content );
		$result = $this->extractor()->extract( $post );

		$this->translateField(
			$result->segments,
			PostSegment::FIELD_TITLE,
			array( $title => 'First post in the classic editor' )
		);
		$this->translateField(
			$result->segments,
			PostSegment::FIELD_EXCERPT,
			array( $excerpt => 'A short summary of the post for the teaser' )
		);
		$this->translateField(
			$result->segments,
			PostSegment::FIELD_CONTENT,
			array(
				'Первый абзац классического редактора без какой-либо разметки.'
					=> 'The first paragraph of the classic editor without any markup.',
				'Нажмите [rsvp_button event="5"], чтобы записаться на мероприятие.'
					=> 'Click [rsvp_button event="5"] to sign up for the event.',
				'Подзаголовок раздела'          => 'Section subheading',
				'Абзац с'                       => 'A paragraph with',
				'акцентом'                      => 'emphasis',
				'и'                              => 'and',
				'ссылкой на подробности'        => 'a link to the details',
				'внутри одного предложения.'    => 'inside a single sentence.',
				'Первый пункт списка'           => 'First list item',
				'Второй пункт списка'           => 'Second list item',
				'Колонка'                        => 'Column',
				'Значение'                       => 'Value',
				'Имя'                            => 'Name',
				'Иван'                           => 'Ivan',
				'Описание фото'                  => 'Photo description',
				'Цитата с классом и инлайн-стилем.' => 'A quote with a class and inline style.',
			)
		);

		$titleHtml   = PostExtractionResult::bodyHtml( $result->titleDocument );
		$excerptHtml = PostExtractionResult::bodyHtml( $result->excerptDocument );
		$contentHtml = PostExtractionResult::bodyHtml( $result->contentDocument );

		// Заголовок и анонс переведены.
		$this->assertStringContainsString( 'First post in the classic editor', $titleHtml );
		$this->assertStringContainsString( 'A short summary of the post for the teaser', $excerptHtml );

		// Весь видимый текст содержимого переведён…
		foreach (
			array(
				'The first paragraph of the classic editor without any markup.',
				'Click',
				'to sign up for the event.',
				'Section subheading',
				'A paragraph with',
				'emphasis',
				'a link to the details',
				'inside a single sentence.',
				'First list item',
				'Second list item',
				'Column',
				'Value',
				'Name',
				'Ivan',
				'Photo description',
				'A quote with a class and inline style.',
			) as $expectedEnglish
		) {
			$this->assertStringContainsString( $expectedEnglish, $contentHtml, "Missing translation: \"$expectedEnglish\"" );
		}

		// …и русский исходник в содержимом не остался.
		foreach ( array( 'Абзац с', 'Первый пункт списка', 'Колонка', 'Цитата с классом' ) as $shouldBeGone ) {
			$this->assertStringNotContainsString( $shouldBeGone, $contentHtml, "Original Russian text leaked through: \"$shouldBeGone\"" );
		}

		// Разметка, шорткод, ссылка, изображение и класс/стиль — не тронуты.
		$this->assertStringContainsString( '<h2>', $contentHtml );
		$this->assertSame( 2, substr_count( $contentHtml, '<li>' ) );
		$this->assertSame( 2, substr_count( $contentHtml, '<td>' ) );
		$this->assertSame( 2, substr_count( $contentHtml, '<th>' ) );
		$this->assertStringContainsString( '[rsvp_button event="5"]', $contentHtml );
		$this->assertStringContainsString( 'href="https://example.com/more/"', $contentHtml );
		$this->assertStringContainsString( 'src="https://example.com/wp-content/uploads/photo.jpg"', $contentHtml );
		$this->assertStringContainsString( 'width="600"', $contentHtml );
		$this->assertStringContainsString( 'height="400"', $contentHtml );
		$this->assertStringContainsString( 'class="aligncenter"', $contentHtml );
		$this->assertStringContainsString( 'class="highlight"', $contentHtml );
		$this->assertStringContainsString( 'style="color:red', $contentHtml );
	}

	/**
	 * Тот же сценарий на Gutenberg-содержимом: комментарии-маркеры блоков
	 * (в том числе с JSON-атрибутами), классы и инлайн-стили блоков должны
	 * пережить перевод буквально — включая случай, когда шорткод стоит
	 * вперемешку с обычным текстом внутри блока-абзаца.
	 */
	public function testGutenbergPostTranslatesAllVisibleTextPreservingStructure(): void {
		$title   = 'Обзор нового продукта';
		$excerpt = 'Что нового в этом продукте';
		$content = <<<'HTML'
<!-- wp:heading -->
<h2 class="wp-block-heading">Первый заголовок блока</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Обычный абзац Gutenberg с <strong>выделением</strong> внутри.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Нажмите [rsvp_button event="5"], чтобы записаться.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
<!-- wp:list-item -->
<li>Первый пункт списка</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Второй пункт списка</li>
<!-- /wp:list-item -->
</ul>
<!-- /wp:list -->

<!-- wp:table -->
<figure class="wp-block-table"><table><tbody><tr><td>Имя</td><td>Иван</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:image {"id":42,"width":600,"height":400} -->
<figure class="wp-block-image size-large"><img src="https://example.com/wp-content/uploads/photo.jpg" alt="Описание фото" class="wp-image-42"/><figcaption class="wp-element-caption">Подпись к изображению</figcaption></figure>
<!-- /wp:image -->

<!-- wp:paragraph {"className":"highlight"} -->
<p class="highlight has-text-color has-red-color" style="color:red">Абзац с классом и инлайн-стилем.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><a href="https://example.com/more/" class="wp-block-button__link">Ссылка на подробности</a></p>
<!-- /wp:paragraph -->
HTML;

		$post   = $this->post( $title, $excerpt, $content );
		$result = $this->extractor()->extract( $post );

		$this->translateField(
			$result->segments,
			PostSegment::FIELD_TITLE,
			array( $title => 'Overview of the new product' )
		);
		$this->translateField(
			$result->segments,
			PostSegment::FIELD_EXCERPT,
			array( $excerpt => "What's new in this product" )
		);
		$this->translateField(
			$result->segments,
			PostSegment::FIELD_CONTENT,
			array(
				'Первый заголовок блока'                     => 'First block heading',
				'Обычный абзац Gutenberg с'                  => 'A regular Gutenberg paragraph with',
				'выделением'                                  => 'emphasis',
				'внутри.'                                     => 'inside it.',
				'Нажмите [rsvp_button event="5"], чтобы записаться.' => 'Click [rsvp_button event="5"] to sign up.',
				'Первый пункт списка'                        => 'First list item',
				'Второй пункт списка'                        => 'Second list item',
				'Имя'                                         => 'Name',
				'Иван'                                        => 'Ivan',
				'Описание фото'                               => 'Photo description',
				'Подпись к изображению'                       => 'Image caption',
				'Абзац с классом и инлайн-стилем.'            => 'A paragraph with a class and inline style.',
				'Ссылка на подробности'                       => 'A link to the details',
			)
		);

		$titleHtml   = PostExtractionResult::bodyHtml( $result->titleDocument );
		$excerptHtml = PostExtractionResult::bodyHtml( $result->excerptDocument );
		$contentHtml = PostExtractionResult::bodyHtml( $result->contentDocument );

		$this->assertStringContainsString( 'Overview of the new product', $titleHtml );
		$this->assertStringContainsString( "What's new in this product", $excerptHtml );

		foreach (
			array(
				'First block heading',
				'A regular Gutenberg paragraph with',
				'emphasis',
				'inside it.',
				'Click',
				'to sign up.',
				'First list item',
				'Second list item',
				'Name',
				'Ivan',
				'Photo description',
				'Image caption',
				'A paragraph with a class and inline style.',
				'A link to the details',
			) as $expectedEnglish
		) {
			$this->assertStringContainsString( $expectedEnglish, $contentHtml, "Missing translation: \"$expectedEnglish\"" );
		}

		foreach ( array( 'Первый заголовок блока', 'Первый пункт списка', 'Подпись к изображению' ) as $shouldBeGone ) {
			$this->assertStringNotContainsString( $shouldBeGone, $contentHtml, "Original Russian text leaked through: \"$shouldBeGone\"" );
		}

		// Комментарии Gutenberg — включая JSON-атрибуты — байт-в-байт, как были.
		foreach (
			array(
				'<!-- wp:heading -->',
				'<!-- /wp:heading -->',
				'<!-- wp:paragraph -->',
				'<!-- /wp:paragraph -->',
				'<!-- wp:list -->',
				'<!-- /wp:list -->',
				'<!-- wp:list-item -->',
				'<!-- /wp:list-item -->',
				'<!-- wp:table -->',
				'<!-- /wp:table -->',
				'<!-- wp:image {"id":42,"width":600,"height":400} -->',
				'<!-- /wp:image -->',
				'<!-- wp:paragraph {"className":"highlight"} -->',
			) as $comment
		) {
			$this->assertStringContainsString( $comment, $contentHtml, "Gutenberg comment lost or altered: \"$comment\"" );
		}

		// Классы, инлайн-стиль, шорткод, ссылка и изображение — не тронуты.
		$this->assertStringContainsString( 'class="wp-block-heading"', $contentHtml );
		$this->assertStringContainsString( 'class="wp-block-list"', $contentHtml );
		$this->assertStringContainsString( 'class="wp-block-table"', $contentHtml );
		$this->assertStringContainsString( 'class="wp-block-image size-large"', $contentHtml );
		$this->assertStringContainsString( 'class="wp-image-42"', $contentHtml );
		$this->assertStringContainsString( 'class="wp-element-caption"', $contentHtml );
		$this->assertStringContainsString( 'class="highlight has-text-color has-red-color"', $contentHtml );
		$this->assertStringContainsString( 'class="wp-block-button__link"', $contentHtml );
		$this->assertStringContainsString( 'style="color:red', $contentHtml );
		$this->assertStringContainsString( '[rsvp_button event="5"]', $contentHtml );
		$this->assertStringContainsString( 'href="https://example.com/more/"', $contentHtml );
		$this->assertStringContainsString( 'src="https://example.com/wp-content/uploads/photo.jpg"', $contentHtml );
	}
}
