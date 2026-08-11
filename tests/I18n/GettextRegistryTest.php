<?php
/**
 * Тесты gettext-контура: приоритет источников и запись непокрытого.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\I18n;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\I18n\GettextKey;
use WpMlp\I18n\GettextRegistry;
use WpMlp\I18n\LanguagePacks;
use WpMlp\I18n\LocaleSwitcher;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Settings\Settings;
use WpMlp\Storage\GettextStore;
use WpMlp\Storage\TranslationCache;

/**
 * Хранилище в памяти вместо `$wpdb` — см. Storage\GettextStore.
 */
final class InMemoryGettextStore implements GettextStore {

	/**
	 * @var list<array{msgid: string, domain: string, context: string, plural_key: ?int}>
	 */
	public array $inserted = array();

	/**
	 * @param array<string, string> $overrides Ключ GettextKey::lookup() => перевод.
	 */
	public function __construct( private readonly array $overrides = array() ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function overridesFor( string $locale, int $cacheVersion ): array {
		unset( $locale, $cacheVersion );

		return $this->overrides;
	}

	/**
	 * {@inheritDoc}
	 */
	public function insertMissing( array $rows ): int {
		foreach ( $rows as $row ) {
			$this->inserted[] = $row;
		}

		return count( $rows );
	}
}

#[CoversClass( GettextRegistry::class )]
#[CoversClass( GettextKey::class )]
final class GettextRegistryTest extends TestCase {

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				Settings::OPTION => array(
					'default_locale'   => 'ru',
					'discover_strings' => true,
					'languages'        => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'status' => 'published', 'wp_locale' => 'ru_RU' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'status' => 'published', 'wp_locale' => 'en_US' ),
						'bg' => array( 'locale' => 'bg', 'slug' => 'bg', 'status' => 'published', 'wp_locale' => 'bg_BG' ),
					),
				),
				'home' => 'https://site.example',
			)
		);

		// Пакет болгарского установлен: без него сбор строк намеренно
		// выключен целиком (см. testMissingLanguagePackStopsCollecting).
		wp_mlp_test_request( array( 'languages' => array( 'bg_BG' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		/*
		 * Болгарский, а не английский, — язык по умолчанию для тестов
		 * этого класса: на английском `msgid` и есть готовый ответ, и
		 * никакая строка в словарь не попадает (см.
		 * testEnglishTargetDoesNotStoreUntranslatedStrings). Проверять на
		 * нём запись новых строк значило бы проверять пустоту.
		 */
		$_SERVER['REQUEST_URI'] = '/bg/about/';
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		wp_mlp_test_request( array() );
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}

	private function registry( InMemoryGettextStore $store ): GettextRegistry {
		$settings = new Settings();

		return new GettextRegistry(
			new LocaleSwitcher( new LanguageResolver( $settings ) ),
			$store,
			new TranslationCache(),
			$settings,
			new LanguagePacks()
		);
	}

	/**
	 * Пункт 5.2 задания: наш ручной перевод главнее официального пакета.
	 * Иначе неудачную формулировку из пакета нечем было бы исправить.
	 */
	public function testOverrideBeatsTheOfficialLanguagePack(): void {
		$store = new InMemoryGettextStore(
			array( GettextKey::lookup( 'Reply', 'default', '', null ) => 'Ответить по-нашему' )
		);

		$registry = $this->registry( $store );

		// WordPress уже перевёл строку пакетом — мы всё равно отдаём своё.
		$this->assertSame( 'Ответить по-нашему', $registry->filterText( 'Reply from pack', 'Reply', 'default' ) );
	}

	/**
	 * Своего перевода нет — отдаём то, что вернул языковой пакет, не трогая.
	 */
	public function testLanguagePackAnswerIsReturnedWhenThereIsNoOverride(): void {
		$registry = $this->registry( new InMemoryGettextStore() );

		$this->assertSame( 'Reply from pack', $registry->filterText( 'Reply from pack', 'Reply', 'default' ) );
	}

	/**
	 * Нет ни своего перевода, ни пакета — остаётся оригинал.
	 */
	public function testMsgidIsReturnedWhenNothingTranslatesIt(): void {
		$registry = $this->registry( new InMemoryGettextStore() );

		$this->assertSame( 'Reply', $registry->filterText( 'Reply', 'Reply', 'default' ) );
	}

	/**
	 * Пункт 5.1: строки, закрытые официальным пакетом, в базу не пишутся
	 * вовсе — иначе словарь распух бы на тысячи строк ядра.
	 */
	public function testStringsCoveredByTheLanguagePackAreNeverStored(): void {
		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( 'Ответить', 'Reply', 'default' );
		$registry->flush();

		$this->assertSame( array(), $store->inserted );
	}

	/**
	 * А вот строка, которую пакет не покрыл, обязана попасть в словарь —
	 * это ровно то, ради чего контур и нужен.
	 */
	public function testUncoveredStringIsStoredWithItsGettextIdentity(): void {
		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( 'Download now', 'Download now', 'download-monitor' );
		$registry->flush();

		$this->assertCount( 1, $store->inserted );
		$this->assertSame(
			array(
				'msgid'      => 'Download now',
				'domain'     => 'download-monitor',
				'context'    => '',
				'plural_key' => null,
			),
			$store->inserted[0]
		);
	}

	/**
	 * Одна и та же строка на странице встречается десятки раз — в словарь
	 * она должна уйти один раз, а не десять.
	 */
	public function testTheSameMissingStringIsStoredOnlyOnce(): void {
		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		for ( $i = 0; $i < 5; $i++ ) {
			$registry->filterText( 'Download now', 'Download now', 'download-monitor' );
		}

		$registry->flush();

		$this->assertCount( 1, $store->inserted );
	}

	/**
	 * ТЗ 4.8: одинаковый текст в разных доменах — разные строки словаря.
	 * «Ответить» из ядра и «Ответить» из плагина могут требовать разного
	 * перевода, и переопределение одного не должно задевать другой.
	 */
	public function testSameMsgidInDifferentDomainsIsTranslatedSeparately(): void {
		$store = new InMemoryGettextStore(
			array( GettextKey::lookup( 'Reply', 'default', '', null ) => 'Ответить (ядро)' )
		);

		$registry = $this->registry( $store );

		$this->assertSame( 'Ответить (ядро)', $registry->filterText( 'Reply', 'Reply', 'default' ) );
		// Тот же msgid, другой домен — переопределение не применяется.
		$this->assertSame( 'Reply', $registry->filterText( 'Reply', 'Reply', 'my-plugin' ) );
	}

	/**
	 * То же самое для контекста `_x()`.
	 */
	public function testSameMsgidInDifferentContextsIsTranslatedSeparately(): void {
		$store = new InMemoryGettextStore(
			array( GettextKey::lookup( 'Post', 'default', 'verb', null ) => 'Опубликовать' )
		);

		$registry = $this->registry( $store );

		$this->assertSame( 'Опубликовать', $registry->filterTextWithContext( 'Post', 'Post', 'verb', 'default' ) );
		// Тот же msgid, контекст «существительное» — другой перевод (его нет).
		$this->assertSame( 'Post', $registry->filterTextWithContext( 'Post', 'Post', 'noun', 'default' ) );
		// И совсем без контекста — тоже отдельная строка.
		$this->assertSame( 'Post', $registry->filterText( 'Post', 'Post', 'default' ) );
	}

	/**
	 * Формы множественного числа не смешиваются: у каждой свой перевод.
	 */
	public function testPluralFormsDoNotMix(): void {
		$store = new InMemoryGettextStore(
			array(
				GettextKey::lookup( '%s comment', 'default', '', 0 ) => '%s комментарий',
				GettextKey::lookup( '%s comment', 'default', '', 1 ) => '%s комментария',
			)
		);

		$registry = $this->registry( $store );

		// Заглушки get_translations_for_domain() в тестах нет, поэтому
		// pluralKey() падает на английское правило: 1 -> форма 0, иначе 1.
		$this->assertSame( '%s комментарий', $registry->filterPlural( '%s comment', '%s comment', '%s comments', 1, 'default' ) );
		$this->assertSame( '%s комментария', $registry->filterPlural( '%s comments', '%s comment', '%s comments', 5, 'default' ) );
	}

	/**
	 * Английский как целевой язык: `msgid` уже английский, и «WordPress
	 * вернул оригинал» тут значит «перевод не нужен», а не «перевода нет».
	 * Без этой проверки одно открытие /en/ занесло бы в словарь ВСЕ строки
	 * ядра, темы и плагинов — тысячи строк, которые нечего переводить.
	 */
	public function testEnglishTargetDoesNotStoreUntranslatedStrings(): void {
		$_SERVER['REQUEST_URI'] = '/en/about/';

		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		// Ровно то, что происходит на каждой строке ядра при /en/.
		$registry->filterText( 'Reply', 'Reply', 'default' );
		$registry->filterText( 'Search', 'Search', 'default' );
		$registry->flush();

		$this->assertSame( array(), $store->inserted );
	}

	/**
	 * `en_GB` — это НЕ язык оригинала: британский сайт вправе захотеть
	 * «colour» вместо «color», и такие строки в словарь попадать должны.
	 */
	public function testBritishEnglishIsNotTreatedAsTheSourceLanguage(): void {
		$options = wp_mlp_test_options();
		$options[ Settings::OPTION ]['languages']['en']['wp_locale'] = 'en_GB';
		wp_mlp_test_options( $options );

		// У британского английского пакет есть — иначе сбор выключен вовсе.
		wp_mlp_test_request( array( 'languages' => array( 'en_GB' ) ) );

		$_SERVER['REQUEST_URI'] = '/en/about/';

		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( 'Color', 'Color', 'default' );
		$registry->flush();

		$this->assertCount( 1, $store->inserted );
	}

	/**
	 * У ядра WordPress есть msgid, которые переводом не являются: запятая-
	 * разделитель, `–` с контекстом «en dash», список сокращений для
	 * wptexturize(). Технически это gettext, но в списке «переведите
	 * интерфейс» они только прячут за собой настоящие строки.
	 */
	public function testPunctuationOnlyMsgidsAreNotCollected(): void {
		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( ',', ',', 'default' );
		$registry->filterTextWithContext( '–', '–', 'en dash', 'default' );
		$registry->filterTextWithContext( '×', '×', 'multiplication', 'default' );
		$registry->flush();

		$this->assertSame( array(), $store->inserted );
	}

	/**
	 * Настоящая строка интерфейса рядом с ними всё равно собирается —
	 * фильтр отсекает мусор, а не всё подряд.
	 */
	public function testRealStringsAreStillCollectedAlongsidePunctuation(): void {
		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( ',', ',', 'default' );
		$registry->filterText( 'Download now', 'Download now', 'download-monitor' );
		$registry->flush();

		$this->assertCount( 1, $store->inserted );
		$this->assertSame( 'Download now', $store->inserted[0]['msgid'] );
	}

	/**
	 * Без языкового пакета WordPress возвращает оригинал КАЖДОЙ строки —
	 * ядра, темы и всех плагинов. Собирать их значит завалить словарь
	 * тысячами строк, среди которых не найти те несколько, что правда
	 * ждут перевода. Пока пакета нет, сбор выключен целиком, а админка
	 * объясняет, почему список пуст.
	 */
	public function testMissingLanguagePackStopsCollecting(): void {
		wp_mlp_test_request( array( 'languages' => array() ) );

		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( 'Reply', 'Reply', 'default' );
		$registry->filterText( 'Download now', 'Download now', 'download-monitor' );
		$registry->flush();

		$this->assertSame( array(), $store->inserted );
	}

	/**
	 * Отключённый сбор строк (настройка «не записывать новые строки»)
	 * распространяется и на gettext — иначе галочка врала бы.
	 */
	public function testDiscoveryCanBeTurnedOff(): void {
		$options = wp_mlp_test_options();
		$options[ Settings::OPTION ]['discover_strings'] = false;
		wp_mlp_test_options( $options );

		$store    = new InMemoryGettextStore();
		$registry = $this->registry( $store );

		$registry->filterText( 'Download now', 'Download now', 'download-monitor' );
		$registry->flush();

		$this->assertSame( array(), $store->inserted );
	}

	/**
	 * Контур запоминает, что уже отдал, — по этому списку Extractor
	 * пропускает строку и не заводит её второй раз как обычный текст
	 * (см. следующий коммит).
	 */
	public function testServedTextsAreRememberedNormalized(): void {
		$registry = $this->registry( new InMemoryGettextStore() );

		$registry->filterText( '  Reply   now  ', 'Reply now', 'default' );

		$this->assertArrayHasKey( 'Reply now', $registry->servedTexts() );
	}
}
