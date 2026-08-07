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
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Storage\TranslationRepository;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Support\Assets;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;
use WpMlp\Translation\ProviderFactory;

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
	 * @param ProviderFactory       $providers    Доступы к провайдеру перевода.
	 * @param OccurrenceRepository  $occurrences  Места использования строк.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly TranslationCache $cache,
		private readonly ProviderFactory $providers,
		private readonly OccurrenceRepository $occurrences
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
				'locale'    => $filters['locale'],
				'status'    => $filters['status'],
				'search'    => $filters['search'],
				'scope'     => $filters['scope'],
				'object_id' => $filters['object_id'],
				'page'      => $filters['page'],
				'per_page'  => self::PER_PAGE,
			)
		);

		?>
		<div class="wrap wp-mlp-strings">
			<h1><?php esc_html_e( 'Перевод строк', 'wp-mlp' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Строки появляются здесь после того, как вы откроете страницу на дополнительном языке: плагин запоминает всё, что реально показала тема — тексты, пункты меню, подписи к картинкам и кнопки.', 'wp-mlp' ); ?>
			</p>

			<?php $this->renderPurgeNotice(); ?>
			<?php $this->renderAiNotice(); ?>
			<?php $this->renderFilters( $secondary, $filters, $result['total'] ); ?>
			<?php $this->renderTableNav( $result['total'], $filters, 'top' ); ?>

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

			<?php $this->renderTableNav( $result['total'], $filters, 'bottom' ); ?>
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
				<?php if ( $this->providers->isReady() ) : ?>
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

			<label for="mlp-scope" class="screen-reader-text"><?php esc_html_e( 'Где встречается', 'wp-mlp' ); ?></label>
			<select name="mlp_scope" id="mlp-scope">
				<option value=""><?php esc_html_e( 'Весь сайт', 'wp-mlp' ); ?></option>
				<option value="<?php echo esc_attr( SourceRepository::SCOPE_GLOBAL ); ?>" <?php selected( SourceRepository::SCOPE_GLOBAL, $filters['scope'] ); ?>>
					<?php esc_html_e( 'Общие элементы (меню, шапка, подвал)', 'wp-mlp' ); ?>
				</option>
				<optgroup label="<?php esc_attr_e( 'Отдельная запись', 'wp-mlp' ); ?>">
					<?php foreach ( $this->translatedObjects() as $id => $title ) : ?>
						<option value="<?php echo esc_attr( SourceRepository::SCOPE_OBJECT . ':' . $id ); ?>"
							<?php selected( SourceRepository::SCOPE_OBJECT === $filters['scope'] && $id === $filters['object_id'] ); ?>>
							<?php echo esc_html( $title ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
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
	 * Объясняет, почему кнопки «Перевести с ИИ» нет.
	 *
	 * Сообщение обязано называть недостающее поле поимённо. Общая фраза
	 * «ключ не настроен» уже один раз отправила владельца сайта проверять
	 * ключ, который на самом деле был сохранён, — не хватало модели.
	 */
	private function renderAiNotice(): void {
		$missing = $this->providers->missing();

		if ( array() === $missing ) {
			return;
		}

		$names = array(
			ProviderFactory::FIELD_KEY   => __( 'ключ OpenAI', 'wp-mlp' ),
			ProviderFactory::FIELD_MODEL => __( 'модель OpenAI', 'wp-mlp' ),
		);

		$labels = array();

		foreach ( $missing as $field ) {
			$labels[] = $names[ $field ] ?? $field;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: 1: comma-separated list of missing fields, 2: link to the settings page */
					__( 'Кнопки «Перевести с ИИ» нет: не заполнено — %1$s. Впишите на странице %2$s.', 'wp-mlp' ),
					'<strong>' . esc_html( implode( ', ', $labels ) ) . '</strong>',
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) . '">' . esc_html__( 'Мультиязычность → Языки', 'wp-mlp' ) . '</a>'
				),
				array(
					'a'      => array( 'href' => array() ),
					'strong' => array(),
				)
			)
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
	 * Постраничная навигация в оформлении списков WordPress.
	 *
	 * Раньше здесь был голый `paginate_links()`: ссылки шли слитно, без
	 * отступов и без указания текущей страницы, и попасть по нужной цифре
	 * было тяжело. Разметка ниже повторяет структуру `WP_List_Table`, поэтому
	 * подхватывает стили ядра и даёт кнопки перехода плюс поле с номером.
	 *
	 * @param int                                                                                          $total    Всего строк.
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, page: int} $filters  Текущие фильтры.
	 * @param string                                                                                       $position `top` или `bottom`.
	 */
	private function renderTableNav( int $total, array $filters, string $position ): void {
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$current = min( max( 1, $filters['page'] ), $pages );

		?>
		<div class="tablenav <?php echo esc_attr( $position ); ?>">
			<div class="tablenav-pages<?php echo $pages < 2 ? ' one-page' : ''; ?>">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of strings */
						esc_html( _n( '%s строка', '%s строк', $total, 'wp-mlp' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>

				<?php if ( $pages > 1 ) : ?>
					<span class="pagination-links">
						<?php
						$this->renderPageLink( '&laquo;', 1, $filters, $current > 1, 'first-page' );
						$this->renderPageLink( '&lsaquo;', $current - 1, $filters, $current > 1, 'prev-page' );
						?>

						<span class="paging-input">
							<form method="get" class="wp-mlp-paging-form">
								<?php $this->renderHiddenFilters( $filters ); ?>
								<label class="screen-reader-text" for="mlp-page-<?php echo esc_attr( $position ); ?>">
									<?php esc_html_e( 'Текущая страница', 'wp-mlp' ); ?>
								</label>
								<input class="current-page" id="mlp-page-<?php echo esc_attr( $position ); ?>"
									type="number" name="paged" min="1" max="<?php echo esc_attr( (string) $pages ); ?>"
									value="<?php echo esc_attr( (string) $current ); ?>" size="2">
								<span class="tablenav-paging-text">
									<?php esc_html_e( 'из', 'wp-mlp' ); ?>
									<span class="total-pages"><?php echo esc_html( number_format_i18n( $pages ) ); ?></span>
								</span>
							</form>
						</span>

						<?php
						$this->renderPageLink( '&rsaquo;', $current + 1, $filters, $current < $pages, 'next-page' );
						$this->renderPageLink( '&raquo;', $pages, $filters, $current < $pages, 'last-page' );
						?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Кнопка перехода на страницу списка.
	 *
	 * @param string                                                                                       $label   Символ на кнопке.
	 * @param int                                                                                          $page    Целевая страница.
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, page: int} $filters Текущие фильтры.
	 * @param bool                                                                                         $enabled Активна ли кнопка.
	 * @param string                                                                                       $class   Класс кнопки из ядра.
	 */
	private function renderPageLink( string $label, int $page, array $filters, bool $enabled, string $class ): void {
		if ( ! $enabled ) {
			printf(
				'<span class="tablenav-pages-navspan button disabled" aria-hidden="true">%s</span>',
				esc_html( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) )
			);

			return;
		}

		printf(
			'<a class="%1$s button" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( $this->filteredUrl( $filters, $page ) ),
			esc_html( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) )
		);
	}

	/**
	 * Адрес страницы списка с сохранением всех фильтров.
	 *
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, page: int} $filters Текущие фильтры.
	 * @param int                                                                                          $page    Целевая страница.
	 */
	private function filteredUrl( array $filters, int $page ): string {
		return add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'mlp_locale' => $filters['locale'],
				'mlp_status' => $filters['status'],
				'mlp_scope'  => self::scopeValue( $filters ),
				's'          => $filters['search'],
				'paged'      => (string) max( 1, $page ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Значение фильтра области в том же виде, в каком его отдаёт `<select>`.
	 *
	 * Один формат и для формы, и для ссылок пагинации: иначе при переходе
	 * на вторую страницу выбранная запись потерялась бы.
	 *
	 * @param array{scope: string, object_id: int} $filters Текущие фильтры.
	 */
	private static function scopeValue( array $filters ): string {
		if ( SourceRepository::SCOPE_OBJECT === $filters['scope'] && $filters['object_id'] > 0 ) {
			return SourceRepository::SCOPE_OBJECT . ':' . $filters['object_id'];
		}

		return SourceRepository::SCOPE_GLOBAL === $filters['scope'] ? SourceRepository::SCOPE_GLOBAL : '';
	}

	/**
	 * Записи и страницы, на которых плагин находил строки.
	 *
	 * @return array<int, string> Идентификатор записи => заголовок для списка.
	 */
	private function translatedObjects(): array {
		$titles = array();

		foreach ( $this->occurrences->objectIds() as $id ) {
			$title = get_the_title( $id );

			if ( '' === trim( (string) $title ) ) {
				continue;
			}

			$titles[ $id ] = (string) $title;
		}

		asort( $titles, SORT_NATURAL | SORT_FLAG_CASE );

		return $titles;
	}

	/**
	 * Скрытые поля, сохраняющие фильтры при переходе по страницам.
	 *
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, page: int} $filters Текущие фильтры.
	 */
	private function renderHiddenFilters( array $filters ): void {
		$fields = array(
			'page'       => self::MENU_SLUG,
			'mlp_locale' => $filters['locale'],
			'mlp_status' => $filters['status'],
			'mlp_scope'  => self::scopeValue( $filters ),
			's'          => $filters['search'],
		);

		foreach ( $fields as $name => $value ) {
			printf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
		}
	}

	/**
	 * Читает фильтры из адресной строки.
	 *
	 * @param array<string, Language> $secondary Дополнительные языки.
	 * @return array{locale: string, status: string, search: string, scope: string, object_id: int, page: int}
	 */
	private function readFilters( array $secondary ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- фильтры только читают данные.
		$locale = isset( $_GET['mlp_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET['mlp_locale'] ) ) ) : '';
		$status = isset( $_GET['mlp_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$scope  = isset( $_GET['mlp_scope'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_scope'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $secondary[ $locale ] ) ) {
			$locale = (string) array_key_first( $secondary );
		}

		list( $scopeName, $objectId ) = self::parseScope( $scope );

		return array(
			'locale'    => $locale,
			'status'    => TranslationStatus::isValid( $status ) ? $status : '',
			'search'    => $search,
			'scope'     => $scopeName,
			'object_id' => $objectId,
			'page'      => max( 1, $page ),
		);
	}

	/**
	 * Разбирает значение фильтра области. Чистая функция.
	 *
	 * Принимает `` (весь сайт), `global` (общие элементы) и `object:123`
	 * (конкретная запись). Всё остальное трактуется как «весь сайт»:
	 * значение приходит из адресной строки и доверять ему нельзя.
	 *
	 * @param string $value Сырое значение параметра.
	 * @return array{0: string, 1: int} Название области и идентификатор записи.
	 */
	public static function parseScope( string $value ): array {
		if ( SourceRepository::SCOPE_GLOBAL === $value ) {
			return array( SourceRepository::SCOPE_GLOBAL, 0 );
		}

		$prefix = SourceRepository::SCOPE_OBJECT . ':';

		if ( str_starts_with( $value, $prefix ) ) {
			$id = substr( $value, strlen( $prefix ) );

			/*
			 * Только цифры целиком. absint() взял бы ведущее число и превратил
			 * `object:-5` в запись 5, а `object:1 OR 1=1` — в запись 1: до SQL
			 * это не дошло бы (значение уходит через %d), но фильтр молча
			 * показывал бы не то, что просили.
			 */
			if ( '' !== $id && ctype_digit( $id ) && (int) $id > 0 ) {
				return array( SourceRepository::SCOPE_OBJECT, (int) $id );
			}
		}

		return array( SourceRepository::SCOPE_ALL, 0 );
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
