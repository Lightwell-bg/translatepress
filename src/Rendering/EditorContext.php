<?php
/**
 * Режим предпросмотра для визуального редактора.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Rendering;

use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Settings\Language;
use LingoCanvas\Support\Assets;
use LingoCanvas\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Решает, показывается ли страница внутри редактора (ТЗ 10.1–10.2).
 *
 * В обычном ответе служебных маркеров быть не должно, поэтому режим включается
 * только по явному параметру и только для пользователя с правами и валидным
 * nonce: в этом режиме страница синхронно пишет в БД новые найденные строки,
 * а значит это операция записи, а не просто чтение.
 */
final class EditorContext implements Hookable {

	public const QUERY_FLAG   = 'lingocanvas_editor';
	public const QUERY_NONCE  = 'lingocanvas_nonce';
	public const NONCE_ACTION = 'lingocanvas_editor_preview';
	public const CAPABILITY   = 'manage_options';

	/**
	 * Общий секрет панели и предпросмотра на одну загрузку редактора.
	 *
	 * Обе стороны сверяют его в каждом сообщении postMessage. Проверять
	 * только `event.origin` и строку-метку вида `lingocanvas-preview` мало:
	 * метка не секрет, её знает кто угодно, прочитавший этот файл, а
	 * происхождение у любого скрипта на той же странице админки такое же,
	 * как у нас. Токен же знают только те двое, между кем идёт разговор.
	 */
	public const QUERY_CHANNEL = 'lingocanvas_channel';

	/**
	 * Мемоизированный ответ isActive().
	 */
	private ?bool $active = null;

	/**
	 * @param LanguageResolver $resolver Язык текущего запроса.
	 */
	public function __construct( private readonly LanguageResolver $resolver ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		/*
		 * Проверка прав отложена до `init`: current_user_can() на
		 * plugins_loaded заставляет WordPress определить пользователя раньше,
		 * чем плагины авторизации успевают навесить свои фильтры.
		 */
		add_action( 'init', array( $this, 'setUp' ), 0 );
	}

	/**
	 * Включает режим редактора, если запрос ему соответствует.
	 */
	public function setUp(): void {
		if ( ! $this->isActive() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'sendHeaders' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// Админ-бар перекрывает вёрстку и сам попадает под клики редактора.
		add_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * Открыта ли страница в редакторе.
	 */
	public function isActive(): bool {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$this->active = $this->detect();

		return $this->active;
	}

	/**
	 * Предпросмотр не должен попадать ни в индекс, ни в кэш страниц.
	 */
	public function sendHeaders(): void {
		nocache_headers();

		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * Подключает скрипт предпросмотра.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'lingocanvas-editor-preview', Assets::url( 'assets/editor-preview.css' ), array(), Assets::version( 'assets/editor-preview.css' ) );
		wp_enqueue_script( 'lingocanvas-editor-preview', Assets::url( 'assets/editor-preview.js' ), array(), Assets::version( 'assets/editor-preview.js' ), true );

		wp_localize_script(
			'lingocanvas-editor-preview',
			'lingocanvasPreview',
			array(
				'flag'    => self::QUERY_FLAG,
				'nonce'   => self::QUERY_NONCE,
				'channel' => self::channelFromRequest(),
				'locale'  => $this->resolver->currentLocale(),
				'i18n'   => array(
					'blockHint' => __( 'Translate the whole paragraph', 'lingocanvas' ),
				),
			)
		);
	}

	/**
	 * Адрес страницы для показа внутри редактора.
	 *
	 * @param string $url Обычный языковой адрес страницы.
	 */
	public static function previewUrl( string $url, string $channel = '' ): string {
		$args = array(
			self::QUERY_FLAG  => '1',
			self::QUERY_NONCE => wp_create_nonce( self::NONCE_ACTION ),
		);

		if ( '' !== $channel ) {
			$args[ self::QUERY_CHANNEL ] = $channel;
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * Токен канала, с которым открыт этот предпросмотр.
	 *
	 * Значение приходит из адресной строки, поэтому пропускается только
	 * то, что могло быть выдано нами: буквы и цифры нужной длины. Всё
	 * остальное схлопывается в пустую строку — с ней предпросмотр просто
	 * не пройдёт сверку и промолчит, а не отправит сообщение с мусором.
	 */
	public static function channelFromRequest(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce режима редактора проверяется в detect().
		$raw = isset( $_GET[ self::QUERY_CHANNEL ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_CHANNEL ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 1 === preg_match( '/^[A-Za-z0-9]{16,64}$/', $raw ) ? $raw : '';
	}

	/**
	 * Язык, который редактируется.
	 */
	public function language(): Language {
		return $this->resolver->current();
	}

	/**
	 * Проверяет все условия включения режима.
	 */
	private function detect(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce проверяется ниже по коду.
		if ( ! isset( $_GET[ self::QUERY_FLAG ] ) ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		$nonce = isset( $_GET[ self::QUERY_NONCE ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_NONCE ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return false;
		}

		// Редактируется перевод, а не оригинал: на языке по умолчанию нечего править.
		return ! $this->resolver->isDefault();
	}
}
