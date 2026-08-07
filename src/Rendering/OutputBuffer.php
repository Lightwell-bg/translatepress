<?php
/**
 * Перехват готового HTML страницы.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

use Throwable;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Support\Hookable;

/**
 * Включает output buffering на дополнительных языках (ТЗ 4.3).
 *
 * Буфер ставится на раннем `init`: к этому моменту тема ещё ничего не вывела,
 * но настройки и язык запроса уже известны. Перевод через `the_content` не
 * подошёл бы — он не покрывает меню, шапку, подвал, виджеты и формы.
 */
final class OutputBuffer implements Hookable {

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
	}

	/**
	 * Ставит буфер, если запрос вообще подлежит переводу.
	 */
	public function start(): void {
		if ( ! $this->shouldBuffer() ) {
			return;
		}

		ob_start( array( $this, 'process' ) );
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
	 */
	private function shouldBuffer(): bool {
		if ( $this->resolver->isDefault() ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение сравнивается со списком.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		// POST-ответы это, как правило, редиректы и обработчики форм.
		return in_array( $method, array( 'GET', 'HEAD' ), true );
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

		if ( function_exists( 'is_feed' ) && ( is_feed() || is_robots() ) ) {
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
				'[wp-mlp] Перевод страницы пропущен: %s в %s:%d',
				$error->getMessage(),
				$error->getFile(),
				$error->getLine()
			)
		);
	}
}
