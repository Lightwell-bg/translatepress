<?php
/**
 * Экран визуального редактора.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Rendering\EditorContext;
use LingoCanvas\Rest\BlocksController;
use LingoCanvas\Rest\EditorPagesController;
use LingoCanvas\Rest\PostTranslationController;
use LingoCanvas\Rest\TranslationsController;
use LingoCanvas\Translation\BulkTranslationMode;
use LingoCanvas\Routing\LanguageResolver;
use LingoCanvas\Routing\UrlConverter;
use LingoCanvas\Settings\Language;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Storage\OccurrenceRepository;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationStatus;
use LingoCanvas\Support\Assets;
use LingoCanvas\Support\Hookable;
use LingoCanvas\Support\Locale;
use LingoCanvas\Translation\ProviderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Панель перевода слева и предпросмотр сайта справа (ТЗ 10.1).
 *
 * Предпросмотр показывается в iframe того же домена и общается с панелью через
 * postMessage: все запросы к REST уходят отсюда, из админки, скрипт внутри
 * превью сам их не делает.
 *
 * Это НЕ изоляция происхождения — same-origin iframe без атрибута `sandbox`
 * технически видит DOM родителя целиком, включая `lingocanvasEditor.nonce`, точно
 * так же, как обычный `<script>` на странице. Единственная граница — что мы
 * туда сами не выводим. Поэтому editor.js ограничивает, куда клик по ссылке в
 * превью может увести этот привилегированный iframe (см. `homeUrl` ниже):
 * без этой границы клик по ссылке на корень домена — где рядом с блогом
 * живёт другое приложение — увёл бы его за пределы плагина, к чужому коду,
 * у которого был бы точно такой же доступ к DOM родителя.
 */
final class EditorPage implements Hookable {

	public const MENU_SLUG  = 'lingocanvas-editor';
	public const CAPABILITY = 'manage_options';

	/**
	 * Секрет канала panel↔предпросмотр, общий на одну загрузку страницы.
	 *
	 * Считается лениво и запоминается, потому что нужен дважды и в разные
	 * моменты запроса: сначала в enqueue() — панели, потом в render() — в
	 * адресе предпросмотра. Сгенерируй его в каждом месте отдельно, и
	 * стороны получили бы РАЗНЫЕ значения, то есть не смогли бы говорить
	 * друг с другом вовсе.
	 */
	private ?string $channelToken = null;

	/**
	 * Построение адресов редактора — общее с REST-поиском и ссылками в админке.
	 */
	private readonly EditorLink $link;

