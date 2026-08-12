<?php
/**
 * Защита идентичности строк словаря от случайного изменения формулы.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WpMlp\Rendering\BlockSanitizer;
use WpMlp\Rendering\Extractor;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;
use WpMlp\Support\Hash;

/**
 * `uniq_hash` — это ЕДИНСТВЕННОЕ, что связывает накопленный перевод с его
 * исходной строкой (см. docs/data-storage.md §3): в `translations` нет ни
 * текста оригинала, ни ссылки на место в документе — только `source_id`,
 * найденный по хешу. Поэтому любое изменение формулы (порядок частей, их
 * состав, значение любой из них) молча «отвязывает» ВСЕ ранее сохранённые
 * переводы: строки на сайте те же, хеш другой, в базе по нему ничего не
 * находится — сайт выглядит так, будто перевод стёрли.
 *
 * Ошибка при этом не выпадет нигде: ни исключения, ни предупреждения —
 * просто пустой результат `lookup()`. Поэтому формула зафиксирована здесь
 * «золотыми» значениями, посчитанными РЕАЛЬНЫМ {@see Extractor}, а не
 * повторной сборкой той же формулы в тесте (иначе тест проверял бы сам
 * себя и менялся бы вместе с ошибкой).
 *
 * Если этот тест упал — не правьте константы. Сначала поймите, что
 * изменилось в идентичности строк и не потеряет ли сайт переводы.
 */
#[CoversNothing]
final class HashCompatibilityTest extends TestCase {

	/**
	 * Хеш пустой строки — то, что лежит в `context_hash` у КАЖДОГО вида
	 * строк (поле зарезервировано под будущий контекст места использования
	 * и сегодня ничего не различает).
	 */
	private const EMPTY_STRING_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Извлекает сегменты из фрагмента страницы.
	 *
	 * @param string              $html        Разметка.
	 * @param array<string, true> $blockHashes Хеши известных translation blocks.
	 * @return list<Segment>
	 */
	private function segments( string $html, array $blockHashes = array() ): array {
		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		return ( new Extractor() )->extract( $document, 'ru', $blockHashes );
	}

	/**
	 * Первый сегмент указанного вида.
	 *
	 * @param list<Segment> $segments Найденные сегменты.
	 */
	private function firstOfKind( array $segments, string $kind ): Segment {
		foreach ( $segments as $segment ) {
			if ( $kind === $segment->kind ) {
				return $segment;
			}
		}

		$this->fail( "В разметке не нашлось сегмента вида \"$kind\"." );
	}

	/**
	 * Обычный текстовый узел. Самый массовый вид строк на сайте — если
	 * «поедет» он, отвяжется почти весь словарь.
	 */
	public function testPlainTextHashIsStable(): void {
		$segments = $this->segments( '<!DOCTYPE html><html><body><p>Обычный текст</p></body></html>' );

		$this->assertSame(
			'5f72371615d780fd861f58bf6f87da25d402dad449680545af31fdc31fd61ec3',
			$this->firstOfKind( $segments, Segment::KIND_TEXT )->uniqHash
		);
	}

	/**
	 * Значение переводимого атрибута (`alt`, `title`, `placeholder`…).
	 */
	public function testAttributeHashIsStable(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><body><img src="a.png" alt="Подпись картинки"></body></html>'
		);

