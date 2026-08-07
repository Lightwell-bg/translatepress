<?php
/**
 * Виджет переключателя языков.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WP_Widget;
use WpMlp\Plugin;

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
		parent::__construct(
			'mlp_language_switcher',
			__( 'Переключатель языков', 'wp-mlp' ),
			array( 'description' => __( 'Ссылки на текущую страницу на других языках сайта.', 'wp-mlp' ) )
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
				<?php esc_html_e( 'Заголовок:', 'wp-mlp' ); ?>
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
				<?php esc_html_e( 'Скрыть текущий язык', 'wp-mlp' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" <?php checked( ! empty( $instance['as_dropdown'] ) ); ?>
					name="<?php echo esc_attr( $this->get_field_name( 'as_dropdown' ) ); ?>" value="1">
				<?php esc_html_e( 'Выпадающим списком', 'wp-mlp' ); ?>
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
