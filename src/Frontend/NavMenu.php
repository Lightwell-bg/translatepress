<?php
/**
 * Языки как пункты меню WordPress.
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
 * Блок «Языки» во «Внешний вид → Меню».
 *
 * Пункт меню — самый надёжный способ поставить переключатель в шапку: он
 * наследует вёрстку и стили темы, встаёт горизонтально рядом с остальными
 * пунктами, а вложенный в родительский пункт превращается в выпадающий список
 * средствами самой темы. Никаких правок шаблонов и своей вёрстки не нужно.
 *
 * В базе пункт хранится как обычная «произвольная ссылка» с адресом-маркером
 * `#mlp-lang-en`. Настоящий адрес подставляется при выводе: он зависит от
 * страницы, на которой посетитель находится сейчас, и в БД его держать нельзя.
 */
final class NavMenu implements Hookable {

	/**
	 * Начало адреса-маркера языкового пункта.
	 */
	private const URL_PREFIX = '#mlp-lang-';

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
		add_action( 'admin_head-nav-menus.php', array( $this, 'addMetaBox' ) );
		add_filter( 'wp_nav_menu_objects', array( $this, 'resolveItems' ) );
	}

	/**
	 * Добавляет блок «Языки» на экран редактирования меню.
	 */
	public function addMetaBox(): void {
		add_meta_box(
			'mlp-languages',
			__( 'Языки', 'wp-mlp' ),
			array( $this, 'renderMetaBox' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Выводит содержимое блока.
	 *
	 * Разметка повторяет структуру стандартных блоков экрана меню — иначе
	 * кнопка «Добавить в меню» ядра не найдёт поля и ничего не добавит.
	 */
	public function renderMetaBox(): void {
		$languages = $this->settings->all();

		if ( array() === $languages ) {
			printf( '<p>%s</p>', esc_html__( 'Сначала добавьте языки на странице «Языки».', 'wp-mlp' ) );

			return;
		}

		?>
		<div id="mlp-languages" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php $index = 0; ?>
					<?php foreach ( $languages as $language ) : ?>
						<?php $key = -1 - $index++; ?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox" class="menu-item-checkbox"
									name="menu-item[<?php echo esc_attr( (string) $key ); ?>][menu-item-object-id]"
									value="<?php echo esc_attr( (string) $key ); ?>">
								<?php echo esc_html( sprintf( '%s (%s)', $language->label, $language->locale ) ); ?>
							</label>
							<input type="hidden" class="menu-item-type"
								name="menu-item[<?php echo esc_attr( (string) $key ); ?>][menu-item-type]" value="custom">
							<input type="hidden" class="menu-item-title"
								name="menu-item[<?php echo esc_attr( (string) $key ); ?>][menu-item-title]"
								value="<?php echo esc_attr( $language->label ); ?>">
							<input type="hidden" class="menu-item-url"
								name="menu-item[<?php echo esc_attr( (string) $key ); ?>][menu-item-url]"
								value="<?php echo esc_attr( self::URL_PREFIX . $language->locale ); ?>">
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<p class="button-controls">
				<span class="add-to-menu">
					<input type="submit" class="button-secondary submit-add-to-menu right"
						value="<?php esc_attr_e( 'Добавить в меню', 'wp-mlp' ); ?>"
						name="add-mlp-language-menu-item" id="submit-mlp-languages">
					<span class="spinner"></span>
				</span>
			</p>

			<p class="description">
				<?php esc_html_e( 'Чтобы получить выпадающий список, перетащите языки под общий пункт меню — тема покажет их подменю сама.', 'wp-mlp' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Подставляет настоящие адреса в языковые пункты меню.
	 *
	 * @param array<int, object> $items Пункты меню.
	 * @return array<int, object>
	 */
	public function resolveItems( $items ): array {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$current  = $this->resolver->current();
		$resolved = array();

		foreach ( $items as $key => $item ) {
			$locale = $this->localeFromItem( $item );

			if ( null === $locale ) {
				$resolved[ $key ] = $item;

				continue;
			}

			$language = $this->settings->get( $locale );

			// Черновой язык и удалённый из настроек в меню не показываем:
			// доступного адреса у них нет.
			if ( null === $language || ! $language->isPublished() ) {
				continue;
			}

			$item->url = $this->urls->switchUrlFor( $language );

			$classes = is_array( $item->classes ) ? $item->classes : array();

			$classes[] = 'mlp-language-item';
			$classes[] = 'mlp-language-item-' . $language->locale;

			if ( $language->locale === $current->locale ) {
				$classes[] = 'current-lang';
				$classes[] = 'current-menu-item';
			}

			$item->classes = $classes;

			$resolved[ $key ] = $item;
		}

		return $resolved;
	}

	/**
	 * Код языка, если пункт меню — языковой.
	 *
	 * @param object $item Пункт меню.
	 */
	private function localeFromItem( object $item ): ?string {
		$url = isset( $item->url ) ? (string) $item->url : '';

		if ( ! str_starts_with( $url, self::URL_PREFIX ) ) {
			return null;
		}

		$locale = substr( $url, strlen( self::URL_PREFIX ) );

		return '' !== $locale ? $locale : null;
	}
}
