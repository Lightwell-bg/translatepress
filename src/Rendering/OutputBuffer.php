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
 * Включает output buffering на дополнительных языках (ТЗ 4.3).
 *
 * Буфер ставится на раннем `init`: к этому моменту тема ещё ничего не вывела,
 * но настройки и язык запроса уже известны. Перевод через `the_content` не
 * подошёл бы — он не покрывает меню, шапку, подвал, виджеты и формы.
 *
 * Закрывается буфер явно и парно: на `shutdown` с приоритетом 0 (раньше
 * `wp_ob_end_flush_all()` ядра с приоритетом 1) вызывается `finish()`, который
 * сбрасывает буферы, открытые поверх нашего, и затем сам наш буфер. Если наш
 * буфер к этому моменту уже закрыт кем-то другим, ничего не делается.
 */
final class OutputBuffer implements Hookable {

	/**
	 * Уровень вложенности нашего буфера сразу после ob_start(); 0 — не открыт.
	 */
	private int $level = 0;

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
		add_action( 'init', array( $this, 'start' ), 1 );
		// Приоритет 0: раньше wp_ob_end_flush_all() ядра (shutdown, приоритет 1).
		add_action( 'shutdown', array( $this, 'finish' ), 0 );
	}

	/**
	 * Ставит буфер, если запрос вообще подлежит переводу.
	 */
	public function start(): void {
		if ( ! $this->shouldBuffer() ) {
			return;
		}

		$this->open();
	}

	/**
	 * Открывает буфер и запоминает его уровень.
	 */
	private function open(): void {
		if ( 0 !== $this->level ) {
			return;
		}

		ob_start( array( $this, 'process' ) );

		$this->level = ob_get_level();
	}

	/**
	 * Закрывает наш буфер, отдавая обработанный ответ.
	 *
	 * Сначала сбрасываются буферы, открытые поверх нашего, затем он сам.
	 * Если наш буфер уже закрыт (или на его месте чужой), ничего не делается.
	 * Повторный вызов безопасен.
	 */
	public function finish(): void {
		$level = $this->level;

		if ( $level <= 0 ) {
			return;
		}

		$this->level = 0;

		if ( ob_get_level() < $level ) {
			return;
		}

		$handlers = ob_list_handlers();

		if ( ( $handlers[ $level - 1 ] ?? '' ) !== self::class . '::process' ) {
			return;
		}

		while ( ob_get_level() > $level ) {
			if ( ! ob_end_flush() ) {
				break;
			}
		}

		if ( ob_get_level() === $level ) {
			ob_end_flush();
		}
	}

	/**
	 * Обрабатывает накопленный ответ.
	 *
	 * Любая ошибка разбора или перевода означает возврат исходного HTML:
	 * сломанная страница хуже непереведённой (ТЗ 12.1, 17).
	 *
	 * @param string $buffer Готовый ответ.
	 * @return string
	 */
	public function process( $buffer ): string {
		$buffer = (string) $buffer;

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
	 * Нужно ли вообще включать буфер.
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
	 * Через тот же буфер проходят JSON, XML-фиды, sitemap и файлы: их разбор
	 * HTML-парсером только испортил бы ответ.
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
