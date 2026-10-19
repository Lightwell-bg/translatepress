<?php
/**
 * Виджет переключателя языков.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Frontend;

use WP_Widget;
use LingoCanvas\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Классический виджет: работает и в «Виджетах», и в блочном редакторе через
 * блок «Классический виджет». Собственный блок Gutenberg потребовал бы сборки
 * JS, а на Этапе 1 это лишнее.
 */
final class LanguageSwitcherWidget extends WP_Widget {

	/**
	 * Регистрирует виджет в WordPress.
	 */
	public function __construct() {
		// id_base входит в имя опции widget_<id_base> и в sidebars_widgets: менять его нельзя.
		parent::__construct(
			'lingocanvas_language_switcher',
			__( 'Language switcher', 'lingocanvas' ),
			array( 'description' => __( 'Links to the current page in the other languages of the site.', 'lingocanvas' ) )
		);
	}

	/**
	 * Выводит виджет.
	 *
	 * @param array<string, string> $args     Обёртки темы.
	 * @param array<string, mixed>  $instance Настройки виджета.
	 */
	public function widget( $args, $instance ): void {
		$switcher = Plugin::container()->get( LanguageSwitcher::class );
		$html     = $switcher->render(
			empty( $instance['hide_current'] ),
			! empty( $instance['as_dropdown'] )
		);

		if ( '' === $html ) {
			return;
		}

		$title = apply_filters( 'widget_title', (string) ( $instance['title'] ?? '' ), $instance, $this->id_base );

		echo wp_kses_post( (string) ( $args['before_widget'] ?? '' ) );

		if ( '' !== $title ) {
			echo wp_kses_post( (string) ( $args['before_title'] ?? '' ) . $title . (string) ( $args['after_title'] ?? '' ) );
		}

		echo wp_kses(
			$html,
			array(
				'ul'     => array( 'class' => array() ),
				'li'     => array( 'class' => array() ),
				'a'      => array(
					'href'         => array(),
					'lang'         => array(),
					'hreflang'     => array(),
					'aria-current' => array(),
				),
				'select' => array(
					'class'      => array(),
					'aria-label' => array(),
					'onchange'   => array(),
				),
				'option' => array(
					'value'    => array(),
					'lang'     => array(),
					'selected' => array(),
				),
			)
		);

		echo wp_kses_post( (string) ( $args['after_widget'] ?? '' ) );
	}

	/**
	 * Форма настроек виджета.
	 *
	 * @param array<string, mixed> $instance Текущие настройки.
	 */
	public function form( $instance ): string {
		$title = (string) ( $instance['title'] ?? '' );

		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'lingocanvas' ); ?>
			</label>
			<input class="widefat" type="text"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label>
				<input type="checkbox" <?php checked( ! empty( $instance['hide_current'] ) ); ?>
					name="<?php echo esc_attr( $this->get_field_name( 'hide_current' ) ); ?>" value="1">
				<?php esc_html_e( 'Hide the current language', 'lingocanvas' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" <?php checked( ! empty( $instance['as_dropdown'] ) ); ?>
					name="<?php echo esc_attr( $this->get_field_name( 'as_dropdown' ) ); ?>" value="1">
				<?php esc_html_e( 'Show as a dropdown', 'lingocanvas' ); ?>
			</label>
		</p>
		<?php

		return '';
	}

	/**
	 * Сохраняет настройки виджета.
	 *
	 * @param array<string, mixed> $new_instance Новые значения.
	 * @param array<string, mixed> $old_instance Прежние значения.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		unset( $old_instance );

		return array(
			'title'        => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
			'hide_current' => ! empty( $new_instance['hide_current'] ),
			'as_dropdown'  => ! empty( $new_instance['as_dropdown'] ),
		);
	}
}
