<?php
/**
 * Админ-страница «Перевод строк».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Rendering\Segment;
use WpMlp\Rest\TranslationsController;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;

/**
 * Таблица найденных строк с полем ручного перевода.
 *
 * Разметка серверная, а сохранение — один fetch к REST: визуальный редактор
 * Этапа 2 всё равно заменит этот экран, поэтому сборщика и React здесь нет.
 */
final class StringTranslationPage implements Hookable {

	public const MENU_SLUG  = 'wp-mlp-strings';
	public const CAPABILITY = 'manage_options';

	private const PER_PAGE = 20;

	/**
	 * @param Settings         $settings Настройки плагина.
	 * @param SourceRepository $sources  Исходные строки.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SourceRepository $sources
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Добавляет пункт подменю.
	 */
	public function addMenu(): void {
		add_submenu_page(
			SettingsPage::MENU_SLUG,
			__( 'Перевод строк', 'wp-mlp' ),
			__( 'Перевод строк', 'wp-mlp' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Подключает скрипт только на своей странице.
	 *
	 * @param string $hook Идентификатор текущего экрана.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wp-mlp-admin', WP_MLP_URL . 'assets/admin.css', array(), WP_MLP_VERSION );
		wp_enqueue_script( 'wp-mlp-admin', WP_MLP_URL . 'assets/admin.js', array(), WP_MLP_VERSION, true );

		wp_localize_script(
			'wp-mlp-admin',
			'wpMlpAdmin',
			array(
				'root'  => esc_url_raw( rest_url( TranslationsController::NAMESPACE . '/translations/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'saving' => __( 'Сохраняю…', 'wp-mlp' ),
					'saved'  => __( 'Сохранено', 'wp-mlp' ),
					'failed' => __( 'Ошибка сохранения', 'wp-mlp' ),
				),
			)
		);
	}

	/**
	 * Выводит страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$secondary = $this->settings->secondary();

		if ( array() === $secondary ) {
			printf(
				'<div class="wrap"><h1>%s</h1><div class="notice notice-warning"><p>%s</p></div></div>',
				esc_html__( 'Перевод строк', 'wp-mlp' ),
				esc_html__( 'Сначала добавьте хотя бы один дополнительный язык на странице «Языки».', 'wp-mlp' )
			);

			return;
		}

		$filters = $this->readFilters( $secondary );
		$result  = $this->sources->paginate(
			array(
				'locale'   => $filters['locale'],
				'status'   => $filters['status'],
				'search'   => $filters['search'],
				'page'     => $filters['page'],
				'per_page' => self::PER_PAGE,
			)
		);

		?>
		<div class="wrap wp-mlp-strings">
			<h1><?php esc_html_e( 'Перевод строк', 'wp-mlp' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Строки появляются здесь после того, как вы откроете страницу на дополнительном языке: плагин запоминает всё, что реально показала тема — тексты, пункты меню, подписи к картинкам и кнопки.', 'wp-mlp' ); ?>
			</p>

			<?php $this->renderFilters( $secondary, $filters, $result['total'] ); ?>

			<table class="widefat striped wp-mlp-table">
				<thead>
					<tr>
						<th scope="col" class="wp-mlp-col-source"><?php esc_html_e( 'Исходная строка', 'wp-mlp' ); ?></th>
						<th scope="col" class="wp-mlp-col-kind"><?php esc_html_e( 'Тип', 'wp-mlp' ); ?></th>
						<th scope="col" class="wp-mlp-col-translation"><?php esc_html_e( 'Перевод', 'wp-mlp' ); ?></th>
						<th scope="col" class="wp-mlp-col-status"><?php esc_html_e( 'Статус', 'wp-mlp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $result['items'] ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'Строк не найдено.', 'wp-mlp' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $result['items'] as $row ) : ?>
						<?php $this->renderRow( $row, $filters['locale'] ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php $this->renderPagination( $result['total'], $filters ); ?>
		</div>
		<?php
	}

	/**
	 * Строка таблицы.
	 *
	 * @param array<string, mixed> $row    Данные строки.
	 * @param string               $locale Целевой язык.
	 */
	private function renderRow( array $row, string $locale ): void {
		$status = (string) ( $row['status'] ?? '' );
		$text   = (string) ( $row['translated_text'] ?? '' );

		if ( '' === $text ) {
			$status = TranslationStatus::MISSING;
		}

		?>
		<tr>
			<td class="wp-mlp-col-source"><?php echo esc_html( (string) $row['source_text'] ); ?></td>
			<td class="wp-mlp-col-kind"><?php echo esc_html( $this->kindLabel( (string) $row['kind'] ) ); ?></td>
			<td class="wp-mlp-col-translation">
				<label class="screen-reader-text" for="mlp-input-<?php echo esc_attr( (string) $row['id'] ); ?>">
					<?php esc_html_e( 'Перевод', 'wp-mlp' ); ?>
				</label>
				<textarea
					id="mlp-input-<?php echo esc_attr( (string) $row['id'] ); ?>"
					class="wp-mlp-input"
					rows="2"
					data-source-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
					data-locale="<?php echo esc_attr( $locale ); ?>"><?php echo esc_textarea( $text ); ?></textarea>
				<button type="button" class="button button-secondary wp-mlp-save">
					<?php esc_html_e( 'Сохранить', 'wp-mlp' ); ?>
				</button>
			</td>
			<td class="wp-mlp-col-status">
				<span class="wp-mlp-status wp-mlp-status--<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( TranslationStatus::label( $status ) ); ?>
				</span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Фильтры над таблицей.
	 *
	 * @param array<string, Language>                                     $secondary Дополнительные языки.
	 * @param array{locale: string, status: string, search: string, page: int} $filters Текущие фильтры.
	 * @param int                                                         $total     Всего строк.
	 */
	private function renderFilters( array $secondary, array $filters, int $total ): void {
		?>
		<form method="get" class="wp-mlp-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">

			<label for="mlp-locale" class="screen-reader-text"><?php esc_html_e( 'Язык', 'wp-mlp' ); ?></label>
			<select name="mlp_locale" id="mlp-locale">
				<?php foreach ( $secondary as $language ) : ?>
					<option value="<?php echo esc_attr( $language->locale ); ?>" <?php selected( $language->locale, $filters['locale'] ); ?>>
						<?php echo esc_html( sprintf( '%s (%s)', $language->label, $language->locale ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mlp-status" class="screen-reader-text"><?php esc_html_e( 'Статус', 'wp-mlp' ); ?></label>
			<select name="mlp_status" id="mlp-status">
				<option value=""><?php esc_html_e( 'Все статусы', 'wp-mlp' ); ?></option>
				<?php foreach ( TranslationStatus::all() as $status ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $status, $filters['status'] ); ?>>
						<?php echo esc_html( TranslationStatus::label( $status ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mlp-search" class="screen-reader-text"><?php esc_html_e( 'Поиск', 'wp-mlp' ); ?></label>
			<input type="search" name="s" id="mlp-search" value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Поиск по исходнику и переводу', 'wp-mlp' ); ?>">

			<?php submit_button( __( 'Показать', 'wp-mlp' ), 'secondary', '', false ); ?>

			<span class="wp-mlp-total">
				<?php
				printf(
					/* translators: %s: number of strings */
					esc_html__( 'Найдено строк: %s', 'wp-mlp' ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
		</form>
		<?php
	}

	/**
	 * Постраничная навигация.
	 *
	 * @param int                                                             $total   Всего строк.
	 * @param array{locale: string, status: string, search: string, page: int} $filters Текущие фильтры.
	 */
	private function renderPagination( int $total, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'total'     => $pages,
				'current'   => $filters['page'],
				'type'      => 'array',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);

		if ( ! is_array( $links ) ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';

		foreach ( $links as $link ) {
			echo wp_kses(
				$link,
				array(
					'a'    => array(
						'href'  => array(),
						'class' => array(),
					),
					'span' => array( 'class' => array() ),
				)
			);
		}

		echo '</div></div>';
	}

	/**
	 * Читает фильтры из адресной строки.
	 *
	 * @param array<string, Language> $secondary Дополнительные языки.
	 * @return array{locale: string, status: string, search: string, page: int}
	 */
	private function readFilters( array $secondary ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- фильтры только читают данные.
		$locale = isset( $_GET['mlp_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET['mlp_locale'] ) ) ) : '';
		$status = isset( $_GET['mlp_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $secondary[ $locale ] ) ) {
			$locale = (string) array_key_first( $secondary );
		}

		return array(
			'locale' => $locale,
			'status' => TranslationStatus::isValid( $status ) ? $status : '',
			'search' => $search,
			'page'   => max( 1, $page ),
		);
	}

	/**
	 * Название вида строки.
	 *
	 * @param string $kind Значение колонки kind.
	 */
	private function kindLabel( string $kind ): string {
		return Segment::KIND_ATTRIBUTE === $kind
			? __( 'Атрибут', 'wp-mlp' )
			: __( 'Текст', 'wp-mlp' );
	}
}
