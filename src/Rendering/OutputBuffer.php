<?php
/**
 * Перехват готового HTML страницы.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Rendering;

use Throwable;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Support\FrontendRequest;
use LingoCanvas\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Переводит HTML шаблона страницы на дополнительных языках (ТЗ 4.3).
 *
 * Перевод через `the_content` не подошёл бы — он не покрывает меню, шапку,
 * подвал, виджеты, формы и SEO-теги в `wp_head`. Всё это выводит файл
 * шаблона, поэтому перехватывается именно его вывод.
 *
 * Жизненный цикл:
 * 1. `template_include` (последним): на WordPress 6.9+ вешается фильтр
 *    штатного буфера `wp_template_enhancement_output_buffer`. Если ядро этот
 *    буфер откроет, шаблон не трогается — перевод сделает ядро.
 * 2. Иначе (WordPress 6.4–6.8 или штатный буфер отключён другим плагином)
 *    шаблон запоминается и подменяется обёрткой `template-buffer.php`.
 * 3. `wp_before_include_template` (ядро 6.9+, приоритет 1000): ядро
 *    открывает свой буфер. Обёртка выполняется уже после этого и знает
 *    решение точно: штатный буфер работает и наш фильтр на месте — просто
 *    подключает шаблон; иначе открывает свой буфер, подключает шаблон и
 *    закрывает буфер — всё в одном файле. Так страница не переводится
 *    дважды, а если ожидаемый штатный буфер не стартовал, это пишется в
 *    debug.log.
 */
final class OutputBuffer implements Hookable {

	/**
	 * Экземпляр, подменивший шаблон текущего запроса; читает обёртка.
	 */
	private static ?self $active = null;

	/**
	 * Шаблон, выбранный WordPress и другими плагинами до подмены.
	 */
	private string $template = '';

	/**
	 * Подписан ли перевод на фильтр штатного буфера ядра 6.9+.
	 */
	private bool $coreFilterAdded = false;

	/**
	 * @param LanguageResolver $resolver   Язык текущего запроса.
	 * @param Translator       $translator Перевод HTML.
	 */
	public function __construct(
		private readonly LanguageResolver $resolver,
		private readonly Translator $translator
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		/*
		 * Фильтр шаблона вешается как можно позже — на последнем шаге
		 * `template_redirect`: из колбэков с тем же приоритетом PHP_INT_MAX
		 * последним выполняется добавленный последним, и чужой фильтр не
		 * заменит обёртку своим шаблоном.
		 */
		add_action( 'template_redirect', array( $this, 'hookTemplate' ), PHP_INT_MAX );
		add_action( 'wp_before_include_template', array( $this, 'verifyCoreBuffer' ), PHP_INT_MAX );
	}

	/**
	 * Вешает фильтр `template_include` последним в цепочке.
	 */
	public function hookTemplate(): void {
		add_filter( 'template_include', array( $this, 'wrapTemplate' ), PHP_INT_MAX );
	}

	/**
	 * Экземпляр, подменивший шаблон текущего запроса, или null.
	 */
	public static function active(): ?self {
		return self::$active;
	}

