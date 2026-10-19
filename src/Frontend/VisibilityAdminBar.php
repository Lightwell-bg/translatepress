<?php
/**
 * Плашка в админ-панели сайта: показана ли страница на этом языке.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Frontend;

use LingoCanvas\Admin\EditorPage;
use LingoCanvas\Rest\PostVisibilityController;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Support\Assets;
use LingoCanvas\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Владелец сайта, открыв запись на дополнительном языке, видит в админ-панели
 * «Скрыто на DE — посетители не видят» и кнопку «Показать на DE» (или
 * «Показано на DE» и «Скрыть на DE»). Страницу при этом открывает без
 * редиректа (PostVisibilityGate::editorSeesHiddenPost()), чтобы было что
 * проверить, прежде чем показывать её посетителям.
 *
 * Появляется только в ручном режиме, на странице одной записи на
 * дополнительном языке, у того, кто может править эту запись, и только
 * когда админ-панель вообще показана.
 */
final class VisibilityAdminBar implements Hookable {

	/** Идентификатор верхнего узла админ-панели. */
	public const NODE_ID = 'wp-mlp-visibility';

	/** Идентификатор кнопки внутри узла. */
	public const TOGGLE_ID = 'wp-mlp-visibility-toggle';

	/**
	 * Как узнать запись текущей страницы; подменяется в тестах.
	 *
	 * @var callable|null
	 */
	private $postIdProvider;

	/**
	 * @param Settings               $settings   Настройки плагина.
	 * @param LanguageResolver       $resolver   Язык текущего запроса.
	 * @param PostLanguageVisibility $visibility Флаги видимости записей.
	 * @param callable|null         $provider   Колбэк `fn(): int` — запись текущей страницы; по умолчанию по условным тегам WordPress.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguageResolver $resolver,
		private readonly PostLanguageVisibility $visibility,
		?callable $provider = null
	) {
		$this->postIdProvider = $provider;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// После кнопки «Перевести страницу» (приоритет 90).
		add_action( 'admin_bar_menu', array( $this, 'addNodes' ), 95 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Данные страницы: [запись, язык, показана ли] либо null, если плашка не нужна.
	 *
	 * @return array{0: int, 1: string, 2: bool}|null
	 */
	public function context(): ?array {
		if ( is_admin() || ! $this->settings->isManualPostVisibility() || $this->resolver->isDefault() || $this->resolver->isUnavailable() ) {
			return null;
		}

		if ( ! function_exists( 'is_admin_bar_showing' ) || ! is_admin_bar_showing() ) {
			return null;
		}

		$postId = null !== $this->postIdProvider ? (int) ( $this->postIdProvider )() : CurrentPageLanguages::queriedPostId();

		if ( $postId <= 0 || ! $this->visibility->isEligiblePost( $postId ) || ! current_user_can( 'edit_post', $postId ) || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return null;
		}

		$locale = $this->resolver->currentLocale();

		if ( ! isset( $this->settings->secondary()[ $locale ] ) ) {
			return null;
		}

		return array( $postId, $locale, $this->visibility->isVisible( $postId, $locale ) );
	}

	/**
	 * Добавляет узел и кнопку в админ-панель.
	 *
	 * @param \WP_Admin_Bar $bar Админ-панель.
	 */
	public function addNodes( $bar ): void {
		$context = $this->context();

		if ( null === $context || ! is_object( $bar ) ) {
			return;
		}

		list( , $locale, $visible ) = $context;

		$code = strtoupper( $locale );

		$bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => esc_html(
					$visible
						? sprintf( /* translators: %s: language code. */ __( 'Shown on %s', 'lingocanvas' ), $code )
						: sprintf( /* translators: %s: language code. */ __( 'Hidden on %s: visitors cannot see it', 'lingocanvas' ), $code )
				),
				'meta'  => array( 'class' => $visible ? 'mlp-vis-shown' : 'mlp-vis-hidden' ),
			)
		);

		$bar->add_node(
			array(
				'id'     => self::TOGGLE_ID,
				'parent' => self::NODE_ID,
				'title'  => esc_html(
					$visible
						? sprintf( /* translators: %s: language code. */ __( 'Hide on %s', 'lingocanvas' ), $code )
						: sprintf( /* translators: %s: language code. */ __( 'Show on %s', 'lingocanvas' ), $code )
				),
				'href'   => '#mlp-visibility',
			)
		);
	}

	/**
	 * Подключает скрипт кнопки и подкрашивает плашку скрытой страницы.
	 */
	public function enqueue(): void {
		$context = $this->context();

		if ( null === $context ) {
			return;
		}

		list( $postId, $locale, $visible ) = $context;

		wp_enqueue_script(
			'lingocanvas-adminbar-visibility',
			Assets::url( 'assets/adminbar-visibility.js' ),
			array(),
			Assets::version( 'assets/adminbar-visibility.js' ),
			true
		);
		wp_localize_script(
			'lingocanvas-adminbar-visibility',
			'lingocanvasAdminBarVisibility',
			array(
				'url'     => esc_url_raw( rest_url( PostVisibilityController::NAMESPACE . '/posts/visibility' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'postId'  => $postId,
				'locale'  => $locale,
				'visible' => $visible,
				'nodeId'  => self::TOGGLE_ID,
				'i18n'    => array(
					'saving' => __( 'Saving…', 'lingocanvas' ),
					'failed' => __( 'Could not save', 'lingocanvas' ),
				),
			)
		);

		if ( ! $visible ) {
			wp_add_inline_style( 'admin-bar', '#wpadminbar .mlp-vis-hidden > .ab-item{background:#b32d2e !important;color:#fff !important}' );
		}
	}
}