	/**
	 * @param Settings                    $settings    Настройки плагина.
	 * @param UrlConverter                $urls        Построение языковых адресов.
	 * @param ProviderFactory             $providers   Доступы к провайдеру перевода.
	 * @param PostLanguageVisibility|null $visibility  Флаги «показывать на языке»; без них переключателя в редакторе нет.
	 * @param OccurrenceRepository|null   $occurrences Откуда берётся процент перевода страницы.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly UrlConverter $urls,
		private readonly ProviderFactory $providers,
		private readonly ?PostLanguageVisibility $visibility = null,
		private readonly ?OccurrenceRepository $occurrences = null
	) {
		$this->link = new EditorLink( $settings, $urls );
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
			__( 'Visual editor', 'lingocanvas' ),
			__( 'Visual editor', 'lingocanvas' ),
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
				'id'    => 'lingocanvas-editor',
				'title' => __( 'Translate page', 'lingocanvas' ),
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

		wp_enqueue_style( 'lingocanvas-editor', Assets::url( 'assets/editor.css' ), array(), Assets::version( 'assets/editor.css' ) );
		wp_enqueue_script( 'lingocanvas-editor', Assets::url( 'assets/editor.js' ), array(), Assets::version( 'assets/editor.js' ), true );

		// Переключатель «Показывать эту страницу на …» использует общий со списком записей скрипт.
		if ( null !== $this->visibility && $this->settings->isManualPostVisibility() ) {
			VisibilityAssets::enqueue();
		}

		$selection = $this->currentSelection();
		$postId    = $this->resolvePostId( $selection['path'] );

		wp_localize_script(
			'lingocanvas-editor',
			'lingocanvasEditor',
			array(
				'sources'      => esc_url_raw( rest_url( TranslationsController::NAMESPACE . '/sources/' ) ),
				'saveRoot'     => esc_url_raw( rest_url( TranslationsController::NAMESPACE . '/translations/' ) ),
				'blocks'       => esc_url_raw( rest_url( BlocksController::NAMESPACE . '/blocks' ) ),
				'postRoot'     => esc_url_raw( rest_url( PostTranslationController::NAMESPACE . '/posts/' ) ),
				'pagesRoot'    => esc_url_raw( rest_url( EditorPagesController::NAMESPACE . '/editor/pages' ) ),
				'postId'       => $postId,
					// Название открытой записи для поля выбора страницы; не запись — пусто, и поле покажет путь.
					'currentTitle' => $postId > 0 ? wp_strip_all_tags( get_the_title( $postId ) ) : '',
				'modeEmpty'   => BulkTranslationMode::EMPTY,
				'modeAll'      => BulkTranslationMode::ALL,
				/*
				 * Граница, за которую превью не должно уводить: клик по
				 * ссылке в предпросмотре переносит этот же iframe (см.
				 * editor.js, обработчик 'navigate'), а он получает тот же
				 * `nonce` через lingocanvasEditor и ничем от родителя не отделён —
				 * без sandbox-атрибута same-origin iframe видит DOM родителя
				 * целиком. Ссылка на корень домена, где рядом с блогом живёт
				 * другое приложение (частый случай на этом сайте, см.
				 * UrlConverter), увела бы привилегированный iframe за
				 * пределы плагина.
				 */
				'homeUrl'      => esc_url_raw( untrailingslashit( (string) get_option( 'home' ) ) ),
				'channel'      => $this->channelToken(),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'statuses'     => $this->statusLabels(),
				'i18n'         => array(
					'pick'              => __( 'Click text in the preview to translate it.', 'lingocanvas' ),
					'saving'            => __( 'Saving…', 'lingocanvas' ),
					'saved'             => __( 'Saved', 'lingocanvas' ),
					'failed'            => __( 'Could not save', 'lingocanvas' ),
					'loading'           => __( 'Loading…', 'lingocanvas' ),
					'confirmDelete'     => __( 'Delete the translation of this string?', 'lingocanvas' ),
					'confirmBlock'      => __( 'Translate the whole paragraph as one piece? Separate translations of its parts will no longer be applied.', 'lingocanvas' ),
					'blockCreated'      => __( 'Paragraph merged. Refreshing the preview…', 'lingocanvas' ),
				'gettextNotice'     => /* translators: %s: text domain, e.g. woocommerce. */ __( 'This is an interface string (domain: %s). WordPress translates such strings from the language pack; you can adjust the translation under String translation → Interface tab.', 'lingocanvas' ),
				'linkLabel'         => /* translators: %d: link number inside the block. */ __( 'Link #%d', 'lingocanvas' ),
				'collapsePanel'     => __( 'Collapse panel', 'lingocanvas' ),
				'expandPanel'       => __( 'Expand panel', 'lingocanvas' ),
				'attribute'         => __( 'Attribute', 'lingocanvas' ),
					'text'              => __( 'Text', 'lingocanvas' ),
					'htmlBlock'         => __( 'Block with markup', 'lingocanvas' ),
					'translating'       => __( 'Translating with AI…', 'lingocanvas' ),
					'aiSuggested'       => __( 'Suggested by AI — review before saving', 'lingocanvas' ),
					'aiFailed'          => __( 'AI could not translate', 'lingocanvas' ),
					'bulkButton'        => __( 'Translate the whole post with AI', 'lingocanvas' ),
					'bulkModeTitle'     => __( 'What to translate?', 'lingocanvas' ),
					'bulkModeEmpty'     => __( 'Empty segments only', 'lingocanvas' ),
					'bulkModeAll'       => __( 'Retranslate the whole post', 'lingocanvas' ),
					'bulkStart'         => __( 'Start', 'lingocanvas' ),
					'bulkCancel'        => __( 'Cancel', 'lingocanvas' ),
					'bulkPreparing'     => __( 'Analyzing the post…', 'lingocanvas' ),
					'bulkProgress'      => __( 'Translating part {current} of {total}…', 'lingocanvas' ),
					'bulkNothing'       => __( 'Nothing to translate — the post is already translated. Switch the mode to “Retranslate the whole post” if you want to refresh the translation.', 'lingocanvas' ),
					'bulkReviewTitle'   => __( 'Review the translation before saving', 'lingocanvas' ),
					'bulkSaveAll'       => __( 'Save all', 'lingocanvas' ),
					'bulkSaving'        => __( 'Saving all…', 'lingocanvas' ),
					'bulkSaved'         => __( 'The post was saved and the preview was updated.', 'lingocanvas' ),
					'bulkFailed'        => __( 'Could not translate', 'lingocanvas' ),
				'bulkPartialFailed' => __( 'Not everything was translated: {reason}. The finished part is below: save it with the “Save all” button, then click “Start” again — in “Empty segments only” mode only what is left will be translated, with no repeat charge for what is already translated.', 'lingocanvas' ),
					'bulkCommitFailed'  => __( 'Could not save — the changes were rolled back, nothing was lost.', 'lingocanvas' ),
					'bulkRejected'      => __( 'AI could not safely translate {count} string(s) without breaking a shortcode — translate them manually below.', 'lingocanvas' ),
					'bulkChanged'       => __( 'changed since the last translation', 'lingocanvas' ),
					'bulkFieldTitle'    => __( 'Title', 'lingocanvas' ),
					'bulkFieldExcerpt'  => __( 'Excerpt', 'lingocanvas' ),
					'bulkFieldContent'  => __( 'Post content', 'lingocanvas' ),
					'bulkFieldThumbnailAlt' => __( 'Thumbnail alt', 'lingocanvas' ),
					'bulkClose'         => __( 'Close', 'lingocanvas' ),
					'pickerPlaceholder' => __( 'Find a page by title or paste a URL', 'lingocanvas' ),
					'pickerLoading'     => __( 'Loading…', 'lingocanvas' ),
					'pickerEmpty'       => __( 'Nothing found', 'lingocanvas' ),
					'pickerError'       => __( 'Could not load the list of pages', 'lingocanvas' ),
				),
			)
		);
	}

	/**
	 * Превращает адрес страницы в путь для `lingocanvas_path`. Чистая функция.
	 *
	 * Клик по ссылке внутри предпросмотра присылает адрес целиком, а
	 * редактору нужен путь без базового пути установки и без языкового
	 * префикса. Разбор живёт здесь, на сервере, а не в JS, по той же
	 * причине, по которой три копии проверки хоста разошлись между собой:
	 * второе воплощение одного правила рано или поздно начинает отвечать
	 * иначе, чем первое.
	 *
	 * Всё, что за пределами установки, схлопывается в `/`: на корне этого
	 * домена живёт отдельный сайт со своими `/ru/`, `/en/`, `/bg/`, и его
	 * страницы редактор открыть не может — показать вместо них главную
	 * честнее, чем случайный путь.
	 *
	 * @param string       $url      Адрес, по которому щёлкнули.
	 * @param string       $basePath Базовый путь установки, `` для корня домена.
	 * @param list<string> $slugs    Слаги всех языков сайта.
	 * @param string       $homeHost Хост самого сайта.
	 */
	public static function pathFromUrl( string $url, string $basePath, array $slugs, string $homeHost ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
			return '/';
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( '' !== $host && '' !== $homeHost && strtolower( $homeHost ) !== $host ) {
			return '/';
		}

		$path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );

		// Вне установки: адрес соседнего сайта на том же домене.
		if ( '' !== $basePath && $path !== $basePath && ! str_starts_with( $path, $basePath . '/' ) ) {
			return '/';
		}

		$relative = LanguageResolver::relativePath( $path, $basePath );

		return '/' . ltrim( UrlConverter::removePrefixFromPath( $relative, '', $slugs ), '/' );
	}

	/**
	 * Секрет канала для этой загрузки страницы редактора.
	 *
	 * Только буквы и цифры: значение уходит в адрес предпросмотра, и
	 * экранировать его там не придётся.
	 */
	private function channelToken(): string {
		if ( null === $this->channelToken ) {
			$this->channelToken = wp_generate_password( 32, false );
		}

		return $this->channelToken;
	}

	/**
	 * Разбирает `lingocanvas_locale`/`lingocanvas_path` из адресной строки с тем же
	 * фолбэком, что и раньше был только в render(): нужен и enqueue() —
	 * там решается, какую запись резолвить в postId.
	 *
	 * @return array{locale: string, path: string}
	 */
	private function currentSelection(): array {
		$secondary = $this->settings->secondary();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- параметры только выбирают, что показать.
		$locale = isset( $_GET['lingocanvas_locale'] ) ? Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET['lingocanvas_locale'] ) ) ) : '';
		$path   = isset( $_GET['lingocanvas_path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['lingocanvas_path'] ) ) : '/';

		// Вставленный адрес или путь с языковым префиксом приводим к виду «без префикса»: единственное место правила.
		$path = $this->link->normalizeInput( $path );

		/*
		 * Переход по ссылке внутри предпросмотра: адрес приходит целиком и
		 * перекрывает `lingocanvas_path`. Страница редактора при этом загружается
		 * заново целиком — иначе `postId` и список строк остались бы от
		 * предыдущей страницы, и «Перевести весь материал» ушло бы не в ту
		 * запись, молча.
		 */
		if ( isset( $_GET['lingocanvas_url'] ) ) {
			$path = self::pathFromUrl(
				sanitize_text_field( wp_unslash( (string) $_GET['lingocanvas_url'] ) ),
				LanguageResolver::basePath(),
				$this->urls->knownSlugs(),
				UrlConverter::homeHost()
			);
		}
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
	 * @param string $path Путь без языкового префикса, как в lingocanvas_path.
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
				esc_html__( 'Visual editor', 'lingocanvas' ),
				esc_html__( 'First add at least one additional language on the “Languages” page.', 'lingocanvas' )
			);

			return;
		}

		$selection = $this->currentSelection();
		$locale    = $selection['locale'];
		$path      = $selection['path'];
		$previewer = EditorContext::previewUrl( $this->urls->absolute( $path, $secondary[ $locale ] ), $this->channelToken() );
		$postId    = $this->resolvePostId( $path );

		?>
		<div class="wrap wp-mlp-editor-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Visual editor', 'lingocanvas' ); ?></h1>

			<form method="get" class="wp-mlp-editor-toolbar">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">

				<label for="mlp-editor-locale"><?php esc_html_e( 'Language:', 'lingocanvas' ); ?></label>
				<select name="lingocanvas_locale" id="mlp-editor-locale">
					<?php foreach ( $secondary as $language ) : ?>
						<option value="<?php echo esc_attr( $language->locale ); ?>" <?php selected( $language->locale, $locale ); ?>>
							<?php echo esc_html( sprintf( '%s (%s)', $language->label, $language->locale ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="mlp-editor-path"><?php esc_html_e( 'Page:', 'lingocanvas' ); ?></label>
				<input type="text" name="lingocanvas_path" id="mlp-editor-path" class="regular-text"
					value="<?php echo esc_attr( $path ); ?>" placeholder="/about/" autocomplete="off">

				<?php submit_button( __( 'Open', 'lingocanvas' ), 'secondary', '', false ); ?>
			</form>

			<?php
			/*
			 * Панель занимает 360px, и на обычном ноутбуке превью остаётся
			 * уже того порога, на котором тема переключается на мобильную
			 * вёрстку: вместо меню появляется гамбургер, а клик по нему
			 * открывает выезжающую панель поверх всего превью. Работать в
			 * таком виде невозможно, а на широком мониторе проблемы нет —
			 * поэтому нужна не перестройка вёрстки, а способ временно
			 * убрать панель и вернуть превью полную ширину.
			 */
			?>
			<p class="wp-mlp-editor-toolbar__aside">
				<button type="button" class="button" id="mlp-editor-toggle-panel"
					aria-expanded="true" aria-controls="mlp-editor-panel">
					<?php esc_html_e( 'Collapse panel', 'lingocanvas' ); ?>
				</button>
				<span class="description">
					<?php esc_html_e( 'The preview gets the full width — the theme shows the regular menu instead of the mobile one.', 'lingocanvas' ); ?>
				</span>
			</p>

			<div class="wp-mlp-editor" data-locale="<?php echo esc_attr( $locale ); ?>" data-post-id="<?php echo esc_attr( (string) $postId ); ?>">
				<div class="wp-mlp-editor__panel" id="mlp-editor-panel">
					<?php echo wp_kses( $this->visibilityBlock( $postId, $secondary[ $locale ] ), AllowedHtml::editorVisibilityBlock() ); ?>
					<?php if ( $postId > 0 && $this->providers->isReady() ) : ?>
						<div class="wp-mlp-editor__bulk">
							<button type="button" class="button button-hero" id="mlp-editor-bulk-open">
								<?php esc_html_e( 'Translate the whole post with AI', 'lingocanvas' ); ?>
							</button>
							<p class="description">
								<?php esc_html_e( 'Title, excerpt and the full post content in one operation — instead of translating block by block.', 'lingocanvas' ); ?>
							</p>

							<div class="wp-mlp-editor__bulk-panel" id="mlp-editor-bulk-panel" hidden>
								<fieldset>
									<legend><?php esc_html_e( 'What to translate?', 'lingocanvas' ); ?></legend>
									<label>
										<input type="radio" name="lingocanvas-bulk-mode" value="<?php echo esc_attr( BulkTranslationMode::EMPTY ); ?>" checked>
										<?php esc_html_e( 'Empty segments only', 'lingocanvas' ); ?>
									</label>
									<label>
										<input type="radio" name="lingocanvas-bulk-mode" value="<?php echo esc_attr( BulkTranslationMode::ALL ); ?>">
										<?php esc_html_e( 'Retranslate the whole post', 'lingocanvas' ); ?>
									</label>
								</fieldset>

								<div class="wp-mlp-editor__actions">
									<button type="button" class="button button-primary" id="mlp-editor-bulk-start">
										<?php esc_html_e( 'Start', 'lingocanvas' ); ?>
									</button>
									<button type="button" class="button" id="mlp-editor-bulk-cancel">
										<?php esc_html_e( 'Cancel', 'lingocanvas' ); ?>
									</button>
								</div>

								<p class="wp-mlp-editor__bulk-progress" role="status" hidden></p>
								<p class="wp-mlp-editor__bulk-warning notice notice-warning" hidden></p>

								<ol class="wp-mlp-editor__bulk-list" hidden></ol>

								<div class="wp-mlp-editor__actions wp-mlp-editor__bulk-commit" hidden>
									<button type="button" class="button button-primary" id="mlp-editor-bulk-save">
										<?php esc_html_e( 'Save all', 'lingocanvas' ); ?>
									</button>
									<button type="button" class="button" id="mlp-editor-bulk-close">
										<?php esc_html_e( 'Close', 'lingocanvas' ); ?>
									</button>
								</div>
							</div>
						</div>
						<hr>
					<?php endif; ?>

					<p class="wp-mlp-editor__hint">
						<?php esc_html_e( 'Click text in the preview to translate it.', 'lingocanvas' ); ?>
					</p>

					<div class="wp-mlp-editor__form" hidden>
						<p class="wp-mlp-editor__kind"></p>

						<label for="mlp-editor-source"><?php esc_html_e( 'Original', 'lingocanvas' ); ?></label>
						<textarea id="mlp-editor-source" rows="3" readonly></textarea>

						<label for="mlp-editor-target">
							<?php esc_html_e( 'Translation', 'lingocanvas' ); ?>
						</label>
						<textarea id="mlp-editor-target" rows="5"></textarea>

						<?php if ( $this->providers->isReady() ) : ?>
							<button type="button" class="button" id="mlp-editor-translate">
								<?php esc_html_e( 'Translate with AI', 'lingocanvas' ); ?>
							</button>
						<?php endif; ?>

						<label for="mlp-editor-status"><?php esc_html_e( 'Status', 'lingocanvas' ); ?></label>
						<select id="mlp-editor-status">
							<?php foreach ( TranslationStatus::all() as $status ) : ?>
								<option value="<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( TranslationStatus::label( $status ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<div class="wp-mlp-editor__actions">
							<button type="button" class="button button-primary" id="mlp-editor-save">
								<?php esc_html_e( 'Save', 'lingocanvas' ); ?>
							</button>
							<button type="button" class="button" id="mlp-editor-delete">
								<?php esc_html_e( 'Delete translation', 'lingocanvas' ); ?>
							</button>
						</div>

						<?php
						/*
						 * Ссылки блока отдельными полями. Своего хранилища у
						 * них нет и не нужно: адрес и так лежит внутри HTML
						 * этого перевода, а поле — только вид на него, чтобы
						 * не искать `href` глазами среди тегов и не сломать
						 * разметку, правя её руками.
						 */
						?>
						<div class="wp-mlp-editor__links" id="mlp-editor-links" hidden>
							<hr>
							<p class="description">
								<?php esc_html_e( 'Links in this block. The address applies to the current language only — other languages have their own.', 'lingocanvas' ); ?>
							</p>
							<div class="wp-mlp-editor__links-list"></div>
						</div>

						<div class="wp-mlp-editor__block" hidden>
							<hr>
							<p class="description">
								<?php esc_html_e( 'The paragraph is split into parts by inline tags. You can translate it as a whole, together with the markup.', 'lingocanvas' ); ?>
							</p>
							<button type="button" class="button" id="mlp-editor-make-block">
								<?php esc_html_e( 'Translate the whole paragraph', 'lingocanvas' ); ?>
							</button>
						</div>

						<p class="wp-mlp-editor__status" role="status"></p>
					</div>
				</div>

				<iframe class="wp-mlp-editor__preview" id="mlp-editor-preview"
					src="<?php echo esc_url( $previewer ); ?>"
					title="<?php esc_attr_e( 'Page preview', 'lingocanvas' ); ?>"></iframe>
			</div>
		</div>
		<?php
	}

	/**
	 * Блок «Показывать эту страницу на <язык>» с процентом перевода.
	 *
	 * Только когда открыта запись или страница, включён ручной режим и у
	 * пользователя есть право править эту запись. Иначе — пустая строка.
	 *
	 * @param int      $postId   Открытая в редакторе запись (0 — не запись).
	 * @param Language $language Язык редактора.
	 */
	private function visibilityBlock( int $postId, Language $language ): string {
		if (
			$postId <= 0
			|| null === $this->visibility
			|| ! $this->settings->isManualPostVisibility()
			|| ! $this->visibility->isEligiblePost( $postId )
			|| ! current_user_can( 'edit_post', $postId )
			|| ! current_user_can( self::CAPABILITY )
		) {
			return '';
		}

		$progress = '';

		if ( null !== $this->occurrences ) {
			list( $total, $done ) = $this->occurrences->translationProgress( $postId, $language->locale );
			list( $state, $pct )  = PostListLanguages::classify( $total, $done );

			switch ( $state ) {
				case PostListLanguages::STATE_FULL:
					$progress = __( 'Fully translated', 'lingocanvas' );
					break;
				case PostListLanguages::STATE_PARTIAL:
					$progress = sprintf( /* translators: %d: percent of strings translated. */ __( '%d%% translated', 'lingocanvas' ), $pct );
					break;
				case PostListLanguages::STATE_NONE:
					$progress = __( 'Nothing translated yet', 'lingocanvas' );
					break;
				default:
					$progress = __( 'This page has no strings yet', 'lingocanvas' );
			}
		}

		$html = '<div class="wp-mlp-editor__visibility">'
			. VisibilityAssets::toggleHtml(
				$postId,
				$language->locale,
				$this->visibility->isVisible( $postId, $language->locale ),
				sprintf( /* translators: %s: language name. */ __( 'Show this page in %s', 'lingocanvas' ), $language->label )
			);

		if ( '' !== $progress ) {
			$html .= '<p class="description">' . esc_html( $progress ) . '</p>';
		}

		return $html . '</div><hr>';
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
		return $this->link->urlForPath( $path );
	}

	/**
	 * Путь текущей страницы фронтенда без языкового префикса.
	 */
	private function currentRelativePath(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?? '/' );

		return $this->link->relativePath( $path );
	}
}
