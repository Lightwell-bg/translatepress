<?php
/**
 * Экран визуального редактора.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Rendering\EditorContext;
use WpMlp\Rest\BlocksController;
use WpMlp\Rest\PostTranslationController;
use WpMlp\Rest\TranslationsController;
use WpMlp\Translation\BulkTranslationMode;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Support\Assets;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;
use WpMlp\Translation\ProviderFactory;

/**
 * Панель перевода слева и предпросмотр сайта справа (ТЗ 10.1).
 *
 * Предпросмотр показывается в iframe того же домена и общается с панелью через
 * postMessage. Так скрипт внутри страницы сайта не получает ни nonce, ни права
 * на запись: все запросы к REST уходят отсюда, из админки.
 */
final class EditorPage implements Hookable {

	public const MENU_SLUG  = 'wp-mlp-editor';
	public const CAPABILITY = 'manage_options';

	/**
	 * @param Settings          $settings Настройки плагина.
	 * @param UrlConverter      $urls     Построение языковых адресов.
	 * @param ProviderFactory   $providers Доступы к провайдеру перевода.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly UrlConverter $urls,
		private readonly ProviderFactory $providers
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_bar_menu', array( $this, 'addAdminBarLink' ), 90 );
	}

	/**
	 * Добавляет пункт подменю.
	 */
	public function addMenu(): void {
		add_submenu_page(
			SettingsPage::MENU_SLUG,
			__( 'Визуальный редактор', 'wp-mlp' ),
			__( 'Визуальный редактор', 'wp-mlp' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Кнопка «Перевести страницу» в админ-баре на фронтенде.
	 *
	 * @param \WP_Admin_Bar $bar Админ-бар.
	 */
	public function addAdminBarLink( $bar ): void {
		if ( is_admin() || ! current_user_can( self::CAPABILITY ) || array() === $this->settings->secondary() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'wp-mlp-editor',
				'title' => __( 'Перевести страницу', 'wp-mlp' ),
				'href'  => $this->editorUrl( $this->currentRelativePath() ),
			)
		);
	}

	/**
	 * Подключает скрипты редактора.
	 *
	 * @param string $hook Идентификатор текущего экрана.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wp-mlp-editor', Assets::url( 'assets/editor.css' ), array(), Assets::version( 'assets/editor.css' ) );
		wp_enqueue_script( 'wp-mlp-editor', Assets::url( 'assets/editor.js' ), array(), Assets::version( 'assets/editor.js' ), true );

		$selection = $this->currentSelection();
		$postId    = $this->resolvePostId( $selection['path'] );

		wp_localize_script(
			'wp-mlp-editor',
			'wpMlpEditor',
			array(
				'sources'      => esc_url_raw( rest_url( TranslationsController::NAMESPACE . '/sources/' ) ),
				'saveRoot'     => esc_url_raw( rest_url( TranslationsController::NAMESPACE . '/translations/' ) ),
				'blocks'       => esc_url_raw( rest_url( BlocksController::NAMESPACE . '/blocks' ) ),
				'postRoot'     => esc_url_raw( rest_url( PostTranslationController::NAMESPACE . '/posts/' ) ),
				'postId'       => $postId,
				'modeEmpty'    => BulkTranslationMode::EMPTY,
				'modeAll'      => BulkTranslationMode::ALL,
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'statuses'     => $this->statusLabels(),
				'i18n'         => array(
					'pick'              => __( 'Нажмите на текст в предпросмотре, чтобы перевести его.', 'wp-mlp' ),
					'saving'            => __( 'Сохраняю…', 'wp-mlp' ),
					'saved'             => __( 'Сохранено', 'wp-mlp' ),
					'failed'            => __( 'Не удалось сохранить', 'wp-mlp' ),
					'loading'           => __( 'Загружаю…', 'wp-mlp' ),
					'confirmDelete'     => __( 'Удалить перевод этой строки?', 'wp-mlp' ),
					'confirmBlock'      => __( 'Перевести весь абзац одним куском? Отдельные переводы его частей перестанут применяться.', 'wp-mlp' ),
					'blockCreated'      => __( 'Абзац объединён. Обновляю предпросмотр…', 'wp-mlp' ),
					'attribute'         => __( 'Атрибут', 'wp-mlp' ),
					'text'              => __( 'Текст', 'wp-mlp' ),
					'htmlBlock'         => __( 'Блок с разметкой', 'wp-mlp' ),
					'translating'       => __( 'Перевожу с ИИ…', 'wp-mlp' ),
					'aiSuggested'       => __( 'Предложено ИИ — проверьте перед сохранением', 'wp-mlp' ),
					'aiFailed'          => __( 'ИИ не смог перевести', 'wp-mlp' ),
					'bulkButton'        => __( 'Перевести весь материал с ИИ', 'wp-mlp' ),
					'bulkModeTitle'     => __( 'Что переводить?', 'wp-mlp' ),
					'bulkModeEmpty'     => __( 'Только пустые сегменты', 'wp-mlp' ),
					'bulkModeAll'       => __( 'Перевести заново весь материал', 'wp-mlp' ),
					'bulkStart'         => __( 'Начать', 'wp-mlp' ),
					'bulkCancel'        => __( 'Отмена', 'wp-mlp' ),
					'bulkPreparing'     => __( 'Разбираю запись…', 'wp-mlp' ),
					'bulkProgress'      => __( 'Перевожу часть {current} из {total}…', 'wp-mlp' ),
					'bulkNothing'       => __( 'Переводить нечего — материал уже переведён. Переключите режим на «Перевести заново весь материал», если хотите обновить перевод.', 'wp-mlp' ),
					'bulkReviewTitle'   => __( 'Проверьте перевод перед сохранением', 'wp-mlp' ),
					'bulkSaveAll'       => __( 'Сохранить всё', 'wp-mlp' ),
					'bulkSaving'        => __( 'Сохраняю всё…', 'wp-mlp' ),
					'bulkSaved'         => __( 'Материал сохранён и обновлён в превью.', 'wp-mlp' ),
					'bulkFailed'        => __( 'Не удалось перевести', 'wp-mlp' ),
					'bulkCommitFailed'  => __( 'Не удалось сохранить — изменения отменены, ничего не потеряно.', 'wp-mlp' ),
					'bulkRejected'      => __( 'ИИ не смог безопасно перевести {count} строк(и), не повредив шорткод — переведите их вручную ниже.', 'wp-mlp' ),
					'bulkChanged'       => __( 'изменилось с прошлого перевода', 'wp-mlp' ),
					'bulkFieldTitle'    => __( 'Заголовок', 'wp-mlp' ),
					'bulkFieldExcerpt'  => __( 'Анонс', 'wp-mlp' ),
					'bulkFieldContent'  => __( 'Текст записи', 'wp-mlp' ),
					'bulkClose'         => __( 'Закрыть', 'wp-mlp' ),
				),
			)
		);
	}

	/**
	 * Разбирает `mlp_locale`/`mlp_path` из адресной строки с тем же
	 * фолбэком, что и раньше был только в render(): нужен и enqueue() —
	 * там решается, какую запись резолвить в postId.
	 *
	 * @return array{locale: string, path: string}
	 */
	private function currentSelection(): array {
		$secondary = $this->settings->secondary();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- параметры только выбирают, что показать.
		$locale = isset( $_GET['mlp_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET['mlp_locale'] ) ) ) : '';
		$path   = isset( $_GET['mlp_path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['mlp_path'] ) ) : '/';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $secondary[ $locale ] ) ) {
			$locale = (string) array_key_first( $secondary );
		}

		return array(
			'locale' => $locale,
			'path'   => '/' . ltrim( $path, '/' ),
		);
	}

	/**
	 * Резолвит открытую в редакторе страницу в id записи — с ним и работает
	 * «Перевести весь материал с ИИ» (заголовок/excerpt/post_content, а не
	 * что попало на странице). Не запись — 0, кнопка массового перевода в
	 * разметке просто не появится (см. render()).
	 *
	 * @param string $path Путь без языкового префикса, как в mlp_path.
	 */
	private function resolvePostId( string $path ): int {
		$url = $this->urls->absolute( $path, $this->settings->defaultLanguage() );

		return (int) url_to_postid( $url );
	}

	/**
	 * Выводит экран редактора.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$secondary = $this->settings->secondary();

		if ( array() === $secondary ) {
			printf(
				'<div class="wrap"><h1>%s</h1><div class="notice notice-warning"><p>%s</p></div></div>',
				esc_html__( 'Визуальный редактор', 'wp-mlp' ),
				esc_html__( 'Сначала добавьте хотя бы один дополнительный язык на странице «Языки».', 'wp-mlp' )
			);

			return;
		}

		$selection = $this->currentSelection();
		$locale    = $selection['locale'];
		$path      = $selection['path'];
		$previewer = EditorContext::previewUrl( $this->urls->absolute( $path, $secondary[ $locale ] ) );
		$postId    = $this->resolvePostId( $path );

		?>
		<div class="wrap wp-mlp-editor-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Визуальный редактор', 'wp-mlp' ); ?></h1>

			<form method="get" class="wp-mlp-editor-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">

				<label for="mlp-editor-locale"><?php esc_html_e( 'Язык:', 'wp-mlp' ); ?></label>
				<select name="mlp_locale" id="mlp-editor-locale">
					<?php foreach ( $secondary as $language ) : ?>
						<option value="<?php echo esc_attr( $language->locale ); ?>" <?php selected( $language->locale, $locale ); ?>>
							<?php echo esc_html( sprintf( '%s (%s)', $language->label, $language->locale ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="mlp-editor-path"><?php esc_html_e( 'Страница:', 'wp-mlp' ); ?></label>
				<input type="text" name="mlp_path" id="mlp-editor-path" class="regular-text"
					value="<?php echo esc_attr( $path ); ?>" placeholder="/about/">

				<?php submit_button( __( 'Открыть', 'wp-mlp' ), 'secondary', '', false ); ?>
			</form>

			<div class="wp-mlp-editor" data-locale="<?php echo esc_attr( $locale ); ?>" data-post-id="<?php echo esc_attr( (string) $postId ); ?>">
				<div class="wp-mlp-editor__panel">
					<?php if ( $postId > 0 && $this->providers->isReady() ) : ?>
						<div class="wp-mlp-editor__bulk">
							<button type="button" class="button button-hero" id="mlp-editor-bulk-open">
								<?php esc_html_e( 'Перевести весь материал с ИИ', 'wp-mlp' ); ?>
							</button>
							<p class="description">
								<?php esc_html_e( 'Заголовок, анонс и весь текст записи одной операцией — вместо перевода блок за блоком.', 'wp-mlp' ); ?>
							</p>

							<div class="wp-mlp-editor__bulk-panel" id="mlp-editor-bulk-panel" hidden>
								<fieldset>
									<legend><?php esc_html_e( 'Что переводить?', 'wp-mlp' ); ?></legend>
									<label>
										<input type="radio" name="mlp-bulk-mode" value="<?php echo esc_attr( BulkTranslationMode::EMPTY ); ?>" checked>
										<?php esc_html_e( 'Только пустые сегменты', 'wp-mlp' ); ?>
									</label>
									<label>
										<input type="radio" name="mlp-bulk-mode" value="<?php echo esc_attr( BulkTranslationMode::ALL ); ?>">
										<?php esc_html_e( 'Перевести заново весь материал', 'wp-mlp' ); ?>
									</label>
								</fieldset>

								<div class="wp-mlp-editor__actions">
									<button type="button" class="button button-primary" id="mlp-editor-bulk-start">
										<?php esc_html_e( 'Начать', 'wp-mlp' ); ?>
									</button>
									<button type="button" class="button" id="mlp-editor-bulk-cancel">
										<?php esc_html_e( 'Отмена', 'wp-mlp' ); ?>
									</button>
								</div>

								<p class="wp-mlp-editor__bulk-progress" role="status" hidden></p>
								<p class="wp-mlp-editor__bulk-warning notice notice-warning" hidden></p>

								<ol class="wp-mlp-editor__bulk-list" hidden></ol>

								<div class="wp-mlp-editor__actions wp-mlp-editor__bulk-commit" hidden>
									<button type="button" class="button button-primary" id="mlp-editor-bulk-save">
										<?php esc_html_e( 'Сохранить всё', 'wp-mlp' ); ?>
									</button>
									<button type="button" class="button" id="mlp-editor-bulk-close">
										<?php esc_html_e( 'Закрыть', 'wp-mlp' ); ?>
									</button>
								</div>
							</div>
						</div>
						<hr>
					<?php endif; ?>

					<p class="wp-mlp-editor__hint">
						<?php esc_html_e( 'Нажмите на текст в предпросмотре, чтобы перевести его.', 'wp-mlp' ); ?>
					</p>

					<div class="wp-mlp-editor__form" hidden>
						<p class="wp-mlp-editor__kind"></p>

						<label for="mlp-editor-source"><?php esc_html_e( 'Оригинал', 'wp-mlp' ); ?></label>
						<textarea id="mlp-editor-source" rows="3" readonly></textarea>

						<label for="mlp-editor-target">
							<?php esc_html_e( 'Перевод', 'wp-mlp' ); ?>
						</label>
						<textarea id="mlp-editor-target" rows="5"></textarea>

						<?php if ( $this->providers->isReady() ) : ?>
							<button type="button" class="button" id="mlp-editor-translate">
								<?php esc_html_e( 'Перевести с ИИ', 'wp-mlp' ); ?>
							</button>
						<?php endif; ?>

						<label for="mlp-editor-status"><?php esc_html_e( 'Статус', 'wp-mlp' ); ?></label>
						<select id="mlp-editor-status">
							<?php foreach ( TranslationStatus::all() as $status ) : ?>
								<option value="<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( TranslationStatus::label( $status ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<div class="wp-mlp-editor__actions">
							<button type="button" class="button button-primary" id="mlp-editor-save">
								<?php esc_html_e( 'Сохранить', 'wp-mlp' ); ?>
							</button>
							<button type="button" class="button" id="mlp-editor-delete">
								<?php esc_html_e( 'Удалить перевод', 'wp-mlp' ); ?>
							</button>
						</div>

						<div class="wp-mlp-editor__block" hidden>
							<hr>
							<p class="description">
								<?php esc_html_e( 'Абзац разбит на части инлайновыми тегами. Его можно перевести целиком, вместе с разметкой.', 'wp-mlp' ); ?>
							</p>
							<button type="button" class="button" id="mlp-editor-make-block">
								<?php esc_html_e( 'Перевести абзац целиком', 'wp-mlp' ); ?>
							</button>
						</div>

						<p class="wp-mlp-editor__status" role="status"></p>
					</div>
				</div>

				<iframe class="wp-mlp-editor__preview" id="mlp-editor-preview"
					src="<?php echo esc_url( $previewer ); ?>"
					title="<?php esc_attr_e( 'Предпросмотр страницы', 'wp-mlp' ); ?>"></iframe>
			</div>
		</div>
		<?php
	}

	/**
	 * Названия статусов для панели.
	 *
	 * @return array<string, string>
	 */
	private function statusLabels(): array {
		$labels = array();

		foreach ( TranslationStatus::all() as $status ) {
			$labels[ $status ] = TranslationStatus::label( $status );
		}

		return $labels;
	}

	/**
	 * Адрес экрана редактора для конкретной страницы.
	 *
	 * @param string $path Путь без языкового префикса.
	 */
	private function editorUrl( string $path ): string {
		$secondary = $this->settings->secondary();

		return add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'mlp_locale' => (string) array_key_first( $secondary ),
				'mlp_path'   => $path,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Путь текущей страницы фронтенда без языкового префикса.
	 */
	private function currentRelativePath(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение экранируется в add_query_arg/esc_url.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?? '/' );

		return $this->urls->stripPrefix( LanguageResolver::relativePath( $path, LanguageResolver::basePath() ) );
	}
}
