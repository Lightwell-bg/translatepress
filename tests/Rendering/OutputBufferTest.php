<?php
/**
 * Тесты перехвата HTML: подмена шаблона, обёртка template-buffer.php, штатный буфер 6.9+.
 *
 * Настоящий Translator финальный, поэтому тесты подключают подмену
 * tests/Doubles/TranslatorStub.php с тем же именем. Это возможно только в
 * отдельном процессе, пока настоящий класс не загружен, — отсюда
 * #[RunInSeparateProcess] на каждом тесте.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use LingoCanvas\Rendering\OutputBuffer;
use LingoCanvas\Rendering\Translator;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Settings\Settings;

#[CoversClass( OutputBuffer::class )]
#[PreserveGlobalState( false )]
final class OutputBufferTest extends TestCase {

	private const WRAPPER = __DIR__ . '/../../src/Rendering/template-buffer.php';

	/**
	 * Уровень буфера PHPUnit перед тестом.
	 */
	private int $baseLevel = 0;

	/**
	 * Временные файлы шаблонов.
	 *
	 * @var list<string>
	 */
	private array $files = array();

	protected function setUp(): void {
		require_once __DIR__ . '/../Doubles/TranslatorStub.php';

		$this->baseLevel = ob_get_level();

		lingocanvas_test_options(
			array(
				'home'                 => 'https://example.test',
				Settings::optionName() => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
					),
				),
			)
		);
		// До `wp` условные теги не вызываются.
		lingocanvas_test_wp( array( 'did_action' => array( 'wp' => 0 ) ) );
		lingocanvas_test_request( array() );

		$_SERVER['REQUEST_URI'] = '/en/about/';
		unset( $GLOBALS['lingocanvas_test_filters'], $GLOBALS['lingocanvas_test_added_actions'] );
		Translator::$calls = 0;
	}

	protected function tearDown(): void {
		lingocanvas_test_options( array() );
		lingocanvas_test_wp( array() );
		lingocanvas_test_request( array() );
		unset( $_SERVER['REQUEST_URI'], $GLOBALS['lingocanvas_test_filters'] );

		while ( ob_get_level() > $this->baseLevel ) {
			ob_end_clean();
		}

		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateKeepsTemplateOnDefaultLanguage(): void {
		$_SERVER['REQUEST_URI'] = '/about/';

		$this->assertSame( '/theme/index.php', $this->buffer()->wrapTemplate( '/theme/index.php' ) );
		$this->assertNull( OutputBuffer::active() );
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateKeepsTemplateOnNonPublicRequest(): void {
		lingocanvas_test_request( array( 'is_admin' => true ) );

		$this->assertSame( '/theme/index.php', $this->buffer()->wrapTemplate( '/theme/index.php' ) );
		$this->assertNull( OutputBuffer::active() );
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateKeepsNonStringAndEmptyTemplate(): void {
		$buffer = $this->buffer();

		$this->assertFalse( $buffer->wrapTemplate( false ) );
		$this->assertNull( $buffer->wrapTemplate( null ) );
		$this->assertSame( '', $buffer->wrapTemplate( '' ) );
		$this->assertNull( OutputBuffer::active() );
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateSwapsInWrapperAndRemembersTemplate(): void {
		$buffer = $this->buffer();
		$result = $buffer->wrapTemplate( '/theme/index.php' );

		$this->assertIsString( $result );
		$this->assertSame( realpath( self::WRAPPER ), realpath( $result ) );
		$this->assertSame( '/theme/index.php', $buffer->template() );
		$this->assertSame( $buffer, OutputBuffer::active() );
	}

	#[RunInSeparateProcess]
	public function testRegisterDefersTemplateHookToTemplateRedirectAndVerifiesCoreBuffer(): void {
		$buffer = $this->buffer();
		$buffer->register();

		$actions = $GLOBALS['lingocanvas_test_added_actions'] ?? array();

		$this->assertSame( array( array( array( $buffer, 'hookTemplate' ), PHP_INT_MAX ) ), $actions['template_redirect'] ?? null );
		$this->assertSame( array( array( array( $buffer, 'verifyCoreBuffer' ), PHP_INT_MAX ) ), $actions['wp_before_include_template'] ?? null );
		// Сам template_include ещё не подписан: он вешается позже, на template_redirect.
		$this->assertArrayNotHasKey( 'template_include', $GLOBALS['lingocanvas_test_filters'] ?? array() );
	}

	#[RunInSeparateProcess]
	public function testHookTemplateAddsTemplateIncludeLast(): void {
		$buffer = $this->buffer();
		$buffer->hookTemplate();

		$this->assertSame( array( array( array( $buffer, 'wrapTemplate' ), PHP_INT_MAX ) ), $GLOBALS['lingocanvas_test_filters']['template_include'] );
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateReturnsOriginalWhenCoreBufferWillStart(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';
		lingocanvas_test_wp(
			array(
				'did_action' => array( 'wp' => 0 ),
				'has_action' => array( 'wp_before_include_template' => true ),
			)
		);

		$buffer = $this->buffer();

		$this->assertSame( '/theme/index.php', $buffer->wrapTemplate( '/theme/index.php' ) );
		$this->assertNull( OutputBuffer::active() );
		$this->assertSame( '', $buffer->template() );
		$this->assertCount( 1, $GLOBALS['lingocanvas_test_filters']['wp_template_enhancement_output_buffer'] );
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateUsesWrapperWhenCoreBufferDisabledByFilter(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';
		lingocanvas_test_wp(
			array(
				'did_action'         => array( 'wp' => 0 ),
				'has_action'         => array( 'wp_before_include_template' => true ),
				'core_should_buffer' => false,
			)
		);

		$buffer = $this->buffer();
		$result = $buffer->wrapTemplate( '/theme/index.php' );

		$this->assertSame( realpath( self::WRAPPER ), realpath( (string) $result ) );
		$this->assertSame( $buffer, OutputBuffer::active() );
		$this->assertSame( '/theme/index.php', $buffer->template() );
	}

	#[RunInSeparateProcess]
	public function testWrapTemplateUsesWrapperWhenCoreStartActionRemoved(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';
		// has_action по умолчанию false: ядро не подписано на wp_before_include_template.
		$buffer = $this->buffer();
		$result = $buffer->wrapTemplate( '/theme/index.php' );

		$this->assertSame( realpath( self::WRAPPER ), realpath( (string) $result ) );
		$this->assertSame( $buffer, OutputBuffer::active() );
	}

	#[RunInSeparateProcess]
	public function testVerifyCoreBufferLogsOnlyWhenPredictedBufferDidNotStart(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';

		$log = $this->captureLog();

		$wp = array(
			'did_action' => array(
				'wp'                                            => 0,
				'wp_template_enhancement_output_buffer_started' => 0,
			),
			'has_action' => array( 'wp_before_include_template' => true ),
		);
		lingocanvas_test_wp( $wp );

		// Буфер не предсказывался (шаблон ещё не обработан): тишина.
		$buffer = $this->buffer();
		$buffer->verifyCoreBuffer();
		$this->assertSame( '', (string) file_get_contents( $log ) );

		// Предсказан, но не стартовал: запись в лог.
		$this->assertSame( '/theme/index.php', $buffer->wrapTemplate( '/theme/index.php' ) );
		$buffer->verifyCoreBuffer();
		$this->assertStringContainsString( 'template enhancement output buffer did not start', (string) file_get_contents( $log ) );
	}

	#[RunInSeparateProcess]
	public function testVerifyCoreBufferSilentWhenCoreBufferRunning(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';

		$log = $this->captureLog();

		lingocanvas_test_wp(
			array(
				'did_action' => array(
					'wp'                                            => 0,
					'wp_template_enhancement_output_buffer_started' => 1,
				),
				'has_action' => array( 'wp_before_include_template' => true ),
				'has_filter' => array( 'wp_template_enhancement_output_buffer' => true ),
			)
		);

		$buffer = $this->buffer();
		$buffer->wrapTemplate( '/theme/index.php' );

		ob_start( 'wp_finalize_template_enhancement_output_buffer' );
		$buffer->verifyCoreBuffer();
		ob_end_clean();

		$this->assertSame( '', (string) file_get_contents( $log ) );
	}

	#[RunInSeparateProcess]
	public function testVerifyCoreBufferSilentWhenWrapperIsUsed(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';

		$log = $this->captureLog();

		lingocanvas_test_wp(
			array(
				'did_action'         => array( 'wp' => 0, 'wp_template_enhancement_output_buffer_started' => 0 ),
				'has_action'         => array( 'wp_before_include_template' => true ),
				'core_should_buffer' => false,
			)
		);

		$buffer = $this->buffer();
		$buffer->wrapTemplate( '/theme/index.php' );
		$buffer->verifyCoreBuffer();

		$this->assertSame( '', (string) file_get_contents( $log ) );
	}

	#[RunInSeparateProcess]
	public function testVerifyCoreBufferSilentWithoutCoreSupport(): void {
		$log = $this->captureLog();

		$buffer = $this->buffer();
		$buffer->wrapTemplate( '/theme/index.php' );
		$buffer->verifyCoreBuffer();

		$this->assertSame( '', (string) file_get_contents( $log ) );
	}

	#[RunInSeparateProcess]
	public function testCoreFilterNotAddedWithoutCoreFunction(): void {
		$this->assertFalse( function_exists( 'wp_start_template_enhancement_output_buffer' ) );

		$buffer = $this->buffer();
		$buffer->wrapTemplate( '/theme/index.php' );

		$this->assertArrayNotHasKey( 'wp_template_enhancement_output_buffer', $GLOBALS['lingocanvas_test_filters'] ?? array() );
		$this->assertFalse( $buffer->coreBufferActive() );
	}

	#[RunInSeparateProcess]
	public function testCoreFilterAddedOnceWhenCoreFunctionExists(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';

		$buffer = $this->buffer();
		$buffer->wrapTemplate( '/theme/index.php' );
		$buffer->wrapTemplate( '/theme/index.php' );

		$added = $GLOBALS['lingocanvas_test_filters']['wp_template_enhancement_output_buffer'] ?? array();

		$this->assertCount( 1, $added );
		$this->assertSame( array( $buffer, 'filterCoreBuffer' ), $added[0][0] );
	}

	#[RunInSeparateProcess]
	public function testCoreBufferActiveRequiresEveryCondition(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';

		$buffer = $this->buffer();
		$buffer->wrapTemplate( '/theme/index.php' );

		$ready = array(
			'did_action' => array(
				'wp'                                          => 0,
				'wp_template_enhancement_output_buffer_started' => 1,
			),
			'has_filter' => array( 'wp_template_enhancement_output_buffer' => true ),
		);

		// Ядро не открыло буфер: его обработчика нет в стеке.
		lingocanvas_test_wp( $ready );
		$this->assertFalse( $buffer->coreBufferActive() );

		ob_start( 'wp_finalize_template_enhancement_output_buffer' );
		$this->assertTrue( $buffer->coreBufferActive() );

		// Другой плагин снял наш фильтр.
		lingocanvas_test_wp( array_merge( $ready, array( 'has_filter' => array() ) ) );
		$this->assertFalse( $buffer->coreBufferActive() );

		// Ядро не стартовало буфер.
		lingocanvas_test_wp( array_merge( $ready, array( 'did_action' => array( 'wp' => 0, 'wp_template_enhancement_output_buffer_started' => 0 ) ) ) );
		$this->assertFalse( $buffer->coreBufferActive() );

		ob_end_clean();
	}

	#[RunInSeparateProcess]
	public function testFilterCoreBufferTranslatesStringsOnly(): void {
		$buffer = $this->buffer();

		$this->assertSame( '<!doctype html><p>Привет</p>', $buffer->filterCoreBuffer( '<!doctype html><p>Hello</p>' ) );
		$this->assertNull( $buffer->filterCoreBuffer( null ) );
	}

	#[RunInSeparateProcess]
	public function testHandleBufferSkipsTranslationOnCleanPhase(): void {
		$buffer = $this->buffer();
		$html   = '<!doctype html><p>Hello</p>';

		$this->assertSame( $html, $buffer->handleBuffer( $html, PHP_OUTPUT_HANDLER_CLEAN ) );
		$this->assertSame( $html, $buffer->handleBuffer( $html, PHP_OUTPUT_HANDLER_CLEAN | PHP_OUTPUT_HANDLER_START ) );
		$this->assertSame( 0, Translator::$calls );
		$this->assertSame( '<!doctype html><p>Привет</p>', $buffer->handleBuffer( $html, PHP_OUTPUT_HANDLER_FINAL ) );
	}

	#[RunInSeparateProcess]
	public function testProcessReturnsOriginalWhenTranslatorThrows(): void {
		$html = '<!doctype html><p>Hello THROW</p>';

		$this->assertSame( $html, $this->buffer()->process( $html ) );
		$this->assertSame( 1, Translator::$calls );
	}

	#[RunInSeparateProcess]
	public function testProcessLeavesNonHtmlAlone(): void {
		$buffer = $this->buffer();

		$this->assertSame( '{"a":"Hello"}', $buffer->process( '{"a":"Hello"}' ) );
		$this->assertSame( '   ', $buffer->process( '   ' ) );
		$this->assertSame( 0, Translator::$calls );
	}

	#[RunInSeparateProcess]
	public function testProcessLeavesFeedAlone(): void {
		lingocanvas_test_wp(
			array(
				'did_action' => array( 'wp' => 1 ),
				'is_feed'    => true,
			)
		);

		$html = '<!doctype html><p>Hello</p>';

		$this->assertSame( $html, $this->buffer()->process( $html ) );
		$this->assertSame( 0, Translator::$calls );
	}

	#[RunInSeparateProcess]
	public function testWrapperTranslatesTemplateAndKeepsBufferLevel(): void {
		$template = $this->template( '<!doctype html><html><body><p>Hello</p></body></html>' );
		$buffer   = $this->wrap( $template );

		ob_start();
		$level = ob_get_level();

		include (string) $buffer->wrapTemplate( $template );

		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( '<!doctype html><html><body><p>Привет</p></body></html>', ob_get_clean() );
		$this->assertSame( 1, Translator::$calls );
	}

	#[RunInSeparateProcess]
	public function testWrapperClosesBufferLeftOpenByTemplate(): void {
		$template = $this->template( '<?php ob_start(); echo "<!doctype html><html><body><p>Hello</p>"; ob_start(); echo "<i>Hello</i></body></html>";' );
		$buffer   = $this->wrap( $template );

		ob_start();
		$level = ob_get_level();

		include (string) $buffer->wrapTemplate( $template );

		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( '<!doctype html><html><body><p>Привет</p><i>Привет</i></body></html>', ob_get_clean() );
		$this->assertSame( 1, Translator::$calls );
	}

	#[RunInSeparateProcess]
	public function testWrapperDoesNotTouchBufferClosedByTemplateItself(): void {
		// Шаблон закрыл и наш буфер: вывод уходит наружу как есть, чужие буферы целы.
		$template = $this->template( '<?php echo "plain"; ob_end_flush();' );
		$buffer   = $this->wrap( $template );

		ob_start();
		$level = ob_get_level();

		include (string) $buffer->wrapTemplate( $template );

		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( 'plain', ob_get_clean() );
	}

	#[RunInSeparateProcess]
	public function testWrapperSkipsOwnBufferWhenCoreBufferActive(): void {
		require_once __DIR__ . '/../Doubles/core_buffer_functions.php';

		$template = $this->template( '<!doctype html><html><body><p>Hello</p></body></html>' );
		$buffer   = $this->wrap( $template );

		lingocanvas_test_wp(
			array(
				'did_action' => array(
					'wp'                                          => 0,
					'wp_template_enhancement_output_buffer_started' => 1,
				),
				'has_filter' => array( 'wp_template_enhancement_output_buffer' => true ),
			)
		);

		ob_start();
		ob_start( 'wp_finalize_template_enhancement_output_buffer' );
		$level = ob_get_level();

		include (string) $buffer->wrapTemplate( $template );

		// Свой буфер не открывался, перевод оставлен штатному (в заглушке он не вызывается).
		$this->assertSame( $level, ob_get_level() );
		ob_end_flush();
		$this->assertSame( '<!doctype html><html><body><p>Hello</p></body></html>', ob_get_clean() );
		$this->assertSame( 0, Translator::$calls );
	}

	#[RunInSeparateProcess]
	public function testWrapperIncludesNothingWithoutActiveBuffer(): void {
		ob_start();

		include self::WRAPPER;

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Включает WP_DEBUG и направляет error_log() во временный файл.
	 *
	 * Вызывается только в отдельном процессе: константу нельзя определить заново.
	 *
	 * @return string Путь к файлу лога.
	 */
	private function captureLog(): string {
		$path = tempnam( sys_get_temp_dir(), 'lclog' );
		file_put_contents( $path, '' );
		$this->files[] = $path;

		if ( ! defined( 'WP_DEBUG' ) ) {
			define( 'WP_DEBUG', true );
		}

		ini_set( 'error_log', $path ); // phpcs:ignore

		return $path;
	}

	private function buffer(): OutputBuffer {
		$settings = new Settings();

		return new OutputBuffer( new LanguageResolver( $settings ), new Translator() );
	}

	/**
	 * Буфер, у которого шаблон уже подменён (как после template_include).
	 */
	private function wrap( string $template ): OutputBuffer {
		$buffer = $this->buffer();
		$buffer->wrapTemplate( $template );

		return $buffer;
	}

	/**
	 * Создаёт временный файл шаблона.
	 *
	 * @param string $content Содержимое файла.
	 */
	private function template( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'lcbuf' );
		file_put_contents( $path, $content );
		$this->files[] = $path;

		return $path;
	}
}
