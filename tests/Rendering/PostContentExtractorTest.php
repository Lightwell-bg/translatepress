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
use WpMlp\Rendering\PostFieldPatcher;
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
	 * Строит карту «uniq_hash => перевод» для сегментов одного поля — по
	 * карте «исходный текст => перевод», как её писал бы человек, глядя на
	 * список строк в админке.
	 *
	 * @param list<PostSegment>     $segments     Все сегменты записи.
	 * @param string                $field        PostSegment::FIELD_*.
	 * @param array<string, string> $translations Исходный текст => перевод.
	 * @return array<string, string> uniq_hash => перевод.
	 */
	private function translationsFor( array $segments, string $field, array $translations ): array {
		$byHash = array();

		foreach ( $segments as $postSegment ) {
			if ( $field !== $postSegment->field ) {
				continue;
			}

			$translation = $translations[ $postSegment->segment->text ] ?? null;

			$this->assertNotNull( $translation, "Нет перевода в карте для: \"{$postSegment->segment->text}\"" );

			$byHash[ $postSegment->segment->uniqHash ] = (string) $translation;
		}

		return $byHash;
	}

	/**
	 * Сегменты одного поля, в порядке появления в его сыром значении —
	 * ровно то, что ожидает PostFieldPatcher::apply().
	 *
	 * @param list<PostSegment> $segments Все сегменты записи.
	 * @param string            $field    PostSegment::FIELD_*.
	 * @return list<PostSegment>
	 */
	private function fieldSegments( array $segments, string $field ): array {
		return array_values( array_filter( $segments, static fn( PostSegment $s ): bool => $field === $s->field ) );
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
	 * классом и инлайн-стилем, шорткод вперемешку с текстом, самозакрывающееся
	 * изображение). Одной операцией переводится ВЕСЬ видимый текст.
	 *
	 * Перевод подставляется НЕ через разбор-сборку DOM (PostExtractionResult
	 * ей больше не пользуется в этом тесте), а точечно, в исходную строку
	 * `post_content` — PostFieldPatcher::apply() возвращает null, если хотя
	 * бы один сегмент не удалось однозначно найти, так что сам факт
	 * ненулевого результата уже доказывает точность разметки диапазонов.
	 * Дальше — посимвольная проверка: то, что не входило ни в один диапазон
	 * замены (самозакрывающий `/>`, атрибуты, шорткод, ссылка), обязано
	 * остаться в точности тем же самым, включая случаи, которые раньше
	 * ломала пересборка через DOM (`<img ... />` → `<img ...>`).
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

