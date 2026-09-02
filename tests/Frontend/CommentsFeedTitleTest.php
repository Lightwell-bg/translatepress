<?php
/**
 * Тесты перевода заголовка ссылки на RSS-фид комментариев записи.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Frontend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Frontend\CommentsFeedTitle;
use WpMlp\Rendering\HtmlDocument;
use WpMlp\Rendering\Segment;
use WpMlp\Support\Hash;
use WpMlp\Support\Text;

#[CoversClass( CommentsFeedTitle::class )]
final class CommentsFeedTitleTest extends TestCase {

	/**
	 * `<link rel="alternate" type="application/rss+xml">` для фида
	 * комментариев записи собирает само ядро WordPress ДО того, как плагин
	 * успевает перехватить HTML: `title="{имя сайта} » {заголовок записи}
	 * Comments Feed"`, где заголовок берётся напрямую из БД, без перевода
	 * (записи не дублируются — один и тот же заголовок для всех языков).
	 * Суффикс «Comments Feed» при этом уже переведён — его переводит сам
	 * WordPress через LocaleSwitcher, официальным языковым пакетом. Менять
	 * нужно только заголовок записи внутри строки, оставив остальное как
	 * есть.
	 *
	 * @param string $attribute   Исходное значение атрибута `title`.
	 * @param string $rawTitle    Заголовок записи как есть в БД.
	 * @param string $translated  Перевод заголовка.
	 * @param string $expected    Что должно получиться.
	 */
	#[DataProvider( 'attributes' )]
	public function testReplaceTitle( string $attribute, string $rawTitle, string $translated, string $expected ): void {
		$this->assertSame( $expected, CommentsFeedTitle::replaceTitle( $attribute, $rawTitle, $translated ) );
	}

	/**
	 * @return list<array{string, string, string, string}>
	 */
	public static function attributes(): array {
		return array(
			array(
				'CenterAI » 1200 AI-агентов OpenAI создали скрытую сеть и атаковали Hugging Face: что показало расследование Comments Feed',
				'1200 AI-агентов OpenAI создали скрытую сеть и атаковали Hugging Face: что показало расследование',
				'1,200 OpenAI AI agents created a hidden network and attacked Hugging Face: what the investigation revealed',
				'CenterAI » 1,200 OpenAI AI agents created a hidden network and attacked Hugging Face: what the investigation revealed Comments Feed',
			),
			// Заголовка нет в строке вовсе — например, тема переопределила
			// шаблон фида. Трогать нечего, строка остаётся как есть.
			array(
				'CenterAI » Comments Feed',
				'Заголовок записи',
				'Post title',
				'CenterAI » Comments Feed',
			),
			// Пустой заголовок ничего не заменяет — это отдельный случай
			// специально, хоть PHP и без него ведёт себя так же безопасно
			// (см. комментарий у replaceTitle()).
			array(
				'CenterAI » Comments Feed',
				'',
				'Post title',
				'CenterAI » Comments Feed',
			),
		);
	}

	/**
	 * uniq_hash обязан совпасть с тем, что для того же текста на том же
	 * исходном языке посчитал бы Extractor для обычного текстового узла
	 * (H1, `<title>`) — иначе перевод, уже сохранённый в базе, не найдётся
	 * никогда, хотя формально существует.
	 */
	public function testUniqHashMatchesAnOrdinaryTextSegment(): void {
		$title  = 'Заголовок записи';
		$locale = 'ru';

		$expected = Hash::ofParts(
			array(
				$locale,
				Segment::KIND_TEXT,
				Hash::of( Text::normalize( $title ) ),
				Hash::of( '' ),
				'',
				'',
				'',
			)
		);

		$this->assertSame( $expected, CommentsFeedTitle::uniqHash( $title, $locale ) );
	}

	/**
	 * Тот же заголовок, отформатированный чуть иначе (лишние пробелы),
	 * обязан давать тот же хеш — тем же способом, каким Extractor
	 * нормализует обычный текст перед хешированием.
	 */
	public function testUniqHashNormalizesWhitespaceLikeExtractorDoes(): void {
		$this->assertSame(
			CommentsFeedTitle::uniqHash( 'Заголовок  записи', 'ru' ),
			CommentsFeedTitle::uniqHash( '  Заголовок записи  ', 'ru' )
		);
	}

	/**
	 * Идентификация нужной ссылки: только `rel=alternate` +
	 * `type=application/rss+xml` с ИМЕННО тем href, что даёт фид
	 * комментариев ЭТОЙ записи. Категории, метки, общий фид сайта — мимо:
	 * их специально исключили из словаря переводов раньше как шум, и
	 * трогать их здесь не нужно.
	 *
	 * @param array<string, string> $attributes Атрибуты проверяемой ссылки.
	 * @param bool                  $expected   Совпадает ли она с искомой.
	 */
	#[DataProvider( 'linkCandidates' )]
	public function testMatchesFeedLink( array $attributes, bool $expected ): void {
		$this->assertSame(
			$expected,
			CommentsFeedTitle::matchesFeedLink(
				$attributes,
				'https://centerai.eu/blog/en/ataka-ai-agentov-openai-hugging-face-2026/feed/'
			)
		);
	}

	/**
	 * @return list<array{array<string, string>, bool}>
	 */
	public static function linkCandidates(): array {
		$right = array(
			'rel'  => 'alternate',
			'type' => 'application/rss+xml',
			'href' => 'https://centerai.eu/blog/en/ataka-ai-agentov-openai-hugging-face-2026/feed/',
		);

		return array(
			'сама ссылка'                 => array( $right, true ),
			'общий фид сайта'             => array( array_merge( $right, array( 'href' => 'https://centerai.eu/blog/en/feed/' ) ), false ),
			'фид рубрики'                 => array( array_merge( $right, array( 'href' => 'https://centerai.eu/blog/en/topics/ai-agents/feed/' ) ), false ),
			'не rss'                      => array( array_merge( $right, array( 'type' => 'text/xml+oembed' ) ), false ),
			'не alternate'                => array( array_merge( $right, array( 'rel' => 'canonical' ) ), false ),
			'регистр rel/type не мешает'  => array( array_merge( $right, array( 'rel' => 'Alternate', 'type' => 'Application/RSS+XML' ) ), true ),
		);
	}

	/**
	 * Тот же поиск, но уже на настоящем разобранном документе — с той же
	 * разметкой, что реально отдаёт сайт (сокращённый фрагмент из отчёта
	 * пользователя): рядом стоят фид сайта, фид комментариев сайта и
	 * oEmbed-ссылки, которые находиться не должны.
	 */
	public function testFindLinkElementOnARealDocument(): void {
		$html = '<!DOCTYPE html><html><head>'
			. '<link rel="alternate" type="application/rss+xml" title="CenterAI » Feed" href="https://centerai.eu/blog/en/feed/">'
			. '<link rel="alternate" type="application/rss+xml" title="CenterAI » Comments Feed" href="https://centerai.eu/blog/en/comments/feed/">'
			. '<link rel="alternate" type="application/rss+xml" title="CenterAI » Заголовок записи Comments Feed" href="https://centerai.eu/blog/en/some-post/feed/">'
			. '<link rel="alternate" title="oEmbed (JSON)" type="application/json+oembed" href="https://centerai.eu/blog/wp-json/oembed/1.0/embed?url=x">'
			. '</head><body><p>Текст</p></body></html>';

		$document = HtmlDocument::parse( $html );

		$this->assertNotNull( $document );

		$found = CommentsFeedTitle::findLinkElement( $document, 'https://centerai.eu/blog/en/some-post/feed/' );

		$this->assertNotNull( $found );
		$this->assertSame(
			'CenterAI » Заголовок записи Comments Feed',
			$found->getAttribute( 'title' )
		);

		// Адреса не той записи среди похожих найтись не должно.
		$this->assertNull( CommentsFeedTitle::findLinkElement( $document, 'https://centerai.eu/blog/en/another-post/feed/' ) );
	}
}
