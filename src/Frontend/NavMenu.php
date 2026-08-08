<?php
/**
 * Переключатель языков как один пункт меню WordPress.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * Блок «Языки» во «Внешний вид → Меню».
 *
 * Владелец сайта добавляет РОВНО ОДИН пункт — «Выпадающий список» или
 * «В ряд» — и ничего не переносит и не вкладывает друг в друга вручную.
 * Список языков плагин достраивает сам на каждом показе меню: под этим
 * единственным пунктом появляются реальные дочерние пункты (для выпадающего
 * списка) или он сам и соседние с ним пункты подряд (для варианта «в ряд»).
 * Так тема обрабатывает результат своими штатными стилями подменю — ничего
 * вручную собирать не нужно, в отличие от обычных пунктов меню.
 *
 * В базе хранится один пункт-маркер — обычная «произвольная ссылка» с
 * адресом `#mlp-language-switcher-dropdown` или `-row`. Настоящие пункты
 * языков в БД не сохраняются: список языков может поменяться в любой
 * момент, а адрес каждого языка зависит от текущей страницы.
 */
final class NavMenu implements Hookable {

	/**
	 * Адрес-маркер пункта «выпадающий список».
	 */
	private const MARKER_DROPDOWN = '#mlp-language-switcher-dropdown';

	/**
	 * Адрес-маркер пункта «в ряд».
	 */
	private const MARKER_ROW = '#mlp-language-switcher-row';

	/**
	 * Начиная с какого числа генерировать идентификаторы для пунктов, которых
	 * нет в базе. Дальше реальных ID меню WordPress не имеет физической
	 * возможности дорасти.
	 */
	private const SYNTHETIC_ID_BASE = 900000000;

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
		add_filter( 'wp_nav_menu_objects', array( $this, 'injectLanguages' ), 10, 1 );
		add_filter( 'nav_menu_link_attributes', array( $this, 'filterLinkAttributes' ), 10, 2 );
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
	 * Разметка (`posttypediv` / `categorychecklist` / `submit-add-to-menu`)
	 * намеренно повторяет структуру стандартных блоков экрана меню: именно на
	 * эти классы завязан общий JS ядра WordPress (`nav-menu.js`), который уже
	 * умеет добавлять отмеченные пункты в меню по кнопке. Свой JS не нужен.
	 */
	public function renderMetaBox(): void {
		if ( array() === $this->settings->all() ) {
			printf( '<p>%s</p>', esc_html__( 'Сначала добавьте языки на странице «Языки».', 'wp-mlp' ) );

			return;
		}

		$rows = array(
			array(
				'key'         => -1,
				'title'       => __( 'Язык', 'wp-mlp' ),
				'url'         => self::MARKER_DROPDOWN,
				'label'       => __( 'Выпадающий список', 'wp-mlp' ),
				'description' => __( 'Один пункт, языки открываются по наведению/клику — так же, как обычное подменю темы.', 'wp-mlp' ),
			),
			array(
				'key'         => -2,
				'title'       => __( 'Переключатель языков', 'wp-mlp' ),
				'url'         => self::MARKER_ROW,
				'label'       => __( 'В ряд', 'wp-mlp' ),
				'description' => __( 'Языки видны сразу, отдельными пунктами подряд, без раскрытия.', 'wp-mlp' ),
			),
		);

		?>
		<div id="mlp-languages" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php foreach ( $rows as $row ) : ?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox" class="menu-item-checkbox"
									name="menu-item[<?php echo esc_attr( (string) $row['key'] ); ?>][menu-item-object-id]"
									value="<?php echo esc_attr( (string) $row['key'] ); ?>">
								<?php echo esc_html( $row['label'] ); ?>
							</label>
							<p class="description"><?php echo esc_html( $row['description'] ); ?></p>
							<input type="hidden" class="menu-item-type"
								name="menu-item[<?php echo esc_attr( (string) $row['key'] ); ?>][menu-item-type]" value="custom">
							<input type="hidden" class="menu-item-title"
								name="menu-item[<?php echo esc_attr( (string) $row['key'] ); ?>][menu-item-title]"
								value="<?php echo esc_attr( $row['title'] ); ?>">
							<input type="hidden" class="menu-item-url"
								name="menu-item[<?php echo esc_attr( (string) $row['key'] ); ?>][menu-item-url]"
								value="<?php echo esc_attr( $row['url'] ); ?>">
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
		</div>
		<?php
	}

	/**
	 * Достраивает языки под пунктами-маркерами.
	 *
	 * @param array<int, object> $items Пункты меню, уже готовые к выводу.
	 * @return array<int, object>
	 */
	public function injectLanguages( $items ): array {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$languages = array_values( $this->settings->published() );

		if ( count( $languages ) < 2 ) {
			// Переключаться не на что — маркеры без языков были бы пустой ссылкой.
			return array_values( array_filter( $items, array( $this, 'isNotMarker' ) ) );
		}

		$current = $this->resolver->current();
		$result  = array();

		foreach ( $items as $item ) {
			$marker = $this->markerType( $item );

			if ( null === $marker ) {
				$result[] = $item;

				continue;
			}

			if ( 'dropdown' === $marker ) {
				$result[] = $this->prepareDropdownTrigger( $item );

				foreach ( $languages as $index => $language ) {
					$result[] = $this->buildItem( $item, $language, $current, (int) $item->db_id, $index );
				}

				continue;
			}

			// «В ряд»: сам маркер становится первым языком, остальные — соседи.
			$this->applyLanguageToItem( $item, $languages[0], $current );
			$result[] = $item;

			for ( $index = 1, $count = count( $languages ); $index < $count; $index++ ) {
				$result[] = $this->buildItem( $item, $languages[ $index ], $current, 0, $index );
			}
		}

		return $result;
	}

