<?php
/**
 * Списки разрешённых тегов для wp_kses в админке.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Каждый билдер разметки в админке уже экранирует свои данные, а здесь
 * описано, какие теги и атрибуты он вообще может выдать. Вывод пропускается
 * через wp_kses с этим списком прямо в точке echo: всё, чего билдер не
 * обещал, будет вырезано. Тесты сверяют списки с реальной разметкой.
 */
final class AllowedHtml {

	/**
	 * Переключатель показа записи на языке (VisibilityAssets::toggleHtml).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function visibilityToggle(): array {
		return array(
			'label' => array(
				'class' => true,
				'title' => true,
			),
			'input' => array(
				'type'         => true,
				'class'        => true,
				'checked'      => true,
				'disabled'     => true,
				'data-post-id' => true,
				'data-locale'  => true,
			),
			'span'  => array(
				'class'       => true,
				'aria-hidden' => true,
				'role'        => true,
				'aria-live'   => true,
			),
		);
	}

	/**
	 * Блок показа на языке в визуальном редакторе (EditorPage::visibilityBlock).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function editorVisibilityBlock(): array {
		return array_merge(
			self::visibilityToggle(),
			array(
				'div' => array( 'class' => true ),
				'p'   => array( 'class' => true ),
				'hr'  => array(),
			)
		);
	}

	/**
	 * Ячейка колонки «Языки» в списке записей (PostListLanguages::cellHtml).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function postListCell(): array {
		$toggle = self::visibilityToggle();

		return array_merge(
			$toggle,
			array(
				'span'   => array_merge(
					$toggle['span'],
					array( 'title' => true )
				),
				'a'      => array(
					'href'  => true,
					'title' => true,
					'class' => true,
				),
				'img'    => array(
					'src'    => true,
					'alt'    => true,
					'width'  => true,
					'height' => true,
				),
				'strong' => array(),
				'em'     => array(),
				'br'     => array(),
			)
		);
	}

	/**
	 * Статус ключа API на странице настроек (SettingsPage::keyStatusHtml).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function keyStatus(): array {
		return array(
			'p'   => array( 'class' => true ),
			'div' => array( 'class' => true ),
		);
	}

	/**
	 * Раздел «LingoCanvas Cloud» на странице настроек (CloudSection::providerHtml, cloudHtml, formsHtml).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function cloudSection(): array {
		return array(
			'fieldset' => array(),
			'legend'   => array( 'class' => true ),
			'div'      => array(
				'class'                        => true,
				'id'                           => true,
				'data-lingocanvas-cloud-status' => true,
			),
			'p'        => array(
				'class'                         => true,
				'hidden'                        => true,
				'role'                          => true,
				'aria-live'                     => true,
				'data-lingocanvas-field'        => true,
				'data-lingocanvas-cloud-pack-note' => true,
			),
			'br'       => array(),
			'label'    => array( 'for' => true ),
			'input'    => array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'id'           => true,
				'class'        => true,
				'checked'      => true,
				'required'     => true,
				'form'         => true,
				'autocomplete' => true,
			),
			'button'   => array(
				'type'                              => true,
				'class'                             => true,
				'form'                              => true,
				'hidden'                            => true,
				'data-lingocanvas-confirm'          => true,
				'data-lingocanvas-cloud-refresh'    => true,
				'data-lingocanvas-cloud-pack'       => true,
				'data-lingocanvas-cloud-resubscribe' => true,
			),
			'span'     => array(
				'class'                  => true,
				'role'                   => true,
				'aria-live'              => true,
				'data-lingocanvas-field' => true,
			),
			'ul'       => array(
				'class'  => true,
				'hidden' => true,
			),
			'li'       => array(),
			'strong'   => array(),
			'a'        => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'form'     => array(
				'id'     => true,
				'method' => true,
				'action' => true,
			),
		);
	}

	/**
	 * Выбор области на странице строк (StringTranslationPage::scopeControlHtml).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function scopeControl(): array {
		return array(
			'label'  => array(
				'for'   => true,
				'class' => true,
			),
			'select' => array(
				'name' => true,
				'id'   => true,
			),
			'option' => array(
				'value'                  => true,
				'selected'               => true,
				'data-lingocanvas-object' => true,
			),
			'span'   => array(
				'class'  => true,
				'id'     => true,
				'hidden' => true,
			),
			'input'  => array(
				'type'          => true,
				'id'            => true,
				'autocomplete'  => true,
				'role'          => true,
				'aria-expanded' => true,
				'aria-controls' => true,
				'value'         => true,
				'placeholder'   => true,
			),
			'ul'     => array(
				'class'  => true,
				'id'     => true,
				'role'   => true,
				'hidden' => true,
			),
		);
	}
}
