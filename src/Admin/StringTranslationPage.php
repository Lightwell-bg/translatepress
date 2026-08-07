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
use WpMlp\Storage\TranslationCache;
use WpMlp\Storage\TranslationRepository;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Support\Assets;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;
use WpMlp\Translation\ProviderInterface;

/**
 * Таблица найденных строк с полем ручного перевода.
 *
 * Разметка серверная, а сохранение — один fetch к REST: визуальный редактор
 * Этапа 2 всё равно заменит этот экран, поэтому сборщика и React здесь нет.
 */
final class StringTranslationPage implements Hookable {

	public const MENU_SLUG   = 'wp-mlp-strings';
	public const CAPABILITY  = 'manage_options';
	public const ACTION_PURGE = 'mlp_purge_translations';

	private const PER_PAGE = 20;

	/**
	 * @param Settings              $settings     Настройки плагина.
	 * @param SourceRepository      $sources      Исходные строки.
	 * @param TranslationRepository $translations Переводы.
	 * @param TranslationCache      $cache        Кэш переводов.
	 * @param ProviderInterface     $provider     Провайдер машинного перевода.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly TranslationCache $cache,
		private readonly ProviderInterface $provider
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION_PURGE, array( $this, 'handlePurge' ) );
	}

	/**
	 * Удаляет все переводы выбранного языка.
	 *
	 * Операция необратимая, поэтому кроме capability и nonce на кнопке висит
	 * подтверждение в браузере, а язык берётся из формы, а не из GET-параметра.
	 */
	public function handlePurge(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав для удаления переводов.', 'wp-mlp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_PURGE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$locale   = isset( $_POST['mlp_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_POST['mlp_locale'] ) ) ) : '';
		$language = $this->settings->get( $locale );

		if ( null === $language || $language->isDefault ) {
			wp_die( esc_html__( 'Такого дополнительного языка нет в настройках.', 'wp-mlp' ), '', array( 'response' => 400 ) );
		}

		$deleted = $this->translations->deleteAllForLocale( $language->locale );
		$this->cache->flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'mlp_locale'  => $language->locale,
					'mlp-purged'  => (string) $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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

		wp_enqueue_style( 'wp-mlp-admin', Assets::url( 'assets/admin.css' ), array(), Assets::version( 'assets/admin.css' ) );
		wp_enqueue_script( 'wp-mlp-admin', Assets::url( 'assets/admin.js' ), array(), Assets::version( 'assets/admin.js' ), true );

		wp_localize_script(
			'wp-mlp-admin',
			'wpMlpAdmin',
			array(
				'root'  => esc_url_raw( rest_url( TranslationsController::NAMESPACE . '/translations/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'saving'        => __( 'Сохраняю…', 'wp-mlp' ),
					'saved'         => __( 'Сохранено', 'wp-mlp' ),
					'failed'        => __( 'Ошибка сохранения', 'wp-mlp' ),
					'deleting'      => __( 'Удаляю…', 'wp-mlp' ),
					'confirmDelete' => __( 'Удалить перевод этой строки?', 'wp-mlp' ),
					'translating'   => __( 'Перевожу с ИИ…', 'wp-mlp' ),
					'aiSuggested'   => __( 'Предложено ИИ — проверьте и сохраните', 'wp-mlp' ),
					'aiFailed'      => __( 'ИИ не смог перевести', 'wp-mlp' ),
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

			<?php $this->renderPurgeNotice(); ?>
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
				<?php if ( $this->provider->supports( $this->settings->defaultLanguage()->locale, $locale ) ) : ?>
					<button type="button" class="button-link wp-mlp-translate"
						title="<?php esc_attr_e( 'Заполнить поле переводом от ИИ — не сохраняет автоматически', 'wp-mlp' ); ?>">
						<?php esc_html_e( 'Перевести с ИИ', 'wp-mlp' ); ?>
					</button>
				<?php endif; ?>
				<?php if ( '' !== $text ) : ?>
					<button type="button" class="button-link wp-mlp-delete"
						title="<?php esc_attr_e( 'Удалить перевод', 'wp-mlp' ); ?>">
						<?php esc_html_e( 'Удалить', 'wp-mlp' ); ?>
					</button>
				<?php endif; ?>
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

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mlp-purge">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PURGE ); ?>">
			<input type="hidden" name="mlp_locale" value="<?php echo esc_attr( $filters['locale'] ); ?>">
			<?php wp_nonce_field( self::ACTION_PURGE ); ?>

			<button type="submit" class="button button-link-delete"
				data-mlp-confirm="<?php echo esc_attr( $this->purgeConfirmation( $secondary, $filters['locale'] ) ); ?>">
				<?php
				printf(
					/* translators: %s: language code */
					esc_html__( 'Удалить все переводы языка «%s»', 'wp-mlp' ),
					esc_html( $filters['locale'] )
				);
				?>
			</button>
			<span class="description">
				<?php esc_html_e( 'Исходные строки останутся — удалятся только переводы на этот язык.', 'wp-mlp' ); ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Текст подтверждения для массового удаления.
	 *
	 * @param array<string, Language> $secondary Дополнительные языки.
	 * @param string                  $locale    Выбранный язык.
	 */
	private function purgeConfirmation( array $secondary, string $locale ): string {
		$label = $secondary[ $locale ]->label ?? $locale;

		return sprintf(
			/* translators: 1: language label, 2: language code */
			__( 'Удалить ВСЕ переводы языка «%1$s» (%2$s)? Это действие нельзя отменить.', 'wp-mlp' ),
			$label,
			$locale
		);
	}

	/**
	 * Сообщение о результате массового удаления.
	 */
	private function renderPurgeNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение счётчика из URL.
		if ( ! isset( $_GET['mlp-purged'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- значение приводится к целому.
		$deleted = absint( $_GET['mlp-purged'] );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of deleted translations */
					__( 'Удалено переводов: %s.', 'wp-mlp' ),
					number_format_i18n( $deleted )
				)
			)
		);
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
