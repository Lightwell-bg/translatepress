<?php
/**
 * Тесты списков тегов для wp_kses: каждый тег и атрибут, который реально
 * выдают билдеры разметки админки (во всех ветках), должен быть в списке,
 * иначе wp_kses молча вырежет его из страницы.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Admin\AllowedHtml;
use LingoCanvas\Admin\EditorLink;
use LingoCanvas\Admin\EditorPage;
use LingoCanvas\Admin\PostListLanguages;
use LingoCanvas\Admin\SettingsPage;
use LingoCanvas\Admin\StringTranslationPage;
use LingoCanvas\Admin\VisibilityAssets;
use LingoCanvas\I18n\LanguagePacks;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\SourceRepository;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Support\Env;
use LingoCanvas\Tests\Doubles\FakeWpdb;
use LingoCanvas\Translation\ProviderFactory;

#[CoversClass( AllowedHtml::class )]
final class AllowedHtmlTest extends TestCase {

	private FakeWpdb $db;

	protected function setUp(): void {
		$this->db        = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->db;
		unset( $GLOBALS['wp_query'], $GLOBALS['lingocanvas_test_meta_primed'] );
		$GLOBALS['pagenow'] = 'edit.php';
		$_GET               = array();
		Env::reset();
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_request( array( 'is_admin' => true ) );
		$this->configure( Settings::POSTS_MANUAL );
		$this->posts( array( 1, 2, 3, 4 ), array( 5 => 'draft' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_query'], $GLOBALS['pagenow'], $GLOBALS['lingocanvas_test_meta_primed'] );
		$_GET = array();
		Env::reset();
		lingocanvas_test_options( array() );
		lingocanvas_test_postmeta( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_request( array() );
	}

	/**
	 * @param array<string, mixed> $settings Настройки поверх значений по умолчанию.
	 */
	private function configure( string $mode, array $settings = array() ): void {
		lingocanvas_test_options(
			array(
				'home'                 => 'https://example.test',
				Settings::optionName() => array_merge(
					Settings::defaults(),
					array(
						'default_locale'          => 'ru',
						'untranslated_posts_mode' => $mode,
						'languages'               => array(
							'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
							'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
							'de' => array( 'locale' => 'de', 'slug' => 'de', 'label' => 'Deutsch', 'status' => 'draft' ),
						),
					),
					$settings
				),
			)
		);
	}

	/**
	 * @param list<int>          $published Опубликованные страницы.
	 * @param array<int, string> $other     id => статус для остальных записей.
	 * @param array<string, bool> $caps     Права.
	 */
	private function posts( array $published, array $other = array(), array $caps = array() ): void {
		$posts = array();

		foreach ( $published as $id ) {
			$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => 'T' . $id, 'post_name' => 'p' . $id, 'post_type' => 'page' ) );
		}

		foreach ( $other as $id => $status ) {
			$posts[ $id ] = new \WP_Post( array( 'ID' => $id, 'post_status' => $status, 'post_title' => 'T' . $id, 'post_name' => 'p' . $id, 'post_type' => 'page' ) );
		}

		lingocanvas_test_wp( array( 'posts' => $posts, 'caps' => $caps ) );
		$GLOBALS['wp_query'] = (object) array( 'posts' => array_values( $posts ) );
	}

	private function visibility(): PostLanguageVisibility {
		return new PostLanguageVisibility( new TranslationCache(), new OccurrenceRepository() );
	}

	/**
	 * Все теги и атрибуты разметки должны входить в список.
	 *
	 * @param string                             $html    Разметка билдера (до wp_kses).
	 * @param array<string, array<string, bool>> $allowed Список AllowedHtml.
	 */
	private function assertCovered( string $html, array $allowed ): void {
		$this->assertNotSame( '', $html );

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$seen = 0;

		foreach ( $dom->getElementsByTagName( '*' ) as $element ) {
			$tag = strtolower( $element->tagName );

			if ( 'html' === $tag || 'body' === $tag ) {
				continue;
			}

			++$seen;
			$this->assertArrayHasKey( $tag, $allowed, "Тег <{$tag}> не разрешён в: {$html}" );

			foreach ( $element->attributes ?? array() as $attribute ) {
				$this->assertArrayHasKey( $attribute->name, $allowed[ $tag ], "Атрибут {$attribute->name} у <{$tag}> не разрешён в: {$html}" );
			}
		}

		$this->assertGreaterThan( 0, $seen );
	}

	/**
	 * Регрессия самой проверки: незнакомый тег и атрибут должны ловиться.
	 */
	public function testCoverageCheckDetectsMissingTagAndAttribute(): void {
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );

		$this->assertCovered( '<span class="a" onclick="x"></span>', AllowedHtml::visibilityToggle() );
	}

	// --- Переключатель -----------------------------------------------------------

	public function testVisibilityToggleCoversAllBranches(): void {
		foreach ( array( true, false ) as $visible ) {
			foreach ( array( '', 'Some reason' ) as $disabled ) {
				$this->assertCovered( VisibilityAssets::toggleHtml( 7, 'de', $visible, 'on site', $disabled ), AllowedHtml::visibilityToggle() );
			}
		}
	}

	// --- Блок в визуальном редакторе ---------------------------------------------

	private function editorBlock( int $postId ): string {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );
		$page     = new EditorPage(
			$settings,
			$urls,
			new ProviderFactory( $settings ),
			$this->visibility(),
			new OccurrenceRepository()
		);
		$method   = new \ReflectionMethod( $page, 'visibilityBlock' );

		return (string) $method->invoke( $page, $postId, $settings->secondary()['en'] );
	}

	public function testEditorVisibilityBlockCoversAllBranches(): void {
		$progress = array(
			array( 10, 10 ),
			array( 10, 6 ),
			array( 10, 0 ),
			array( 0, 0 ),
		);

		foreach ( $progress as list( $total, $done ) ) {
			foreach ( array( true, false ) as $visible ) {
				$this->db->row = array( 'total' => $total, 'done' => $done );
				lingocanvas_test_postmeta( array() );

				if ( $visible ) {
					$this->visibility()->setMany( array( 1 ), 'en', true );
				}

				$this->assertCovered( $this->editorBlock( 1 ), AllowedHtml::editorVisibilityBlock() );
			}
		}
	}

	// --- Колонка «Языки» ---------------------------------------------------------

	private function cellHtml( PostListLanguages $service, int $postId ): string {
		$method = new \ReflectionMethod( $service, 'cellHtml' );

		return (string) $method->invoke( $service, lingocanvas_test_wp()['posts'][ $postId ] );
	}

	private function listService( bool $withVisibility, bool $withFlag ): PostListLanguages {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );
		$service  = new PostListLanguages( $settings, new EditorLink( $settings, $urls ), $withVisibility ? $this->visibility() : null );

		if ( $withFlag ) {
			$property = new \ReflectionProperty( $service, 'flagUrls' );
			$property->setValue( $service, array( 'en' => 'https://example.test/flags/en.svg', 'de' => 'https://example.test/flags/de.svg' ) );
		}

		return $service;
	}

	public function testPostListCellCoversStatesToggleSeoDraftAndFlag(): void {
		// Колонки: done_0/seo_0 — en, done_1/seo_1 — de.
		$this->db->rows = array(
			array( 'object_id' => 1, 'total' => 5, 'done_0' => 5, 'seo_0' => 0, 'done_1' => 3, 'seo_1' => 2 ),
			array( 'object_id' => 2, 'total' => 4, 'done_0' => 0, 'seo_0' => 1, 'done_1' => 0, 'seo_1' => 0 ),
		);
		$this->visibility()->setMany( array( 1 ), 'en', true );

		foreach ( array( true, false ) as $withFlag ) {
			$service = $this->listService( true, $withFlag );

			// 1: полно/частично + SEO у черновика; 2: нет перевода; 3 и 4: строк ещё нет; 5: запись не опубликована.
			foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
				$this->assertCovered( $this->cellHtml( $service, $id ), AllowedHtml::postListCell() );
			}
		}

		$this->assertStringContainsString( '<img ', $this->cellHtml( $this->listService( true, true ), 1 ) );
	}

	public function testPostListCellCoversDisabledToggleAndAllMode(): void {
		$this->posts( array( 1 ), array(), array( 'edit_post:1' => false ) );
		$this->assertCovered( $this->cellHtml( $this->listService( true, false ), 1 ), AllowedHtml::postListCell() );

		$this->posts( array( 1 ) );
		$this->configure( Settings::POSTS_ALL );
		$this->assertCovered( $this->cellHtml( $this->listService( true, false ), 1 ), AllowedHtml::postListCell() );
	}

	// --- Статус ключа ------------------------------------------------------------

	/**
	 * @param array<string, string> $constants Константы wp-config.php.
	 * @param array<string, mixed>  $overrides Настройки поверх значений по умолчанию.
	 * @param array{content?: ?string, parent?: ?string}|null $envPaths Пути env-файлов.
	 */
	private function keyStatus( array $constants = array(), array $overrides = array(), ?array $envPaths = null ): string {
		$this->configure( Settings::POSTS_MANUAL, $overrides );

		$settings = new Settings();
		$page     = new SettingsPage(
			$settings,
			new LanguagePacks(),
			new ProviderFactory( $settings, static fn( string $name ): ?string => $constants[ $name ] ?? null, $envPaths )
		);

		return $page->keyStatusHtml();
	}

	public function testKeyStatusCoversAllSources(): void {
		$allowed = AllowedHtml::keyStatus();

		$this->assertCovered( $this->keyStatus( array( ProviderFactory::CONST_API_KEY => 'sk-secret-value' ) ), $allowed );
		$this->assertCovered( $this->keyStatus( array(), array( 'openai_api_key' => 'sk-secret-abcd' ) ), $allowed );
		$this->assertCovered( $this->keyStatus(), $allowed );

		$dir = sys_get_temp_dir() . '/mlp-allowed-' . uniqid();
		mkdir( $dir );

		// Ключ из .env плагина: предупреждение div.notice.
		file_put_contents( $dir . '/.env', "OPENAI_API_KEY=sk-plugin-secret\n" );
		Env::load( $dir . '/.env' );
		$pluginEnv = $this->keyStatus();
		$this->assertStringContainsString( 'notice-warning', $pluginEnv );
		$this->assertCovered( $pluginEnv, $allowed );
		unlink( $dir . '/.env' );
		Env::reset();

		// Файл в wp-content без защитной строки: дополнительный div.notice-error.
		file_put_contents( $dir . '/lingocanvas.env.php', "OPENAI_API_KEY=sk-leak\n" );
		Env::loadGuarded( $dir . '/lingocanvas.env.php' );
		$unguarded = $this->keyStatus();
		$this->assertStringContainsString( 'notice-error', $unguarded );
		$this->assertCovered( $unguarded, $allowed );
		unlink( $dir . '/lingocanvas.env.php' );
		rmdir( $dir );
	}

	// --- Выбор области -----------------------------------------------------------

	public function testScopeControlCoversAllBranches(): void {
		$allowed = AllowedHtml::scopeControl();

		$this->assertCovered( StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_ALL, 0, '' ), $allowed );
		$this->assertCovered( StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_GLOBAL, 0, '' ), $allowed );
		$this->assertCovered( StringTranslationPage::scopeControlHtml( SourceRepository::SCOPE_OBJECT, 42, 'Про "нас" <b>' ), $allowed );
	}
}
