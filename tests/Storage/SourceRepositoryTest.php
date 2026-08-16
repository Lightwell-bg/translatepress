<?php
/**
 * Тесты чистой логики SourceRepository.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Storage\SourceRepository;

#[CoversClass( SourceRepository::class )]
final class SourceRepositoryTest extends TestCase {

	#[DataProvider( 'boundaryCases' )]
	public function testMatchesSearchPhrase( string $haystack, string $search, bool $expected ): void {
		$this->assertSame( $expected, SourceRepository::matchesSearchPhrase( $haystack, $search ) );
	}

	/**
	 * @return list<array{string, string, bool}>
	 */
	public static function boundaryCases(): array {
		return array(
			// Ровно жалоба, с которой всё началось: короткая фраза не должна
			// находиться внутри произвольных слов, которые её лишь содержат.
			array( 'AI Act enters into force', 'AI', true ),
			array( 'This domain contains detail about email', 'AI', false ),
			array( 'Explain the plan', 'AI', false ),

			// Регистр не имеет значения.
			array( 'AI Act', 'ai', true ),
			array( 'ai act', 'AI', true ),

			// Кириллица — та же граница, что и для латиницы.
			array( 'Какой плагин выбрать', 'плагин', true ),
			array( 'Список плагинов WordPress', 'плагин', false ),
			array( 'плагин', 'плагин', true ),

			// Фраза из нескольких слов ищется как есть, границы — по краям
			// всей фразы целиком, а не по каждому слову отдельно.
			array( 'Новый AI Act уже действует', 'AI Act', true ),
			array( 'AI и Act — разные слова', 'AI Act', false ),

			// Пунктуация и начало/конец строки — тоже законная граница.
			array( 'Купить.', 'Купить', true ),
			array( 'плагин', 'плагин', true ),
			array( '(плагин)', 'плагин', true ),

			// Спецсимволы в поисковой фразе не должны ломать регулярное
			// выражение — предыдущий поиск через LIKE был терпим к любому
			// вводу, новый обязан остаться таким же безопасным.
			array( 'Цена: 50.00', '50.00', true ),

			/*
			 * Знак препинания сам по себе границы не требует: у него нет
			 * буквы/цифры на краю, с которой он мог бы слиться. Одинокий
			 * «?» находится где угодно — это просто обычный подстроковый
			 * поиск для не-буквенной фразы, тут защищать нечего.
			 */
			array( 'Вопрос?', '?', true ),
			array( 'Без вопросов', '?', false ),

			/*
			 * Живой баг: фраза, начинающаяся и заканчивающаяся слэшем,
			 * не находилась ни в одном адресе — граница требовалась и
			 * СЛЕВА от первого слэша, хотя сама фраза буквой не начинается
			 * и защищать её край было не от чего. `centerai.eu/blog/en/…` —
			 * перед слэшем буква «u», формальная проверка это отвергала.
			 */
			array( 'https://centerai.eu/blog/en/plaginy-multiyazychnosti-wordpress/', '/blog/en/', true ),
			array( 'https://centerai.eu/blog/bg/plaginy-multiyazychnosti-wordpress/', '/blog/en/', false ),
			array( '/kontakty/', '/kontakty/', true ),
			array( '/kontakty-2/', '/kontakty/', false ),

			// Пустая строка, где искать, — никогда не совпадение.
			array( '', 'AI', false ),
			array( 'AI', '', false ),
		);
	}

	/**
	 * Живой случай, который и был жалобой: поиск «AI» на сайте про
	 * AI-автоматизацию раньше находил её внутри «domain», «detail»,
	 * «explain» — десятки посторонних совпадений на одно нужное.
	 */
	public function testShortAcronymDoesNotMatchInsideUnrelatedWords(): void {
		$noise = array( 'domain', 'detail', 'explain', 'maintain', 'remain', 'against', 'campaign' );

		foreach ( $noise as $word ) {
			$this->assertFalse(
				SourceRepository::matchesSearchPhrase( $word, 'AI' ),
				// Фигурные скобки обязательны: байты «»» — валидные символы
				// в имени переменной для токенизатора PHP, и "$word»" без
				// них парсится как обращение к $word\xC2\xBB.
				"«{$word}» не должно считаться совпадением для поиска «AI»."
			);
		}

		$this->assertTrue( SourceRepository::matchesSearchPhrase( 'AI Act and AI agents', 'AI' ) );
	}
}
