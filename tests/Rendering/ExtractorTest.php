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

	/**
	 * `title` у `<link>` — подпись для агрегатора лент, а не текст для
	 * посетителя. Таких строк на сайте десятки (по одной на рубрику, метку
	 * и запись), они лезли в «Контент» наравне с настоящим текстом, а их
	 * перевод не менял ничего ни на экране, ни в выдаче.
	 */
	public function testLinkTitleIsNotExtracted(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><head>'
			. '<link rel="alternate" type="application/rss+xml" title="CenterAI » Лента комментариев">'
			. '</head><body><p>Настоящий текст</p></body></html>'
		);

		$this->assertSame( array( 'Настоящий текст' ), $texts );
	}

	/**
	 * А `title` у обычной ссылки — наоборот, всплывающая подсказка, её
	 * посетитель видит: отсекать заодно с метаданными нельзя.
	 */
	public function testAnchorTitleIsStillExtracted(): void {
		$texts = $this->texts(
			'<!DOCTYPE html><html><body><a href="/x" title="Записи автора">ссылка</a></body></html>'
		);

		$this->assertContains( 'Записи автора', $texts );
	}

	/**
	 * Строка, которую на этом же запросе уже отдал gettext-контур, не
	 * должна заводиться в словарь второй раз: иначе «Ответить» окажется и
	 * в «Интерфейсе», и в «Контенте», и переводить её придётся дважды, в
	 * двух разных экранах (п. 5.4 задания).
	 */
	public function testTextAlreadyServedByGettextIsSkipped(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Ответить</p><p>Обычный текст</p></body></html>'
		);

		$this->assertNotNull( $document );

		$texts = array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru', array(), false, array( 'Ответить' => true ) )
		);

		$this->assertSame( array( 'Обычный текст' ), $texts );
	}

	/**
	 * Сравнение идёт по нормализованному тексту — тому же виду, в каком
	 * строка попадает в словарь. Иначе лишние пробелы и переносы строк в
	 * разметке темы ломали бы совпадение.
	 */
	public function testGettextSkipMatchesNormalizedText(): void {
		$document = HtmlDocument::parse(
			"<!DOCTYPE html><html><body><p>  Оставить\n  комментарий  </p></body></html>"
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract(
			$document,
			'ru',
			array(),
			false,
			array( 'Оставить комментарий' => true )
		);

		$this->assertSame( array(), $segments );
	}

	/**
	 * Атрибуты отсеиваются по тому же набору: `placeholder` и `aria-label`
	 * сплошь и рядом выводятся через esc_attr_e(), то есть это такие же
	 * gettext-строки, и дубль в «Контенте» получился бы точно так же.
	 */
	public function testAttributeAlreadyServedByGettextIsSkipped(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body>'
			. '<input type="search" placeholder="Поиск">'
			. '<img src="a.png" alt="Своя картинка">'
			. '</body></html>'
		);

		$this->assertNotNull( $document );

		$texts = array_map(
			static fn( Segment $segment ): string => $segment->text,
			( new Extractor() )->extract( $document, 'ru', array(), false, array( 'Поиск' => true ) )
		);

		$this->assertSame( array( 'Своя картинка' ), $texts );
	}

	/**
	 * Пустой набор — прежнее поведение без единого изменения: этот путь
	 * проходят массовый перевод записи и все существующие вызовы.
	 */
	public function testEmptySkipListChangesNothing(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><body><p>Ответить</p></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru', array(), false, array() );

		$this->assertCount( 1, $segments );
		$this->assertSame( 'Ответить', $segments[0]->text );
	}

	/**
	 * Находит сегмент с точным (не нормализованным-обрезанным) текстом.
	 *
	 * @param list<Segment> $segments Найденные сегменты.
	 * @param string        $text     Искомый нормализованный текст.
	 */
	private function segmentWithText( array $segments, string $text ): ?Segment {
		foreach ( $segments as $segment ) {
			if ( $segment->text === $text ) {
				return $segment;
			}
		}

		return null;
	}

	/**
	 * Пункт 3 жалобы: заголовок записи должен получать один и тот же перевод
	 * в H1, `<title>`, og:title и twitter:title — иначе SEO-плагин выводит
	 * четыре независимо переведённых, потенциально расходящихся варианта
	 * одной и той же фразы. og:title/twitter:title хранятся как attribute
	 * (см. testTitleMetaKeepsOwnStoredKind ниже), но хешируются как обычный
	 * текст — ровно как H1 и `<title>`.
	 */
	public function testOgAndTwitterTitleShareHashWithMatchingHeadingText(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<title>Пять советов</title>'
			. '<meta property="og:title" content="Пять советов">'
			. '<meta name="twitter:title" content="Пять советов">'
			. '</head><body><h1>Пять советов</h1></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$matching = array_values( array_filter( $segments, static fn( Segment $s ): bool => 'Пять советов' === $s->text ) );

		$this->assertCount( 4, $matching, 'title/og:title/twitter:title/H1 должны дать четыре сегмента с одним и тем же текстом.' );

		$hashes = array_unique( array_map( static fn( Segment $s ): string => $s->uniqHash, $matching ) );

		$this->assertCount( 1, $hashes, 'У title/og:title/twitter:title/H1 с одинаковым текстом должен быть один uniq_hash.' );
	}

	/**
	 * Перевод, найденный по общему хешу, применяется независимо от того,
	 * в какой именно из четырёх сегментов подставлять, — так и должно быть,
	 * раз хеш один: Translator подставляет один и тот же перевод во все.
	 */
	public function testApplyingSharedTranslationReachesEverySurface(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<title>Пять советов</title>'
			. '<meta property="og:title" content="Пять советов">'
			. '</head><body><h1>Пять советов</h1></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );

		foreach ( $segments as $segment ) {
			if ( 'Пять советов' === $segment->text ) {
				$segment->apply( 'Five tips', $document );
			}
		}

		$html = $document->html();

		$this->assertStringContainsString( '<title>Five tips</title>', $html );
		$this->assertStringContainsString( 'og:title" content="Five tips"', $html );
		$this->assertStringContainsString( '<h1>Five tips</h1>', $html );
		$this->assertStringNotContainsString( 'Пять советов', $html );
	}

	/**
	 * og:title остаётся ПО ХРАНЕНИЮ атрибутом (`kind = attribute`), а не
	 * текстом: только хеш общий с текстом, не сам вид строки. Это важно для
	 * пункта 5 жалобы — meta-поля обязаны оставаться отличимыми как
	 * SEO/GEO в «Переводе строк», а не превращаться в обычный «Текст».
	 */
	public function testTitleMetaKeepsOwnStoredKindDespiteSharedHash(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<meta property="og:title" content="Пять советов">'
			. '</head><body><h1>Пять советов</h1></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$ogTitle  = $this->segmentWithText( $segments, 'Пять советов' );

		foreach ( $segments as $segment ) {
			if ( Segment::KIND_ATTRIBUTE === $segment->kind && 'content' === $segment->attribute ) {
				$ogTitle = $segment;
			}
		}

		$this->assertNotNull( $ogTitle );
		$this->assertSame( Segment::KIND_ATTRIBUTE, $ogTitle->kind );
		$this->assertSame( 'content', $ogTitle->attribute );
	}

	/**
	 * Область действия узкая: только og:title/twitter:title, НЕ любое meta.
	 * og:description с тем же текстом, что и обычный абзац где-то на
	 * странице, — это совпадение, а не признак «одна и та же фраза заголовка»,
	 * и не должно неожиданно склеиваться в один перевод.
	 */
	public function testOgDescriptionDoesNotShareHashWithMatchingText(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<meta property="og:description" content="Добро пожаловать">'
			. '</head><body><p>Добро пожаловать</p></body></html>'
		);

		$this->assertNotNull( $document );

		$segments = ( new Extractor() )->extract( $document, 'ru' );
		$matching = array_values( array_filter( $segments, static fn( Segment $s ): bool => 'Добро пожаловать' === $s->text ) );

		$this->assertCount( 2, $matching );
		$this->assertNotSame( $matching[0]->uniqHash, $matching[1]->uniqHash );
	}

	/**
	 * Когда текст правда разный (например, у `<title>` есть суффикс с именем
	 * сайта, которого нет в H1), хеши остаются разными — общий хеш-вид не
	 * заставляет РАЗНЫЙ текст притворяться одинаковым, он лишь снимает
	 * искусственное различие по виду сегмента для текста, который и так
	 * совпадает.
	 */
	public function testTitleWithSiteNameSuffixStaysDistinctFromBareHeading(): void {
		$document = HtmlDocument::parse(
			'<!DOCTYPE html><html><head>'
			. '<title>Пять советов — CenterAI</title>'
			. '<meta property="og:title" content="Пять советов">'
			. '</head><body><h1>Пять советов</h1></body></html>'
		);

		$this->assertNotNull( $document );

		$segments  = ( new Extractor() )->extract( $document, 'ru' );
		$pageTitle = $this->segmentWithText( $segments, 'Пять советов — CenterAI' );
		$ogTitle   = $this->segmentWithText( $segments, 'Пять советов' );

		$this->assertNotNull( $pageTitle );
		$this->assertNotNull( $ogTitle );
		$this->assertNotSame( $pageTitle->uniqHash, $ogTitle->uniqHash );
	}
}
