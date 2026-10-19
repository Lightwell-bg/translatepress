<?php
/**
 * Скрипт и стили переключателей «показывать на сайте» в админке.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Rest\PostVisibilityController;
use LingoCanvas\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Один скрипт на все места админки, где есть переключатель показа записи на
 * языке (список записей, визуальный редактор): он слушает клики на уровне
 * документа, поэтому работает и для строк, которые WordPress перерисовал
 * после быстрого редактирования.
 */
final class VisibilityAssets {

	/** Имя скрипта и стиля. */
	public const HANDLE = 'lingocanvas-post-visibility';

	/**
	 * Подключает скрипт и стиль переключателей.
	 */
	public static function enqueue(): void {
		wp_enqueue_style(
			self::HANDLE,
			Assets::url( 'assets/post-visibility.css' ),
			array(),
			Assets::version( 'assets/post-visibility.css' )
		);
		wp_enqueue_script(
			self::HANDLE,
			Assets::url( 'assets/post-visibility.js' ),
			array(),
			Assets::version( 'assets/post-visibility.js' ),
			true
		);
		wp_localize_script(
			self::HANDLE,
			'lingocanvasVisibility',
			array(
				'url'   => esc_url_raw( rest_url( PostVisibilityController::NAMESPACE . '/posts/visibility' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'saving'  => __( 'Saving…', 'lingocanvas' ),
					'failed'  => __( 'Could not save', 'lingocanvas' ),
					'network' => __( 'Cannot reach the site. Please try again.', 'lingocanvas' ),
				),
			)
		);
	}

	/**
	 * Разметка одного переключателя (чекбокс в виде выключателя). Чистая функция.
	 *
	 * @param int    $postId   Запись.
	 * @param string $locale   Код языка.
	 * @param bool   $visible  Включена ли запись на языке.
	 * @param string $text     Подпись справа от выключателя — обычная строка, экранируется здесь.
	 * @param string $disabled Причина, по которой переключатель недоступен; пустая строка — доступен.
	 */
	public static function toggleHtml( int $postId, string $locale, bool $visible, string $text, string $disabled = '' ): string {
		$title = '' !== $disabled ? $disabled : $text;

		return sprintf(
			'<label class="mlp-vis" title="%1$s"><input type="checkbox" class="mlp-vis-toggle" data-post-id="%2$d" data-locale="%3$s"%4$s%5$s><span class="mlp-vis__track" aria-hidden="true"></span><span class="mlp-vis__text">%6$s</span></label><span class="mlp-vis__msg" role="status" aria-live="polite"></span>',
			esc_attr( $title ),
			$postId,
			esc_attr( $locale ),
			$visible ? ' checked="checked"' : '',
			'' !== $disabled ? ' disabled="disabled"' : '',
			esc_html( $text )
		);
	}
}
