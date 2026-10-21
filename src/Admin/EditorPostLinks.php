<?php
/**
 * Ссылки на визуальный редактор в списках записей и на экране правки.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Действие «Перевести» в строке списка и боковой метабокс «Перевод».
 *
 * Боковые метабоксы показываются и в сайдбаре Гутенберга, так что отдельный
 * плагин для блочного редактора не нужен.
 */
final class EditorPostLinks implements Hookable {

	/** Действие nonce метабокса «Показывать на сайте». */
	public const NONCE_ACTION = 'lingocanvas_post_visibility';

	/** Имя поля с nonce. */
	public const NONCE_FIELD = 'lingocanvas_post_visibility_nonce';

	/** Имя массива чекбоксов `lingocanvas_visible[<язык>]`. */
	public const FIELD = 'lingocanvas_visible';

	/**
	 * @param Settings                    $settings   Настройки плагина.
	 * @param EditorLink                  $link       Пути и адреса редактора.
	 * @param PostLanguageVisibility|null $visibility Флаги «показывать на языке»; без них блока показа в метабоксе нет.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly EditorLink $link,
		private readonly ?PostLanguageVisibility $visibility = null
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ), 10, 2 );
		add_action( 'save_post', array( $this, 'saveVisibility' ), 10, 2 );
	}

	/**
	 * Сохраняет чекбоксы «Показывать на сайте» из метабокса.
	 *
	 * Срабатывает на любом сохранении записи, поэтому всё отсеивается заранее:
	 * автосохранение и ревизия, отсутствие nonce метабокса (быстрое
	 * редактирование, REST-сохранение блочного редактора, импорт), нехватка
	 * прав, не ручной режим, неподходящий тип. Снятый чекбокс не приходит
	 * вовсе — отсутствие языка в `lingocanvas_visible` означает «скрыть».
	 *
	 * @param int|mixed      $postId Запись.
	 * @param \WP_Post|mixed $post   Запись целиком (может не прийти).
	 */
	public function saveVisibility( $postId, $post = null ): void {
		$postId = (int) $postId;

		if ( $postId <= 0 || null === $this->visibility || ! $this->settings->isManualPostVisibility() ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
			return;
		}

		// Права проверяются раньше, чем читается любое поле запроса.
		if ( ! current_user_can( 'edit_post', $postId ) || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce проверяется ниже, до чтения остальных полей.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! $this->visibility->isEligiblePost( $postId ) ) {
			return;
		}

		$posted = array();

		if ( isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- каждый ключ и значение санируются в цикле ниже.
			foreach ( wp_unslash( $_POST[ self::FIELD ] ) as $postedLocale => $postedFlag ) {
				$posted[ sanitize_text_field( (string) $postedLocale ) ] = ! empty( $postedFlag );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		unset( $post );

		foreach ( $this->settings->secondary() as $language ) {
			$this->visibility->set( $postId, $language->locale, ! empty( $posted[ $language->locale ] ) );
		}
	}

	/**
	 * Добавляет «Перевести» в действия строки списка записей.
	 *
	 * @param array<string, string> $actions Действия строки.
	 * @param \WP_Post|mixed        $post    Запись строки.
	 * @return array<string, string>
	 */
	public function rowActions( $actions, $post = null ) {
		if ( ! is_array( $actions ) || ! $post instanceof \WP_Post || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return $actions;
		}

		$url = $this->link->urlForPost( $post );

		if ( null === $url ) {
			return $actions;
		}

		$actions['lingocanvas_translate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Translate', 'lingocanvas' )
		);

		return $actions;
	}

	/**
	 * Регистрирует метабокс на всех публичных типах, кроме вложений.
	 *
	 * @param string|mixed $postType Тип записи текущего экрана.
	 */
	public function addMetaBox( $postType = '' ): void {
		if ( ! is_string( $postType ) || 'attachment' === $postType || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return;
		}

		$type = get_post_type_object( $postType );

		if ( null === $type || empty( $type->public ) ) {
			return;
		}

		add_meta_box(
			'wp-mlp-translate',
			__( 'Translation', 'lingocanvas' ),
			array( $this, 'renderMetaBox' ),
			$postType,
			'side',
			'default'
		);
	}

	/**
	 * Содержимое метабокса.
	 *
	 * @param \WP_Post|mixed $post Редактируемая запись.
	 */
	public function renderMetaBox( $post ): void {
		if ( ! $post instanceof \WP_Post || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return;
		}

		$secondary = $this->settings->secondary();

		if ( null === $this->link->pathForPost( $post ) ) {
			printf( '<p>%s</p>', esc_html__( 'Publish the post to translate it in the visual editor.', 'lingocanvas' ) );

			$this->renderVisibility( $post );

			return;
		}

		if ( array() === $secondary ) {
			printf( '<p>%s</p>', esc_html__( 'First add at least one secondary language on the "Languages" page.', 'lingocanvas' ) );

			return;
		}

		echo '<ul>';

		foreach ( $secondary as $language ) {
			$url = $this->link->urlForPost( $post, $language->locale );

			if ( null === $url ) {
				continue;
			}

			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( $url ),
				esc_html( sprintf( '%s — %s', __( 'Open in the visual editor', 'lingocanvas' ), $language->label ) )
			);
		}

		echo '</ul>';

		$this->renderVisibility( $post );
	}

	/**
	 * Блок «Показывать на сайте»: по чекбоксу на каждый дополнительный язык.
	 *
	 * Только в ручном режиме и только тем, кто может править запись. Чекбоксы
	 * уходят обычной формой записи и обрабатываются в saveVisibility().
	 *
	 * @param \WP_Post $post Редактируемая запись.
	 */
	private function renderVisibility( \WP_Post $post ): void {
		$secondary = $this->settings->secondary();

		if (
			null === $this->visibility
			|| ! $this->settings->isManualPostVisibility()
			|| array() === $secondary
			|| ! PostLanguageVisibility::isEligibleType( (string) $post->post_type )
			|| ! current_user_can( 'edit_post', (int) $post->ID )
			|| ! current_user_can( EditorPage::CAPABILITY )
		) {
			return;
		}

		$this->visibility->prime( array( (int) $post->ID ) );

		printf( '<hr><p><strong>%s</strong></p>', esc_html__( 'Show on the site', 'lingocanvas' ) );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<ul>';

		foreach ( $secondary as $language ) {
			printf(
				'<li><label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label></li>',
				esc_attr( self::FIELD ),
				esc_attr( $language->locale ),
				checked( $this->visibility->isVisible( (int) $post->ID, $language->locale ), true, false ),
				esc_html( $language->label )
			);
		}

		echo '</ul>';
		printf( '<p class="description">%s</p>', esc_html__( 'Check the languages in which this post is visible to visitors. In the other languages it is hidden: the link leads to the default-language version.', 'lingocanvas' ) );
	}
}
