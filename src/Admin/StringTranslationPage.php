<?php
/**
 * Админ-страница «Перевод строк».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\I18n\GettextKey;
use WpMlp\Rendering\Segment;
use WpMlp\Rest\TranslationsController;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\GettextRepository;
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

	/**
	 * Чистка собранных строк интерфейса без перевода.
	 */
	public const ACTION_CLEAN_GETTEXT = 'mlp_clean_gettext';

	/**
	 * Вкладка «Контент» — тексты сайта: записи, страницы, меню, виджеты.
	 */
	public const TAB_CONTENT = 'content';

	/**
	 * Вкладка «SEO/GEO» — meta description, Open Graph, Twitter, JSON-LD.
	 */
	public const TAB_SEO = 'seo';

	/**
	 * Вкладка «Интерфейс» — строки WordPress, темы и плагинов (gettext).
	 */
	public const TAB_INTERFACE = 'interface';

	/**
	 * Вкладка «Ссылки» — адреса, куда ведут ссылки на каждом языке.
	 */
	public const TAB_LINKS = 'links';

	private const PER_PAGE = 20;

	/**
	 * @param Settings              $settings     Настройки плагина.
	 * @param SourceRepository      $sources      Исходные строки.
	 * @param TranslationRepository $translations Переводы.
	 * @param TranslationCache      $cache        Кэш переводов.
	 * @param ProviderFactory        $providers    Доступы к провайдеру перевода.
	 * @param OccurrenceRepository   $occurrences  Места использования строк.
	 * @param InterfaceStringsScreen $interface    Вкладка «Интерфейс».
	 * @param GettextRepository      $gettext      Gettext-часть словаря.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly TranslationCache $cache,
		private readonly ProviderFactory $providers,
		private readonly OccurrenceRepository $occurrences,
		private readonly InterfaceStringsScreen $interface,
		private readonly GettextRepository $gettext
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION_PURGE, array( $this, 'handlePurge' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAN_GETTEXT, array( $this, 'handleCleanGettext' ) );
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
	 * Убирает собранные строки интерфейса, которые никто не переводил.
	 *
	 * Отдельно от «удалить все переводы»: там удаляются переводы и
	 * остаются исходные строки, а здесь наоборот — уходят сами строки,
	 * но только те, что никто не трогал руками. Для gettext это
	 * безопасно: они собираются заново сами при следующем показе страницы.
	 */
	public function handleCleanGettext(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-mlp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_CLEAN_GETTEXT );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$locale = isset( $_POST['mlp_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_POST['mlp_locale'] ) ) ) : '';
		$tab    = self::parseTab( isset( $_POST['mlp_tab'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mlp_tab'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/*
		 * Чистим ровно то, что показано на этой вкладке. Иначе кнопка на
		 * «Контенте» молча снесла бы и SEO-строки — а это разные списки с
		 * разной ценой ошибки.
		 */
		if ( self::TAB_INTERFACE === $tab ) {
			$deleted = $this->gettext->deleteUntranslated();
		} else {
			$deleted = $this->sources->deleteUntranslated( self::cleanableKinds( $tab ) );
		}

		$this->cache->flush();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'mlp_tab'     => $tab,
					'mlp_locale'  => $locale,
					'mlp-cleaned' => (string) $deleted,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Какие виды строк чистит кнопка на этой вкладке. Чистая функция.
	 *
	 * SEO-строки и контент лежат в одних и тех же `kind`, различает их
	 * только `attribute_name = 'content'` в местах использования, поэтому
	 * «почистить только SEO» отдельным запросом не выйдет — и обе вкладки
	 * чистят один и тот же набор видов. Это честнее, чем делать вид, что
	 * кнопка на «SEO/GEO» трогает только его.
	 *
	 * @param string $tab Текущая вкладка.
	 * @return list<string>
	 */
	public static function cleanableKinds( string $tab ): array {
		if ( self::TAB_INTERFACE === $tab ) {
			return array( GettextKey::KIND );
		}

		if ( self::TAB_LINKS === $tab ) {
			return array( SourceRepository::TYPE_LINK );
		}

		return array(
			SourceRepository::TYPE_TEXT,
			SourceRepository::TYPE_ATTRIBUTE,
			SourceRepository::TYPE_BLOCK,
			SourceRepository::TYPE_SEO,
		);
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
		$tab     = self::parseTab( $this->rawTab() );

		?>
		<div class="wrap wp-mlp-strings">
			<h1><?php esc_html_e( 'Перевод строк', 'wp-mlp' ); ?></h1>

			<?php $this->renderTabs( $tab, $filters['locale'] ); ?>
			<?php $this->renderPurgeNotice(); ?>
			<?php $this->renderAiNotice(); ?>

			<?php $this->renderCleanNotice(); ?>

			<?php if ( self::TAB_INTERFACE === $tab ) : ?>
				<?php $this->renderInterfaceTab( $secondary[ $filters['locale'] ], $secondary, $filters ); ?>
			<?php else : ?>
				<?php $this->renderStringsTab( $tab, $secondary, $filters ); ?>
			<?php endif; ?>

			<?php $this->renderCleanForm( $tab, $filters['locale'] ); ?>
		</div>
		<?php
	}

	/**
	 * Вкладка «Интерфейс» — делегируется отдельному экрану: у gettext-строк
	 * свои колонки (домен, контекст, официальный перевод) и свои фильтры.
	 *
	 * @param Language                                                                                     $language Целевой язык.
	 * @param array{locale: string, status: string, search: string, domain: string, page: int} $filters  Текущие фильтры.
	 */
	private function renderInterfaceTab( Language $language, array $secondary, array $filters ): void {
		$this->interface->render(
			$language,
			$secondary,
			array(
				'domain' => $filters['domain'],
				'status' => $filters['status'],
				'search' => $filters['search'],
				'page'   => $filters['page'],
			),
			self::PER_PAGE,
			function ( int $total, int $page ) use ( $filters ): void {
				$filters['page'] = $page;

				$this->renderTableNav( $total, $filters, 'top' );
			}
		);
	}

	/**
	 * Вкладки «Контент» и «SEO/GEO» — один и тот же список строк из DOM,
	 * отличается только тем, какие виды строк в него попадают.
	 *
	 * @param string                                                                                       $tab       Текущая вкладка.
	 * @param array<string, Language>                                                                      $secondary Дополнительные языки.
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, type: string, page: int} $filters Текущие фильтры.
	 */
	private function renderStringsTab( string $tab, array $secondary, array $filters ): void {
		/*
		 * Вкладка решает, какие виды строк показывать, а не пользователь:
		 * SEO/GEO и строки интерфейса живут на своих экранах, и «все типы»
		 * на вкладке «Контент» означает «всё содержимое», а не буквально всё.
		 */
		if ( self::TAB_SEO === $tab ) {
			$type = SourceRepository::TYPE_SEO;
		} elseif ( self::TAB_LINKS === $tab ) {
			$type = SourceRepository::TYPE_LINK;
		} else {
			$type = '' !== $filters['type'] ? $filters['type'] : SourceRepository::TYPE_CONTENT;
		}

		$result = $this->sources->paginate(
			array(
				'locale'    => $filters['locale'],
				'status'    => $filters['status'],
				'search'    => $filters['search'],
				'scope'     => $filters['scope'],
				'object_id' => $filters['object_id'],
				'type'      => $type,
				'page'      => $filters['page'],
				'per_page'  => self::PER_PAGE,
			)
		);

		?>
		<p class="description">
			<?php if ( self::TAB_SEO === $tab ) : ?>
				<?php esc_html_e( 'Поля для поисковиков и превью в соцсетях: meta description, Open Graph, Twitter Card и текстовые поля JSON-LD. На самой странице они не видны — их читают Google и мессенджеры, когда кто-то делится ссылкой.', 'wp-mlp' ); ?>
				<br>
				<?php esc_html_e( 'Заголовок записи переводить здесь не нужно: он общий с H1 и переводится один раз во вкладке «Контент».', 'wp-mlp' ); ?>
			<?php elseif ( self::TAB_LINKS === $tab ) : ?>
				<?php esc_html_e( 'Куда ведут ссылки на этом языке. Слева — адрес из оригинала, справа можно указать другой: например, направить болгарскую кнопку на болгарский лендинг, а английскую — на английский.', 'wp-mlp' ); ?>
				<br>
				<?php esc_html_e( 'Оставьте поле пустым — ссылка останется прежней и получит языковой префикс автоматически, как раньше. Заполняйте только там, где адрес должен отличаться.', 'wp-mlp' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'То, что вы написали сами: текст записей и страниц, заголовки, пункты меню, подписи к картинкам, тексты в виджетах. Это основная вкладка — с неё и начинайте.', 'wp-mlp' ); ?>
				<br>
				<?php esc_html_e( 'Строки появляются здесь не сразу: плагин узнаёт текст страницы только когда она реально показана. Откройте страницу на дополнительном языке — и её строки окажутся в списке.', 'wp-mlp' ); ?>
			<?php endif; ?>
		</p>

		<?php $this->renderFilters( $secondary, $filters, $result['total'], $tab ); ?>
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
		<?php
	}

	/**
	 * Кнопка чистки собранных строк интерфейса.
	 *
	 * @param string $locale Текущий язык — вернуться на ту же вкладку.
	 */
	private function renderCleanForm( string $tab, string $locale ): void {
		$isInterface = self::TAB_INTERFACE === $tab;

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp-mlp-purge">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAN_GETTEXT ); ?>">
			<input type="hidden" name="mlp_locale" value="<?php echo esc_attr( $locale ); ?>">
			<input type="hidden" name="mlp_tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( self::ACTION_CLEAN_GETTEXT ); ?>

			<button type="submit" class="button"
				data-mlp-confirm="<?php esc_attr_e( 'Убрать найденные строки, у которых нет ни одного перевода? Всё переведённое останется, а нужные строки соберутся заново при следующем показе страниц.', 'wp-mlp' ); ?>">
				<?php esc_html_e( 'Очистить строки без перевода', 'wp-mlp' ); ?>
			</button>
			<span class="description">
				<?php if ( $isInterface ) : ?>
					<?php esc_html_e( 'Полезно, если список засорился строками, собранными до установки языкового пакета. Строки с вашим переводом не удаляются.', 'wp-mlp' ); ?>
				<?php elseif ( self::TAB_LINKS === $tab ) : ?>
					<?php esc_html_e( 'Убирает только адреса ссылок. Полезно, если список засорился техническими адресами (например, собранными до этого обновления). Ссылки с вашим переводом не удаляются.', 'wp-mlp' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Чистит «Контент» и «SEO/GEO» разом — они лежат в одной таблице. Полезно, если в список попали строки интерфейса, собранные до появления вкладки «Интерфейс». Переведённое не удаляется, остальное соберётся заново.', 'wp-mlp' ); ?>
				<?php endif; ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Сообщение о результате чистки.
	 */
	private function renderCleanNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение счётчика из URL.
		if ( ! isset( $_GET['mlp-cleaned'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- значение приводится к целому.
		$deleted = absint( $_GET['mlp-cleaned'] );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of deleted strings */
					__( 'Убрано строк интерфейса: %s.', 'wp-mlp' ),
					number_format_i18n( $deleted )
				)
			)
		);
	}

	/**
	 * Переключатель вкладок в оформлении ядра WordPress.
	 *
	 * @param string $current Текущая вкладка.
	 * @param string $locale  Выбранный язык — переносится между вкладками.
	 */
	private function renderTabs( string $current, string $locale ): void {
		$tabs = array(
			self::TAB_CONTENT   => __( 'Контент', 'wp-mlp' ),
			self::TAB_SEO       => __( 'SEO/GEO', 'wp-mlp' ),
			self::TAB_INTERFACE => __( 'Интерфейс', 'wp-mlp' ),
			self::TAB_LINKS     => __( 'Ссылки', 'wp-mlp' ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix">';

		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'       => self::MENU_SLUG,
							'mlp_tab'    => $tab,
							'mlp_locale' => $locale,
						),
						admin_url( 'admin.php' )
					)
				),
				$tab === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Сырое значение вкладки из адресной строки.
	 */
	private function rawTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- значение проходит через allowlist.
		return isset( $_GET['mlp_tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_tab'] ) ) : '';
	}

	/**
	 * Проверяет значение вкладки по allowlist. Чистая функция.
	 *
	 * @param string $value Сырое значение параметра.
	 */
	public static function parseTab( string $value ): string {
		$allowed = array( self::TAB_CONTENT, self::TAB_SEO, self::TAB_INTERFACE, self::TAB_LINKS );

		return in_array( $value, $allowed, true ) ? $value : self::TAB_CONTENT;
	}

	/**
	 * Строка таблицы.
	 *
	 * @param array<string, mixed> $row    Данные строки.
	 * @param string               $locale Целевой язык.
	 */
	/**
	 * Строка списка — это адрес ссылки (`href`). Чистая функция.
	 *
	 * Языковой модели такую строку не показываем: она переводит то, что
	 * видит, — `/o-nas/` станет `/about-us/`, параметры перемешаются, и
	 * ссылка молча поведёт в никуда, а перевод будет выглядеть успешным
	 * (та же причина, что и в PostTranslationController::isLinkTarget() и
	 * в редакторе — см. assets/editor.js). Список строк отдаёт кнопку на
	 * каждой строке отдельно от одиночного редактора, поэтому проверка
	 * нужна и здесь.
	 *
	 * @param array<string, mixed> $row Строка из SourceRepository::paginate().
	 */
	public static function isLinkRow( array $row ): bool {
		return Segment::KIND_ATTRIBUTE === (string) ( $row['kind'] ?? '' )
			&& 'href' === (string) ( $row['attribute_name'] ?? '' );
	}

	private function renderRow( array $row, string $locale ): void {
		$status = (string) ( $row['status'] ?? '' );
		$text   = (string) ( $row['translated_text'] ?? '' );

		if ( '' === $text ) {
			$status = TranslationStatus::MISSING;
		}

		$isLink = self::isLinkRow( $row );

		?>
		<tr>
			<td class="wp-mlp-col-source"><?php echo esc_html( (string) $row['source_text'] ); ?></td>
			<td class="wp-mlp-col-kind">
				<?php
				echo esc_html(
					$this->kindLabel(
						(string) $row['kind'],
						isset( $row['attribute_name'] ) && '' !== (string) $row['attribute_name'] ? (string) $row['attribute_name'] : null
					)
				);
				?>
			</td>
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
				<?php if ( $this->providers->isReady() && ! $isLink ) : ?>
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
	private function renderFilters( array $secondary, array $filters, int $total, string $tab = self::TAB_CONTENT ): void {
		?>
		<form method="get" class="wp-mlp-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
			<input type="hidden" name="mlp_tab" value="<?php echo esc_attr( $tab ); ?>">

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

			<?php if ( self::TAB_SEO !== $tab && self::TAB_LINKS !== $tab ) : ?>
				<?php /* На SEO/GEO и «Ссылках» выбирать нечего: вкладка сама и есть тип. */ ?>
				<label for="mlp-type" class="screen-reader-text"><?php esc_html_e( 'Тип строки', 'wp-mlp' ); ?></label>
				<select name="mlp_type" id="mlp-type">
					<option value=""><?php esc_html_e( 'Всё содержимое', 'wp-mlp' ); ?></option>
					<option value="<?php echo esc_attr( SourceRepository::TYPE_TEXT ); ?>" <?php selected( SourceRepository::TYPE_TEXT, $filters['type'] ); ?>>
						<?php esc_html_e( 'Текст', 'wp-mlp' ); ?>
					</option>
					<option value="<?php echo esc_attr( SourceRepository::TYPE_ATTRIBUTE ); ?>" <?php selected( SourceRepository::TYPE_ATTRIBUTE, $filters['type'] ); ?>>
						<?php esc_html_e( 'Атрибут (alt, title, placeholder)', 'wp-mlp' ); ?>
					</option>
					<option value="<?php echo esc_attr( SourceRepository::TYPE_BLOCK ); ?>" <?php selected( SourceRepository::TYPE_BLOCK, $filters['type'] ); ?>>
						<?php esc_html_e( 'Блок (абзац с разметкой)', 'wp-mlp' ); ?>
					</option>
				</select>
			<?php endif; ?>

			<label for="mlp-search" class="screen-reader-text"><?php esc_html_e( 'Поиск', 'wp-mlp' ); ?></label>
			<input type="search" name="s" id="mlp-search" value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Поиск по исходнику и переводу', 'wp-mlp' ); ?>"
				title="<?php esc_attr_e( '* — любые символы: *blog/en/ значит «кончается на», blog/en/* — «начинается с». Подробнее — в «Помощь» справа вверху.', 'wp-mlp' ); ?>">

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
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, type: string, page: int} $filters  Текущие фильтры.
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
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, type: string, page: int} $filters Текущие фильтры.
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
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, type: string, page: int} $filters Текущие фильтры.
	 * @param int                                                                                                       $page    Целевая страница.
	 */
	private function filteredUrl( array $filters, int $page ): string {
		return add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'mlp_tab'    => self::parseTab( $this->rawTab() ),
				'mlp_locale' => $filters['locale'],
				'mlp_status' => $filters['status'],
				'mlp_scope'  => self::scopeValue( $filters ),
				'mlp_type'   => $filters['type'],
				'mlp_domain' => $filters['domain'] ?? '',
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
	 * Найдено на живом сайте: `object_id` в местах использования — это то,
	 * что `get_queried_object_id()` вернул в момент показа страницы,
	 * причём для ЛЮБОЙ страницы, какую бы WordPress ни отдал по ссылке.
	 * Ссылка вида `?p=39`, которую никто в здравом уме не наберёт, но
	 * может дёрнуть бездумный обход ID подряд, вполне может указывать на
	 * черновую ревизию статьи (`post_type = revision`) — а `get_the_title()`
	 * у ревизии возвращает тот же заголовок, что и у самой статьи. Отсюда
	 * учетверённое «Как подготовить AI-проект…» в списке: 36 — сама
	 * запись, 39/40/42 — три её ревизии, случайно попавшие в базу как
	 * отдельные «страницы». Туда же утекают вложения (`cropped-logo.png`)
	 * и служебный тип `wp_global_styles` («Custom Styles» — так WordPress
	 * называет его ВСЕГДА, у любой темы) — их тоже видел
	 * `get_queried_object_id()` на каком-то показе.
	 *
	 * Список для выпадающего меню должен остаться допустимым, только когда
	 * `get_post_types(['public' => true])` минус `attachment` — то есть
	 * ровно то, для чего WordPress вообще строит отдельные адреса
	 * страниц: записи, страницы и настоящие пользовательские типы (товары
	 * магазина и подобное). Ревизии, стили, вложения регистрируются с
	 * `public => false` или, как вложения, публичны, но страницей в
	 * привычном смысле не являются, — их такой allowlist отсекает без
	 * необходимости перечислять служебные типы по имени: список новых
	 * внутренних типов WordPress не наш, а его, и перечислять их вручную
	 * значило бы вечно догонять.
	 *
	 * @return array<int, string> Идентификатор записи => заголовок для списка.
	 */
	private function translatedObjects(): array {
		$allowedTypes = self::scopeDropdownPostTypes( get_post_types( array( 'public' => true ) ) );
		$titles       = array();

		foreach ( $this->occurrences->objectIds() as $id ) {
			if ( ! in_array( get_post_type( $id ), $allowedTypes, true ) ) {
				continue;
			}

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
	 * Из публичных типов записей — те, что достойны своей строки в списке
	 * «Где встречается». Чистая функция.
	 *
	 * `attachment` — единственное системное исключение: вложения
	 * зарегистрированы публичными (у них есть собственный адрес страницы),
	 * но выбирать медиафайл как «запись, где искать строки» не имеет
	 * смысла — там нет вашего текста, только файл.
	 *
	 * @param list<string> $publicPostTypes Результат `get_post_types(['public' => true])`.
	 * @return list<string>
	 */
	public static function scopeDropdownPostTypes( array $publicPostTypes ): array {
		return array_values( array_diff( $publicPostTypes, array( 'attachment' ) ) );
	}

	/**
	 * Скрытые поля, сохраняющие фильтры при переходе по страницам.
	 *
	 * @param array{locale: string, status: string, search: string, scope: string, object_id: int, type: string, page: int} $filters Текущие фильтры.
	 */
	private function renderHiddenFilters( array $filters ): void {
		$fields = array(
			'page'       => self::MENU_SLUG,
			'mlp_tab'    => self::parseTab( $this->rawTab() ),
			'mlp_locale' => $filters['locale'],
			'mlp_status' => $filters['status'],
			'mlp_scope'  => self::scopeValue( $filters ),
			'mlp_type'   => $filters['type'],
			'mlp_domain' => $filters['domain'] ?? '',
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
	 * @return array{locale: string, status: string, search: string, scope: string, object_id: int, type: string, page: int}
	 */
	private function readFilters( array $secondary ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- фильтры только читают данные.
		$locale = isset( $_GET['mlp_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET['mlp_locale'] ) ) ) : '';
		$status = isset( $_GET['mlp_status'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$scope  = isset( $_GET['mlp_scope'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_scope'] ) ) : '';
		$type   = isset( $_GET['mlp_type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_type'] ) ) : '';
		$domain = isset( $_GET['mlp_domain'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_domain'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $secondary[ $locale ] ) ) {
			$locale = (string) array_key_first( $secondary );
		}

		list( $scopeName, $objectId ) = self::parseScope( $scope );

		/*
		 * Статус на вкладке «Интерфейс» свой: там фильтруют не по стадии
		 * готовности перевода, а по тому, есть ли вообще наше
		 * переопределение поверх языкового пакета.
		 */
		$isInterface = self::TAB_INTERFACE === self::parseTab( $this->rawTab() );

		return array(
			'locale'    => $locale,
			'status'    => $this->parseStatus( $status, $isInterface ),
			'search'    => $search,
			'scope'     => $scopeName,
			'object_id' => $objectId,
			'type'      => self::parseType( $type ),
			'domain'    => $domain,
			'page'      => max( 1, $page ),
		);
	}

	/**
	 * Проверяет значение фильтра статуса по allowlist нужной вкладки.
	 *
	 * @param string $value       Сырое значение параметра.
	 * @param bool   $isInterface Вкладка «Интерфейс» — у неё свои статусы.
	 */
	private function parseStatus( string $value, bool $isInterface ): string {
		if ( $isInterface ) {
			$allowed = array( GettextRepository::STATUS_MISSING, GettextRepository::STATUS_OVERRIDDEN );

			return in_array( $value, $allowed, true ) ? $value : '';
		}

		return TranslationStatus::isValid( $value ) ? $value : '';
	}

	/**
	 * Проверяет значение фильтра типа. Чистая функция.
	 *
	 * Значение приходит из адресной строки, поэтому всё, чего нет в
	 * allowlist, схлопывается в «любой тип» — так же, как parseScope()
	 * поступает с областью поиска.
	 *
	 * @param string $value Сырое значение параметра.
	 */
	public static function parseType( string $value ): string {
		$allowed = array(
			SourceRepository::TYPE_SEO,
			SourceRepository::TYPE_TEXT,
			SourceRepository::TYPE_ATTRIBUTE,
			SourceRepository::TYPE_BLOCK,
		);

		return in_array( $value, $allowed, true ) ? $value : SourceRepository::TYPE_ALL;
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
	 * `content`-атрибут — это meta-тег (SEO/GEO), не обычный атрибут вроде
	 * `alt` или `placeholder`, поэтому у него отдельная подпись, хотя в
	 * колонке `kind` он хранится как `attribute` — своего вида в таблице
	 * `sources` для meta-тегов нет.
	 *
	 * @param string      $kind          Значение колонки kind.
	 * @param string|null $attributeName Имя атрибута из occurrences, если есть.
	 */
	private function kindLabel( string $kind, ?string $attributeName = null ): string {
		if ( Segment::KIND_SEO === $kind || ( Segment::KIND_ATTRIBUTE === $kind && 'content' === $attributeName ) ) {
			return __( 'SEO/GEO', 'wp-mlp' );
		}

		if ( Segment::KIND_HTML_BLOCK === $kind ) {
			return __( 'Блок', 'wp-mlp' );
		}

		return Segment::KIND_ATTRIBUTE === $kind
			? __( 'Атрибут', 'wp-mlp' )
			: __( 'Текст', 'wp-mlp' );
	}
}