	/**
	 * Добавляет класс `mlp-language-item` прямо на `<a>` языкового пункта.
	 *
	 * Ядро WordPress выводит `$item->classes` только на `<li>`. Этого обычно
	 * достаточно, но InternalLinks::apply() (второй, независимый проход по
	 * готовому HTML, который добавляет языковой префикс к ссылкам темы)
	 * должен уметь узнать ссылку переключателя, даже если тема подменяет
	 * Walker и не выводит классы `<li>` как ожидается. Класс прямо на `<a>` —
	 * это гарантия, не зависящая от чужого кода вывода меню.
	 *
	 * @param array<string, string> $atts  Атрибуты ссылки.
	 * @param object                $item  Пункт меню.
	 * @return array<string, string>
	 */
	public function filterLinkAttributes( $atts, $item ): array {
		$atts = is_array( $atts ) ? $atts : array();

		if ( ! in_array( 'mlp-language-item', (array) ( $item->classes ?? array() ), true ) ) {
			return $atts;
		}

		$existing      = isset( $atts['class'] ) ? trim( (string) $atts['class'] ) : '';
		$atts['class'] = '' !== $existing ? $existing . ' mlp-language-item' : 'mlp-language-item';

		return $atts;
	}

	/**
	 * Не является ли пункт нашим маркером.
	 *
	 * @param object $item Пункт меню.
	 */
	private function isNotMarker( object $item ): bool {
		return null === $this->markerType( $item );
	}

	/**
	 * Тип маркера, если пункт им является.
	 *
	 * @param object $item Пункт меню.
	 */
	private function markerType( object $item ): ?string {
		$url = isset( $item->url ) ? (string) $item->url : '';

		if ( self::MARKER_DROPDOWN === $url ) {
			return 'dropdown';
		}

		return self::MARKER_ROW === $url ? 'row' : null;
	}

	/**
	 * Готовит пункт-триггер выпадающего списка: сам он никуда не ведёт,
	 * а помечается как имеющий дочерние пункты — так тема покажет подменю
	 * своими же стилями, ничего кроме класса ей для этого не нужно.
	 *
	 * @param object $item Пункт-маркер.
	 */
	private function prepareDropdownTrigger( object $item ): object {
		$item->url = '#';

		/**
		 * Классы триггера выпадающего списка.
		 *
		 * `menu-item-has-children` понимает подавляющее большинство тем —
		 * это тот же класс, что WordPress сам добавляет обычным пунктам
		 * с дочерними. Мега-меню и кастомные JS-переключатели темы иногда
		 * ждут свой класс или data-атрибут — фильтр позволяет его добавить,
		 * не трогая код плагина.
		 *
		 * @param list<string> $classes Классы пункта-триггера.
		 * @param object       $item    Сам пункт меню.
		 */
		$item->classes = array_values(
			array_unique(
				apply_filters(
					'mlp_nav_menu_dropdown_classes',
					array_merge(
						is_array( $item->classes ) ? $item->classes : array(),
						array( 'mlp-language-switcher-trigger', 'menu-item-has-children' )
					),
					$item
				)
			)
		);

		return $item;
	}

	/**
	 * Синтезирует пункт меню для одного языка на основе маркера.
	 *
	 * Клонирование маркера, а не сборка объекта с нуля: маркер — уже
	 * настоящий объект пункта меню WordPress со всеми полями, которые
	 * ожидает Walker и тема. Переопределяются только те, что должны
	 * отличаться для конкретного языка.
	 *
	 * @param object   $marker         Пункт-маркер, с которого клонируем.
	 * @param Language $language       Язык, для которого собирается пункт.
	 * @param Language $current        Язык текущего запроса.
	 * @param int      $parentDbId     0 для «в ряд», db_id маркера для выпадающего списка.
	 * @param int      $index          Порядковый номер языка — часть уникального ID.
	 */
	private function buildItem( object $marker, Language $language, Language $current, int $parentDbId, int $index ): object {
		$item = clone $marker;

		$id             = self::SYNTHETIC_ID_BASE + ( (int) $marker->db_id ) * 1000 + $index;
		$item->ID       = $id;
		$item->db_id    = $id;
		$item->object   = 'custom';
		$item->object_id = 0;
		$item->type     = 'custom';
		$item->type_label = __( 'Произвольная ссылка', 'wp-mlp' );
		$item->menu_item_parent = $parentDbId;
		$item->menu_order = (int) $marker->menu_order + $index + 1;
		$item->description = '';
		$item->attr_title  = '';
		$item->target      = '';
		$item->xfn         = '';

		$this->applyLanguageToItem( $item, $language, $current );

		return $item;
	}

	/**
	 * Подставляет адрес, подпись и классы конкретного языка в пункт меню.
	 *
	 * @param object   $item     Пункт меню (маркер или его клон).
	 * @param Language $language Язык.
	 * @param Language $current  Язык текущего запроса.
	 */
	private function applyLanguageToItem( object $item, Language $language, Language $current ): void {
		$item->url   = $this->urls->switchUrlFor( $language );
		$item->title = $language->labelWithFlag();

		$classes = array( 'mlp-language-item', 'mlp-language-item-' . $language->locale );

		if ( $language->locale === $current->locale ) {
			$classes[] = 'current-lang';
			$classes[] = 'current-menu-item';
		}

		$item->classes = $classes;
	}
}
