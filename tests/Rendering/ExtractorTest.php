<?php
/**
 * Тесты извлечения строк из DOM и подстановки перевода.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;

#[CoversClass( Extractor::class )]
#[CoversClass( HtmlDocument::class )]
#[CoversClass( Segment::class )]
final class ExtractorTest extends TestCase {

	/**
	 * Тексты найденных сегментов.
	 *
	 * @param string $html Разметка страницы.
	 * @return list<string>
	 */
	private function texts( string $html ): array {
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document, 'Документ должен разбираться.' );

		return array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru' )
		);
	}

	/**
	 * Сегменты обязаны идти в том же порядке, в каком стоят в разметке —
	 * не важно для Translator/EditorMarkers (у каждого сегмента своя ссылка
	 * на узел DOM), но обязательно для RawContentPatcher: он клеит перевод
	 * в исходную строку одним проходом вперёд и без порядка перепутает
	 * позиции при первом же повторяющемся слове.
	 */
	public function testSegmentsComeOutInDocumentOrder(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>'
			. '<p>Первый</p>'
			. '<p>Второй с <em>акцентом</em> и остатком</p>'
			. '<ul><li>Третий</li><li>Четвёртый</li></ul>'
			. '<img alt="Пятый" src="a.png">'
			. '</body></html>'
		);

		$this->assertSame(
			array( 'Первый', 'Второй с', 'акцентом', 'и остатком', 'Третий', 'Четвёртый', 'Пятый' ),
			$texts
		);
	}

	/**
	 * Атрибут элемента идёт в списке РОВНО там, где сам элемент стоит среди
	 * соседей по тексту — а не до всех соседних текстовых узлов и не после.
	 */
	public function testAttributeSegmentTakesElementsPositionAmongSiblings(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>'
			. '<p>Раньше <img alt="Картинка" src="a.png"> позже</p>'
			. '</body></html>'
		);

		$this->assertSame( array( 'Раньше', 'Картинка', 'позже' ), $texts );
	}

	public function testExtractsTextNodesAndTitle(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><head><title>Заголовок</title></head>
			 <body><p>Привет <b>мир</b></p></body></html>'
		);

		$this->assertContains( 'Заголовок', $texts );
		$this->assertContains( 'Привет', $texts );
		$this->assertContains( 'мир', $texts );
	}

	public function testIgnoresScriptAndStyleContent(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>
				<script>var подпись = "Не переводить";</script>
				<style>.a{content:"Тоже не переводить"}</style>
				<pre>Код примера</pre>
				<p>Переводить</p>
			 </body></html>'
		);

		$this->assertSame( array( 'Переводить' ), $texts );
	}

	public function testExtractsTranslatableAttributes(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>
				<img src="a.png" alt="Кот на окне">
				<a href="/x" title="Подсказка">ссылка</a>
				<input type="search" placeholder="Поиск по сайту">
				<button aria-label="Закрыть">×</button>
				<meta name="description" content="Описание страницы">
			 </body></html>'
		);

		$this->assertContains( 'Кот на окне', $texts );
		$this->assertContains( 'Подсказка', $texts );
		$this->assertContains( 'Поиск по сайту', $texts );
		$this->assertContains( 'Закрыть', $texts );
		$this->assertContains( 'Описание страницы', $texts );
	}

	/**
	 * `value` у текстового поля — это данные посетителя, а не надпись.
	 */
	public function testExtractsValueOnlyFromButtons(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>
				<input type="submit" value="Отправить">
				<input type="text" value="Иванов Иван">
			 </body></html>'
		);

		$this->assertContains( 'Отправить', $texts );
		$this->assertNotContains( 'Иванов Иван', $texts );
	}

	public function testSkipsExcludedSubtrees(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>
				<div id="wpadminbar"><span>Панель админа</span></div>
				<div data-no-translation><span>Не трогать</span></div>
				<div translate="no">Тоже не трогать</div>
				<p>Обычный текст</p>
			 </body></html>'
		);

		$this->assertSame( array( 'Обычный текст' ), $texts );
	}

	public function testSkipsNumbersAndUrls(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body>
				<span>2024</span><span>19,90 ₽</span><span>https://example.com/a</span>
				<p>Цена товара</p>
			 </body></html>'
		);

		$this->assertSame( array( 'Цена товара' ), $texts );
	}

	/**
	 * Одинаковая строка должна давать один ключ словаря, где бы ни встретилась.
	 */
	public function testIdenticalStringsShareUniqHash(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Купить</p><span>  Купить  </span></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );

		$this->assertCount( 2, $segments );
		$this->assertSame( $segments[0]->uniqHash, $segments[1]->uniqHash );
	}

	/**
	 * Текст и атрибут — разные виды строк, у них разные ключи (ТЗ 6.1: kind
	 * входит в уникальный индекс).
	 */
	public function testTextAndAttributeGetDifferentHashes(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Купить</p><img src="a.png" alt="Купить"></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );

		$this->assertCount( 2, $segments );
		$this->assertNotSame( $segments[0]->uniqHash, $segments[1]->uniqHash );
	}

	public function testRoundTripKeepsMarkupIntact(): void {
		$html     = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Тест</title></head>'
			. '<body><p>Привет</p><img src="a.png" alt="Кот"><br><hr></body></html>';
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$result = $document->html();

		$this->assertStringContainsString( 'Привет', $result );
		$this->assertStringContainsString( 'lang="ru"', $result );
		$this->assertStringContainsString( '<img', $result );
		$this->assertStringContainsString( '<br>', $result );
	}

	/**
	 * Подстановка не должна экранировать текст заранее: DOM делает это сам,
	 * иначе на странице появилось бы `&amp;amp;`.
	 */
	public function testApplyEscapesExactlyOnce(): void {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body><p>Чай</p></body></html>' );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$segments[0]->apply( 'Tea & coffee' );

		$html = $document->html();

		$this->assertStringContainsString( 'Tea &amp; coffee', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	public function testApplyKeepsSurroundingWhitespace(): void {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body><p> Чай <b>x</b></p></body></html>' );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );

		foreach ( $segments as $segment ) {
			if ( 'Чай' === $segment->text ) {
				$segment->apply( 'Tea' );
			}
		}

		$this->assertStringContainsString( '<p> Tea <b>', $document->html() );
	}

	/**
	 * Фолбэк на libxml включается на PHP 8.1–8.3. Он должен не портить
	 * кириллицу и находить те же строки, что и встроенный HTML5-парсер.
	 */
	public function testLegacyParserKeepsCyrillicAndFindsSameStrings(): void {
		$html     = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Тест</title></head>'
			. '<body><p>Привет, мир</p><img src="a.png" alt="Кот"></body></html>';
		$document = HtmlDocument::parse( $html, true );

		$this->assertNotNull( $document );

		$texts = array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru' )
		);

		$this->assertContains( 'Привет, мир', $texts );
		$this->assertContains( 'Кот', $texts );
		$this->assertStringContainsString( 'Привет, мир', $document->html() );
	}

	public function testLegacyParserAppliesTranslation(): void {
		$document = HtmlDocument::parse( '<!DOCTYPE html><html><body><p>Чай</p></body></html>', true );

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$segments[0]->apply( 'Tea & coffee' );

		$html = $document->html();

		$this->assertStringContainsString( 'Tea &amp; coffee', $html );
		$this->assertStringNotContainsString( '&amp;amp;', $html );
	}

	public function testBrokenHtmlStillParses(): void {
		$document = HtmlDocument::parse( '<html><body><p>Текст<div>Ещё</p></body>' );

		$this->assertNotNull( $document );
		$this->assertStringContainsString( 'Текст', $document->html() );
	}
}