	/**
	 * Фильтр `template_include`: подменяет шаблон обёрткой, если страницу надо переводить.
	 *
	 * @param mixed $template Путь к шаблону.
	 * @return mixed
	 */
	public function wrapTemplate( $template ) {
		if ( ! is_string( $template ) || '' === $template || ! $this->shouldBuffer() ) {
			return $template;
		}

		// Ядро 6.9+ откроет штатный буфер, только если фильтр добавлен до wp_before_include_template.
		if ( function_exists( 'wp_start_template_enhancement_output_buffer' ) && ! $this->coreFilterAdded ) {
			add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'filterCoreBuffer' ) );
			$this->coreFilterAdded = true;
		}

		/*
		 * Штатный буфер откроется — шаблон не подменяем: глобальная
		 * `$template` остаётся путём к шаблону темы, а перевод не зависит от
		 * того, что сделают с шаблоном другие плагины.
		 */
		if ( $this->coreBufferWillStart() ) {
			return $template;
		}

		$this->template = $template;
		self::$active   = $this;

		return __DIR__ . '/template-buffer.php';
	}

	/**
	 * Откроет ли ядро 6.9+ штатный буфер на `wp_before_include_template`.
	 *
	 * То же условие, что проверяет само ядро; отключение буфера другим
	 * плагином через фильтр или снятие действия ядра здесь уже видно.
	 */
	private function coreBufferWillStart(): bool {
		return $this->coreFilterAdded
			&& function_exists( 'wp_should_output_buffer_template_for_enhancement' )
			&& false !== has_action( 'wp_before_include_template', 'wp_start_template_enhancement_output_buffer' )
			&& wp_should_output_buffer_template_for_enhancement();
	}

	/**
	 * Действие `wp_before_include_template` (ядро 6.9+), последним.
	 *
	 * Если штатный буфер ожидался, но не стартовал (его отключили уже после
	 * выбора шаблона), страница уйдёт непереведённой — это пишется в
	 * debug.log, а не проходит молча.
	 */
	public function verifyCoreBuffer(): void {
		if ( ! $this->coreFilterAdded || null !== self::$active || $this->coreBufferActive() ) {
			return;
		}

		$this->log( new \RuntimeException( 'WordPress template enhancement output buffer did not start; the page is not translated.' ) );
	}

	/**
	 * Исходный шаблон, который подключает обёртка.
	 */
	public function template(): string {
		return $this->template;
	}

	/**
	 * Переведёт ли страницу штатный буфер ядра 6.9+.
	 *
	 * Проверяется по факту, уже после решения ядра: буфер стартовал, ещё
	 * открыт и наш фильтр на нём не снят. Иначе обёртка открывает свой.
	 */
	public function coreBufferActive(): bool {
		return $this->coreFilterAdded
			&& did_action( 'wp_template_enhancement_output_buffer_started' ) > 0
			&& false !== has_filter( 'wp_template_enhancement_output_buffer', array( $this, 'filterCoreBuffer' ) )
			&& in_array( 'wp_finalize_template_enhancement_output_buffer', ob_list_handlers(), true );
	}

	/**
	 * Фильтр `wp_template_enhancement_output_buffer` (ядро 6.9+).
	 *
	 * Вызывается внутри обработчика буфера: открывать здесь буферы нельзя.
	 *
	 * @param mixed $html HTML шаблона.
	 * @return mixed
	 */
	public function filterCoreBuffer( $html ) {
		return is_string( $html ) ? $this->process( $html ) : $html;
	}

	/**
	 * Обработчик собственного буфера обёртки.
	 *
	 * При очистке буфера (страницу заменяют, например, страницей ошибки)
	 * вывод отбрасывается — переводить его незачем.
	 *
	 * @param mixed $buffer Накопленный вывод.
	 * @param int   $phase  Фаза PHP_OUTPUT_HANDLER_*.
	 */
	public function handleBuffer( $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL ): string {
		$buffer = (string) $buffer;

		if ( 0 !== ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
			return $buffer;
		}

		return $this->process( $buffer );
	}

	/**
	 * Обрабатывает готовый ответ.
	 *
	 * Любая ошибка разбора или перевода означает возврат исходного HTML:
	 * сломанная страница хуже непереведённой (ТЗ 12.1, 17).
	 *
	 * @param string $buffer Готовый ответ.
	 */
	public function process( string $buffer ): string {
		if ( ! $this->looksLikeHtml( $buffer ) ) {
			return $buffer;
		}

		try {
			$translated = $this->translator->translateHtml( $buffer, $this->resolver->current() );
		} catch ( Throwable $error ) {
			$this->log( $error );

			return $buffer;
		}

		return null !== $translated ? $translated : $buffer;
	}

	/**
	 * Нужно ли вообще переводить страницу.
	 *
	 * Условие «служебный это запрос или обычная страница» общее с подменой
	 * локали (см. Support\FrontendRequest): если они разойдутся, страница
	 * получится полупереведённой — строки темы на одном языке, текст на
	 * другом.
	 */
	private function shouldBuffer(): bool {
		if ( $this->resolver->isDefault() ) {
			return false;
		}

		return FrontendRequest::isPublicRender();
	}

	/**
	 * Похож ли буфер на HTML-документ.
	 *
	 * Шаблон может отдать и не HTML (JSON, XML, файл): разбор HTML-парсером
	 * только испортил бы такой ответ.
	 *
	 * @param string $buffer Готовый ответ.
	 */
	private function looksLikeHtml( string $buffer ): bool {
		if ( '' === trim( $buffer ) ) {
			return false;
		}

		// Условные теги трогаем только после `wp`: раньше $wp_query ещё нет,
		// и обращение к нему подняло бы _doing_it_wrong в debug.log.
		if ( did_action( 'wp' ) && ( is_feed() || is_robots() ) ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 !== stripos( $header, 'content-type:' ) ) {
				continue;
			}

			return false !== stripos( $header, 'text/html' );
		}

		// Заголовок не выставлен явно — судим по началу ответа.
		$head = ltrim( substr( $buffer, 0, 512 ) );

		return 1 === preg_match( '/^<(!doctype\s+html|html)/i', $head );
	}

	/**
	 * Пишет ошибку в debug.log, не мешая работе сайта.
	 *
	 * @param Throwable $error Исключение.
	 */
	private function log( Throwable $error ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[lingocanvas] Page translation skipped: %s in %s:%d',
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			)
		);
	}
}