<p><img src="https://example.com/wp-content/uploads/photo.jpg" alt="Описание фото" width="600" height="400" class="aligncenter" /></p>
HTML;

		$post   = $this->post( $title, $excerpt, $content );
		$result = $this->extractor()->extract( $post );

		$titleTranslations = $this->translationsFor(
			$result->segments,
			PostSegment::FIELD_TITLE,
			array( $title => 'First post in the classic editor' )
		);
		$excerptTranslations = $this->translationsFor(
			$result->segments,
			PostSegment::FIELD_EXCERPT,
			array( $excerpt => 'A short summary of the post for the teaser' )
		);
		$contentTranslations = $this->translationsFor(
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

		$titlePatched   = PostFieldPatcher::apply( $title, $this->fieldSegments( $result->segments, PostSegment::FIELD_TITLE ), $titleTranslations );
		$excerptPatched = PostFieldPatcher::apply( $excerpt, $this->fieldSegments( $result->segments, PostSegment::FIELD_EXCERPT ), $excerptTranslations );
		$contentPatched = PostFieldPatcher::apply( $content, $this->fieldSegments( $result->segments, PostSegment::FIELD_CONTENT ), $contentTranslations );

		// Ни для одного поля патчер не сдался — каждый сегмент был найден
		// в исходной строке однозначно, диапазон за диапазоном.
		$this->assertNotNull( $titlePatched, 'Не удалось точно найти заголовок в исходной строке.' );
		$this->assertNotNull( $excerptPatched, 'Не удалось точно найти анонс в исходной строке.' );
		$this->assertNotNull( $contentPatched, 'Не удалось точно найти все сегменты содержимого в исходной строке.' );

		$this->assertSame( 'First post in the classic editor', $titlePatched );
		$this->assertSame( 'A short summary of the post for the teaser', $excerptPatched );

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
			$this->assertStringContainsString( $expectedEnglish, $contentPatched, "Missing translation: \"$expectedEnglish\"" );
		}

		// …и русский исходник в содержимом не остался.
		foreach ( array( 'Абзац с', 'Первый пункт списка', 'Колонка', 'Цитата с классом' ) as $shouldBeGone ) {
			$this->assertStringNotContainsString( $shouldBeGone, $contentPatched, "Original Russian text leaked through: \"$shouldBeGone\"" );
		}

		// Разметка целиком, символ в символ — не пересобрана DOM'ом, а
		// скопирована из исходника: самозакрывающий тег остаётся
		// самозакрывающимся (это то самое, что раньше ломала DOM-пересборка).
		$this->assertStringContainsString(
			'<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Photo description" width="600" height="400" class="aligncenter" />',
			$contentPatched
		);
		$this->assertStringContainsString( '<h2>Section subheading</h2>', $contentPatched );
		$this->assertStringContainsString( '<blockquote class="highlight" style="color:red;">', $contentPatched );
		$this->assertSame( 2, substr_count( $contentPatched, '<li>' ) );
		$this->assertSame( 2, substr_count( $contentPatched, '<td>' ) );
		$this->assertSame( 2, substr_count( $contentPatched, '<th>' ) );
		$this->assertStringContainsString( '[rsvp_button event="5"]', $contentPatched );
		$this->assertStringContainsString( 'href="https://example.com/more/"', $contentPatched );

		// Длина изменилась ровно настолько, насколько отличаются длины
		// оригинальных и переведённых кусков, — независимое, посимвольное
		// доказательство того, что патчер ничего не потерял и не добавил
		// сверх самих переводов.
		$expectedDelta = 0;

		foreach ( $contentTranslations as $hash => $translation ) {
			foreach ( $this->fieldSegments( $result->segments, PostSegment::FIELD_CONTENT ) as $postSegment ) {
				if ( $postSegment->segment->uniqHash === $hash ) {
					$expectedDelta += mb_strlen( $translation ) - mb_strlen( $postSegment->segment->text );
					break;
				}
			}
		}

		$this->assertSame( mb_strlen( $content ) + $expectedDelta, mb_strlen( $contentPatched ) );
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

		$titleTranslations = $this->translationsFor(
			$result->segments,
			PostSegment::FIELD_TITLE,
			array( $title => 'Overview of the new product' )
		);
		$excerptTranslations = $this->translationsFor(
			$result->segments,
			PostSegment::FIELD_EXCERPT,
			array( $excerpt => "What's new in this product" )
		);
		$contentTranslations = $this->translationsFor(
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

		$titlePatched   = PostFieldPatcher::apply( $title, $this->fieldSegments( $result->segments, PostSegment::FIELD_TITLE ), $titleTranslations );
		$excerptPatched = PostFieldPatcher::apply( $excerpt, $this->fieldSegments( $result->segments, PostSegment::FIELD_EXCERPT ), $excerptTranslations );
		$contentPatched = PostFieldPatcher::apply( $content, $this->fieldSegments( $result->segments, PostSegment::FIELD_CONTENT ), $contentTranslations );

		$this->assertNotNull( $titlePatched, 'Не удалось точно найти заголовок в исходной строке.' );
		$this->assertNotNull( $excerptPatched, 'Не удалось точно найти анонс в исходной строке.' );
		$this->assertNotNull( $contentPatched, 'Не удалось точно найти все сегменты содержимого в исходной строке.' );

		$this->assertSame( 'Overview of the new product', $titlePatched );
		$this->assertSame( "What's new in this product", $excerptPatched );

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
			$this->assertStringContainsString( $expectedEnglish, $contentPatched, "Missing translation: \"$expectedEnglish\"" );
		}

		foreach ( array( 'Первый заголовок блока', 'Первый пункт списка', 'Подпись к изображению' ) as $shouldBeGone ) {
			$this->assertStringNotContainsString( $shouldBeGone, $contentPatched, "Original Russian text leaked through: \"$shouldBeGone\"" );
		}

		// Комментарии Gutenberg — включая JSON-атрибуты — байт-в-байт, как
		// были: они скопированы substr()'ом из исходника, не пересобраны.
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
			$this->assertStringContainsString( $comment, $contentPatched, "Gutenberg comment lost or altered: \"$comment\"" );
		}

		// Самозакрывающийся <img ... /> внутри figure — весь тег целиком,
		// без единого символа расхождения (а не «класс где-то есть»).
		$this->assertStringContainsString(
			'<img src="https://example.com/wp-content/uploads/photo.jpg" alt="Photo description" class="wp-image-42"/>',
			$contentPatched
		);

		// Классы, инлайн-стиль, шорткод, ссылка — не тронуты.
		$this->assertStringContainsString( 'class="wp-block-heading"', $contentPatched );
		$this->assertStringContainsString( 'class="wp-block-list"', $contentPatched );
		$this->assertStringContainsString( 'class="wp-block-table"', $contentPatched );
		$this->assertStringContainsString( 'class="wp-block-image size-large"', $contentPatched );
		$this->assertStringContainsString( 'class="wp-element-caption"', $contentPatched );
		$this->assertStringContainsString( 'class="highlight has-text-color has-red-color"', $contentPatched );
		$this->assertStringContainsString( 'class="wp-block-button__link"', $contentPatched );
		$this->assertStringContainsString( 'style="color:red"', $contentPatched );
		$this->assertStringContainsString( '[rsvp_button event="5"]', $contentPatched );
		$this->assertStringContainsString( 'href="https://example.com/more/"', $contentPatched );

		// Тот же посимвольный контроль длины, что и в классическом тесте.
		$expectedDelta = 0;

		foreach ( $contentTranslations as $hash => $translation ) {
			foreach ( $this->fieldSegments( $result->segments, PostSegment::FIELD_CONTENT ) as $postSegment ) {
				if ( $postSegment->segment->uniqHash === $hash ) {
					$expectedDelta += mb_strlen( $translation ) - mb_strlen( $postSegment->segment->text );
					break;
				}
			}
		}

		$this->assertSame( mb_strlen( $content ) + $expectedDelta, mb_strlen( $contentPatched ) );
	}
}