		$this->assertSame(
			'9266f8f9f7a6d805bb179babd3ecdd520876740e7ce89fa8c0aa8f95e5eae5a5',
			$this->firstOfKind( $segments, Segment::KIND_ATTRIBUTE )->uniqHash
		);
	}

	/**
	 * Текстовое поле структурированных данных JSON-LD.
	 */
	public function testSeoHashIsStable(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><head><script type="application/ld+json">'
			. '{"@type":"Article","headline":"Заголовок графа"}'
			. '</script></head><body></body></html>'
		);

		$this->assertSame(
			'4916447178c4b5aa7c85d84363ae92f03cb96e0c588d8fa637062397e9ecfb15',
			$this->firstOfKind( $segments, Segment::KIND_SEO )->uniqHash
		);
	}

	/**
	 * Translation block — абзац, переводимый целиком вместе с разметкой.
	 */
	public function testHtmlBlockHashIsStable(): void {
		$html = '<!DOCTYPE html><html><body><p>Читайте <b>наш</b> блог</p></body></html>';

		// Блок опознаётся только если его хеш уже заведён (SourceRepository::blockHashes()).
		$document = HtmlDocument::parse( $html );
		$this->assertNotNull( $document );

		$paragraph = $document->document()->getElementsByTagName( 'p' )->item( 0 );
		$blockHash = Hash::of( BlockSanitizer::sanitize( $document->innerHtml( $paragraph ) ) );

		$segments = $this->segments( $html, array( $blockHash => true ) );

		$this->assertSame(
			'22ca156b841c4b8c2c7128422f52de524461b1c0d1ce1d9cb549a76d8293a551',
			$this->firstOfKind( $segments, Segment::KIND_HTML_BLOCK )->uniqHash
		);
	}

	/**
	 * Исключение для заголовка записи (см. docs/data-storage.md §3): у
	 * `og:title`/`twitter:title`/JSON-LD `headline` при совпадении текста с
	 * H1/`<title>` хеш считается как у обычного текста. Это тоже часть
	 * идентичности и тоже не должно «поехать» незаметно.
	 */
	public function testTitleMetaSharesPlainTextHash(): void {
		$segments = $this->segments(
			'<!DOCTYPE html><html><head><meta property="og:title" content="Общий заголовок"></head>'
			. '<body><h1>Общий заголовок</h1></body></html>'
		);

		$expected = '682cedf3b8576f525c8d0ddc87ec57a8441b6440839b6959d0fa241eea3b0294';

		$this->assertSame( $expected, $this->firstOfKind( $segments, Segment::KIND_TEXT )->uniqHash );
		$this->assertSame( $expected, $this->firstOfKind( $segments, Segment::KIND_ATTRIBUTE )->uniqHash );
	}

	/**
	 * `context_hash` — это поле про МЕСТО ИСПОЛЬЗОВАНИЯ строки (ТЗ 6.1),
	 * зарезервированное на будущее, и сегодня оно у всех видов строк равно
	 * хешу пустой строки.
	 *
	 * Отдельный тест, потому что при появлении gettext-контура его легко
	 * перепутать с `gettext_context` — контекстом из `_x()`. Это РАЗНЫЕ
	 * поля: контекст `_x()` живёт только в `gettext_context`, а
	 * `context_hash` у `kind = 'gettext'` остаётся ровно таким же, как у
	 * всех остальных видов. Класть `_x()`-контекст в `context_hash` нельзя:
	 * это сместит идентичность и разойдётся с колонкой в БД.
	 */
	public function testContextHashIsAlwaysTheEmptyStringHash(): void {
		$this->assertSame( self::EMPTY_STRING_HASH, Hash::of( '' ) );

		// Хеш собран ровно из тех частей, что и в Extractor::makeSegment(),
		// с context_hash = хеш пустой строки: совпадение с «золотым»
		// значением обычного текста доказывает, что там именно оно.
		$this->assertSame(
			'5f72371615d780fd861f58bf6f87da25d402dad449680545af31fdc31fd61ec3',
			Hash::ofParts(
				array( 'ru', Segment::KIND_TEXT, Hash::of( 'Обычный текст' ), self::EMPTY_STRING_HASH, '', '', '' )
			)
		);
	}

	/**
	 * NULL в базе и пустая строка в формуле дают ОДИН И ТОТ ЖЕ хеш.
	 *
	 * Это то, что делает сегодняшнее расхождение безопасным: в колонках
	 * `domain`/`gettext_context`/`plural_key` у не-gettext строк лежит
	 * `NULL`, а в формулу те же части уходят пустыми строками — хеш от
	 * этого не меняется.
	 */
	public function testNullAndEmptyStringAreInterchangeableInTheFormula(): void {
		$sourceHash = Hash::of( 'Обычный текст' );

		$this->assertSame(
			Hash::ofParts( array( 'ru', Segment::KIND_TEXT, $sourceHash, self::EMPTY_STRING_HASH, '', '', '' ) ),
			Hash::ofParts( array( 'ru', Segment::KIND_TEXT, $sourceHash, self::EMPTY_STRING_HASH, null, null, null ) )
		);
	}

	/**
	 * ГЛАВНОЕ ПРАВИЛО задания про gettext, зафиксированное тестом.
	 *
	 * `domain`/`gettext_context`/`plural_key` заполняются реальными
	 * значениями ТОЛЬКО у новых строк с `kind = 'gettext'`. Бэкфилить их в
	 * уже существующие `text`/`attribute`/`html_block`/`seo` нельзя ни при
	 * каких доработках: тест ниже показывает, что происходит — тот же
	 * текст, тот же вид, тот же язык, но хеш ДРУГОЙ, значит сохранённый
	 * перевод по нему уже не найдётся.
	 *
	 * Для новых gettext-строк это же свойство, наоборот, работает на нас:
	 * одинаковый msgid в разных доменах и контекстах обязан давать разные
	 * строки словаря (ТЗ 4.8) — и даёт, ровно по той же причине.
	 */
	public function testFillingGettextFieldsChangesTheHashAndMustNeverBeBackfilled(): void {
		$sourceHash = Hash::of( 'Обычный текст' );

		$existing = Hash::ofParts(
			array( 'ru', Segment::KIND_TEXT, $sourceHash, self::EMPTY_STRING_HASH, '', '', '' )
		);

		$backfilled = Hash::ofParts(
			array( 'ru', Segment::KIND_TEXT, $sourceHash, self::EMPTY_STRING_HASH, 'default', '', '' )
		);

		$this->assertNotSame(
			$existing,
			$backfilled,
			'Бэкфил domain в существующую строку меняет uniq_hash — перевод отвяжется.'
		);
	}

	/**
	 * Оборотная сторона того же правила: у новых gettext-строк домен и
	 * контекст ОБЯЗАНЫ различать строки, иначе «Ответить» из ядра и
	 * «Ответить» из плагина получили бы один перевод на двоих.
	 */
	public function testGettextFieldsDistinguishOtherwiseIdenticalStrings(): void {
		$msgidHash = Hash::of( 'Reply' );

		$core = Hash::ofParts( array( 'en_US', 'gettext', $msgidHash, self::EMPTY_STRING_HASH, 'default', '', '' ) );
		$theme = Hash::ofParts( array( 'en_US', 'gettext', $msgidHash, self::EMPTY_STRING_HASH, 'twentytwentyfour', '', '' ) );
		$withContext = Hash::ofParts( array( 'en_US', 'gettext', $msgidHash, self::EMPTY_STRING_HASH, 'default', 'verb', '' ) );
		$pluralForm = Hash::ofParts( array( 'en_US', 'gettext', $msgidHash, self::EMPTY_STRING_HASH, 'default', '', '1' ) );

		$this->assertCount(
			4,
			array_unique( array( $core, $theme, $withContext, $pluralForm ) ),
			'Домен, контекст и форма множественного числа обязаны давать разные строки словаря.'
		);
	}

	/**
	 * Ни один из «золотых» видов строк не совпадает с другим при одинаковом
	 * тексте — `kind` действительно входит в идентичность.
	 */
	public function testKindIsPartOfIdentity(): void {
		$sourceHash = Hash::of( 'Одинаковый текст' );

		$hashes = array();

		foreach ( array( Segment::KIND_TEXT, Segment::KIND_ATTRIBUTE, Segment::KIND_SEO, Segment::KIND_HTML_BLOCK, 'gettext' ) as $kind ) {
			$hashes[] = Hash::ofParts( array( 'ru', $kind, $sourceHash, self::EMPTY_STRING_HASH, '', '', '' ) );
		}

		$this->assertCount( 5, array_unique( $hashes ) );
	}

	/**
	 * Язык — тоже часть идентичности: одна и та же строка, найденная на
	 * сайте с другим исходным языком, это другая строка словаря. Важно для
	 * gettext, где `source_locale` намеренно другой (`en_US`, а не язык
	 * сайта) — см. docs/data-storage.md.
	 */
	public function testSourceLocaleIsPartOfIdentity(): void {
		$sourceHash = Hash::of( 'Reply' );

		$this->assertNotSame(
			Hash::ofParts( array( 'ru', 'gettext', $sourceHash, self::EMPTY_STRING_HASH, 'default', '', '' ) ),
			Hash::ofParts( array( 'en_US', 'gettext', $sourceHash, self::EMPTY_STRING_HASH, 'default', '', '' ) )
		);
	}
}
