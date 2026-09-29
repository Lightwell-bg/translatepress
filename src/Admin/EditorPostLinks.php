<?php
/**
 * Ссылки на визуальный редактор в списках записей и на экране правки.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * Действие «Перевести» в строке списка и боковой метабокс «Перевод».
 *
 * Боковые метабоксы показываются и в сайдбаре Гутенберга, так что отдельный
 * плагин для блочного редактора не нужен.
 */
final class EditorPostLinks implements Hookable {

	/**
	 * @param Settings   $settings Настройки плагина.
	 * @param EditorLink $link     Пути и адреса редактора.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly EditorLink $link
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'addMetaBox' ), 10, 2 );
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

		$actions['mlp_translate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Перевести', 'wp-mlp' )
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
			__( 'Перевод', 'wp-mlp' ),
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

		if ( null === $this->link->pathForPost( $post ) ) {
			printf( '<p>%s</p>', esc_html__( 'Опубликуйте запись, чтобы перевести её в визуальном редакторе.', 'wp-mlp' ) );

			return;
		}

		$secondary = $this->settings->secondary();

		if ( array() === $secondary ) {
			printf( '<p>%s</p>', esc_html__( 'Сначала добавьте хотя бы один дополнительный язык на странице «Языки».', 'wp-mlp' ) );

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
				esc_html( sprintf( '%s — %s', __( 'Открыть в визуальном редакторе', 'wp-mlp' ), $language->label ) )
			);
		}

		echo '</ul>';
	}
}
