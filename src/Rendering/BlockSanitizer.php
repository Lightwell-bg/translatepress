<?php
/**
 * Очистка разметки translation block.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Пропускает только безопасную инлайновую разметку (ТЗ 13).
 *
 * Содержимое блока приходит из браузера и подставляется в готовую страницу
 * как HTML, а не как текст. Это единственное место в плагине, где разметка
 * не экранируется, поэтому список разрешённых тегов задан явно и узко:
 * ни скриптов, ни обработчиков событий, ни атрибутов style.
 */
final class BlockSanitizer {

	/**
	 * Разрешённые теги и их атрибуты.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		$inline = array(
			'class' => true,
			'id'    => true,
			'lang'  => true,
			'dir'   => true,
			'title' => true,
		);

		return array(
			'a'      => $inline + array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'abbr'   => $inline,
			'b'      => $inline,
			'br'     => array(),
			'cite'   => $inline,
			'code'   => $inline,
			'del'    => $inline,
			'em'     => $inline,
			'i'      => $inline,
			'img'    => $inline + array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
				'srcset' => true,
				'sizes'  => true,
				'loading' => true,
			),
			'ins'    => $inline,
			'mark'   => $inline,
			'q'      => $inline,
			's'      => $inline,
			'small'  => $inline,
			'span'   => $inline,
			'strong' => $inline,
			'sub'    => $inline,
			'sup'    => $inline,
			'u'      => $inline,
		);
	}

	/**
	 * Очищает разметку блока.
	 *
	 * @param string $html Разметка из браузера.
	 */
	public static function sanitize( string $html ): string {
		$clean = wp_kses( $html, self::allowed() );

		// Служебные маркеры редактора в словарь попадать не должны.
		$clean = (string) preg_replace( '/\s*data-mlp-[\w-]+="[^"]*"/', '', $clean );
		$clean = (string) preg_replace( '/\s*class="mlp-marker"/', '', $clean );

		return trim( (string) preg_replace( '/\s+/u', ' ', $clean ) );
	}
}
