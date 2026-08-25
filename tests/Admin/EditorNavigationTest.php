<?php
/**
 * Тесты перехода по ссылке внутри предпросмотра редактора.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\EditorPage;

#[CoversClass( EditorPage::class )]
final class EditorNavigationTest extends TestCase {

	private const SLUGS = array( 'ru', 'bg', 'en' );
	private const HOME  = 'centerai.eu';

	/**
	 * Клик по ссылке в предпросмотре присылает адрес целиком, а редактору
	 * нужен путь без базового пути установки и без языкового префикса —
	 * ровно тот, что лежит в `mlp_path`. Разбирает адрес сервер: своя
	 * реализация этого правила в JS разошлась бы с серверной ровно так
	 * же, как уже расходились между собой три копии проверки хоста.
	 *
	 * @param string $url      Адрес, по которому щёлкнули в предпросмотре.
	 * @param string $expected Путь, который должен попасть в mlp_path.
	 */
	#[DataProvider( 'urls' )]
	public function testUrlBecomesEditorPath( string $url, string $expected ): void {
		$this->assertSame(
			$expected,
			EditorPage::pathFromUrl( $url, '/blog', self::SLUGS, self::HOME )
		);
	}

	/**
	 * @return list<array{string, string}>
	 */
	public static function urls(): array {
		return array(
			// Обычная страница на языке предпросмотра.
			array( 'https://centerai.eu/blog/en/some-post/', '/some-post/' ),
			array( 'https://centerai.eu/blog/bg/some-post/', '/some-post/' ),

			// Язык по умолчанию идёт без префикса.
			array( 'https://centerai.eu/blog/some-post/', '/some-post/' ),

			// Главная установки.
			array( 'https://centerai.eu/blog/en/', '/' ),
			array( 'https://centerai.eu/blog/', '/' ),

			// Относительный адрес — тоже внутрь установки.
			array( '/blog/en/some-post/', '/some-post/' ),

			// Вложенные пути не теряются.
			array( 'https://centerai.eu/blog/en/topics/ai-agents/', '/topics/ai-agents/' ),

			/*
			 * Хвост запроса и якорь до `mlp_path` доходить не должны: это
			 * адрес страницы, а не конкретного места на ней.
			 */
			array( 'https://centerai.eu/blog/en/post/?utm_source=x', '/post/' ),
			array( 'https://centerai.eu/blog/en/post/#section', '/post/' ),

			/*
			 * Чужой хост и адрес вне установки в редактор не попадают:
			 * на корне этого домена живёт отдельный сайт со своими
			 * `/ru/`, `/en/`, `/bg/` — его страницы редактор открыть не
			 * может, и подсовывать вместо них случайный путь нельзя.
			 */
			array( 'https://other.example/blog/en/post/', '/' ),
			array( 'https://centerai.eu/en/', '/' ),
			array( 'https://centerai.eu/en/#services', '/' ),

			/*
			 * Обычная страница соседнего сайта — без языкового сегмента,
			 * который сам по себе схлопнулся бы в `/`. Без проверки границ
			 * установки её путь прошёл бы дальше как есть, и редактор
			 * открыл бы `/o-nas/` внутри блога — адрес, которого там нет.
			 */
			array( 'https://centerai.eu/o-nas/', '/' ),
			array( 'https://centerai.eu/kontakty/uslugi/', '/' ),

			// Мусор вместо адреса — на главную, а не в неопределённость.
			array( '', '/' ),
			array( 'javascript:alert(1)', '/' ),
		);
	}

	/**
	 * Установка в корне домена: базового пути нет, но языковой префикс
	 * убирается по-прежнему.
	 */
	public function testInstallationAtDomainRoot(): void {
		$this->assertSame(
			'/some-post/',
			EditorPage::pathFromUrl( 'https://centerai.eu/en/some-post/', '', self::SLUGS, self::HOME )
		);
	}

	/**
	 * Регистр хоста значения не имеет — домен в DNS регистронезависим.
	 */
	public function testHostCaseDoesNotMatter(): void {
		$this->assertSame(
			'/some-post/',
			EditorPage::pathFromUrl( 'https://CENTERAI.EU/blog/en/some-post/', '/blog', self::SLUGS, self::HOME )
		);
	}
}
