<?php
/**
 * Переключатель языков.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * Шорткод `[mlp_language_switcher]` и одноимённый виджет.
 *
 * Ссылки ведут на ту же страницу на другом языке, вместе со строкой запроса:
 * с результатов поиска посетитель должен попасть на тот же поиск, а не на
 * главную. Черновые языки не показываются.
 */
final class LanguageSwitcher implements Hookable {

	public const SHORTCODE = 'mlp_language_switcher';

	/**
	 * @param Settings         $settings Настройки плагина.
	 * @param LanguageResolver $resolver Язык текущего запроса.
	 * @param UrlConverter     $urls     Построение языковых адресов.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguageResolver $resolver,
		private readonly UrlConverter $urls
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'widgets_init', array( $this, 'registerWidget' ) );
	}

	/**
	 * Регистрирует классический виджет.
	 */
	public function registerWidget(): void {
		register_widget( LanguageSwitcherWidget::class );
	}

	/**
	 * Обработчик шорткода.
	 *
	 * @param array<string, string>|string $atts Атрибуты шорткода.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'show_current' => 'yes',
				'type'         => 'list',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return $this->render(
			'no' !== strtolower( (string) $atts['show_current'] ),
			'dropdown' === strtolower( (string) $atts['type'] )
		);
	}

	/**
	 * Собирает разметку переключателя.
	 *
	 * @param bool $showCurrent Показывать ли текущий язык.
	 * @param bool $asDropdown  Отдать выпадающим списком вместо ссылок.
	 */
	public function render( bool $showCurrent = true, bool $asDropdown = false ): string {
		$languages = $this->settings->published();

		if ( count( $languages ) < 2 ) {
			return '';
		}

		$current = $this->resolver->current();
		$items   = array();

		foreach ( $languages as $language ) {
			$isCurrent = $language->locale === $current->locale;

			if ( $isCurrent && ! $showCurrent ) {
				continue;
			}

			$items[] = array(
				'url'     => $this->urls->switchUrlFor( $language ),
				'label'   => $language->labelWithFlag(),
				'lang'    => $language->bcp47(),
				'current' => $isCurrent,
			);
		}

		if ( array() === $items ) {
			return '';
		}

		return $asDropdown ? $this->renderDropdown( $items ) : $this->renderList( $items );
	}

	/**
	 * Список ссылок.
	 *
	 * @param list<array{url: string, label: string, lang: string, current: bool}> $items Языки.
	 */
	private function renderList( array $items ): string {
		$html = '<ul class="mlp-language-switcher">';

		foreach ( $items as $item ) {
			$html .= sprintf(
				/*
				 * Класс `mlp-language-item` на самой ссылке — не только на `<li>` —
				 * это маркер для InternalLinks::apply(): каждая ссылка здесь уже
				 * ведёт на свой язык, второй, независимый проход не должен её
				 * трогать (см. InternalLinks::SWITCHER_CLASS).
				 */
				'<li class="mlp-language-switcher__item%1$s"><a href="%2$s" class="mlp-language-item" lang="%3$s" hreflang="%3$s"%4$s>%5$s</a></li>',
				$item['current'] ? ' is-current' : '',
				esc_url( $item['url'] ),
				esc_attr( $item['lang'] ),
				$item['current'] ? ' aria-current="true"' : '',
				esc_html( $item['label'] )
			);
		}

		return $html . '</ul>';
	}

	/**
	 * Выпадающий список: переход выполняет крошечный инлайн-обработчик.
	 *
	 * @param list<array{url: string, label: string, lang: string, current: bool}> $items Языки.
	 */
	private function renderDropdown( array $items ): string {
		$html = sprintf(
			'<select class="mlp-language-switcher mlp-language-switcher--dropdown" aria-label="%s" onchange="if(this.value)window.location.href=this.value">',
			esc_attr__( 'Язык сайта', 'wp-mlp' )
		);

		foreach ( $items as $item ) {
			$html .= sprintf(
				'<option value="%s" lang="%s"%s>%s</option>',
				esc_url( $item['url'] ),
				esc_attr( $item['lang'] ),
				$item['current'] ? ' selected' : '',
				esc_html( $item['label'] )
			);
		}

		return $html . '</select>';
	}
}
