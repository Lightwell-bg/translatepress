<?php
/**
 * Админ-страница «Языки».
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Frontend\Flags;
use LingoCanvas\Rest\OpenAiModelsController;
use LingoCanvas\Frontend\Sitemap;
use LingoCanvas\I18n\LanguagePacks;
use LingoCanvas\Settings\Language;
use LingoCanvas\Settings\Settings;
use LingoCanvas\Settings\SwitcherDisplay;
use LingoCanvas\Rest\PostVisibilityController;
use LingoCanvas\Storage\PostLanguageVisibility;
use LingoCanvas\Storage\TranslationCache;
use LingoCanvas\Support\Assets;
use LingoCanvas\Support\Env;
use LingoCanvas\Support\Hookable;
use LingoCanvas\Support\Locale;
use LingoCanvas\Translation\ProviderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Список языков сайта: код, URL-слаг, название, статус, язык по умолчанию.
 */
final class SettingsPage implements Hookable {

	public const MENU_SLUG   = 'lingocanvas';
	public const CAPABILITY  = 'manage_options';
	public const ACTION_SAVE = 'lingocanvas_save_settings';

	/**
	 * Имя submit-кнопки «Скачать языковой пакет»; её значение — локаль.
	 *
	 * Кнопка живёт внутри той же формы настроек, а не в отдельном
	 * admin-post: так она бесплатно получает и проверку прав, и nonce, и —
	 * главное — сохранение только что введённой локали ДО попытки скачать
	 * для неё пакет. Отдельным действием пришлось бы либо дублировать всё
	 * это, либо качать пакет для старого, ещё не сохранённого значения.
	 */
	private const FIELD_DOWNLOAD_PACK = 'lingocanvas_download_pack';

	/**
	 * @param Settings      $settings Настройки плагина.
	 * @param LanguagePacks $packs    Языковые пакеты WordPress.
	 * @param ProviderFactory $providers Откуда берутся доступы к OpenAI.
	 * @param PostLanguageVisibility|null $visibility Счётчики и массовое включение записей на языке.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguagePacks $packs,
		private readonly ProviderFactory $providers,
		private readonly ?PostLanguageVisibility $visibility = null,
		private readonly ?CloudSection $cloud = null
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handleSave' ) );
		add_action( 'admin_notices', array( $this, 'maybeShowPermalinkNotice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Сохраняет и удаляет картинки флагов по данным отправленной формы.
	 *
	 * Строки формы пронумерованы, и номер связывает файл (`flag_file_3`) с
	 * языком (`languages[3]`). Брать код языка из имени файла нельзя: файл
	 * называется как угодно, а нужен именно тот язык, в чьей строке его
	 * выбрали.
	 *
	 * @param array<string, mixed> $input Данные формы (уже без слешей).
	 * @return list<string> Сообщения об ошибках.
	 */
	private function handleFlagFiles( array $input ): array {
		$languages = is_array( $input['languages'] ?? null ) ? $input['languages'] : array();
		$errors    = array();

		foreach ( $languages as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$locale = Locale::normalize( (string) ( $row['locale'] ?? '' ) );

			if ( ! Locale::isValid( $locale ) ) {
				continue;
			}

			// Язык удаляют — вместе с ним уходит и его картинка.
			if ( ! empty( $row['delete'] ) ) {
				FlagUpload::remove( $locale );

				continue;
			}

			if ( ! empty( $row['flag_remove'] ) ) {
				FlagUpload::remove( $locale );
			}

			$field = 'flag_file_' . $index;

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверен в handleSave(); содержимое файла проверяет и очищает FlagUpload (размер, разбор SVG), имя не используется.
			$file = $_FILES[ $field ] ?? null;

			if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				continue;
			}

			$errors = array_merge( $errors, $this->storeFlagFile( $locale, $file ) );
		}

		return $errors;
	}

	/**
	 * Кладёт один загруженный файл флага.
	 *
	 * @param string              $locale Код языка.
	 * @param array<string, mixed> $file   Элемент $_FILES.
	 * @return list<string> Сообщения об ошибках.
	 */
	private function storeFlagFile( string $locale, array $file ): array {
		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return array( sprintf( /* translators: %s: language code. */ __( 'The flag file for language "%s" failed to upload. Please try again.', 'lingocanvas' ), $locale ) );
		}

		$path = (string) ( $file['tmp_name'] ?? '' );

		/*
		 * is_uploaded_file() отсекает попытку подсунуть путь к любому файлу
		 * сервера вместо загруженного — без неё содержимое чужого файла
		 * можно было бы переписать в картинку флага.
		 */
		if ( '' === $path || ! is_uploaded_file( $path ) ) {
			return array( sprintf( /* translators: %s: language code. */ __( 'The flag file for language "%s" failed to upload. Please try again.', 'lingocanvas' ), $locale ) );
		}

		$content = (string) file_get_contents( $path );

		if ( FlagUpload::store( $locale, $content ) ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: 1: language code, 2: maximum file size in kilobytes */
				__( 'The flag for language "%1$s" was not saved: an SVG file up to %2$d KB is required. The plugin does not accept other formats.', 'lingocanvas' ),
				$locale,
				(int) ( FlagUpload::MAX_BYTES / 1000 )
			),
		);
	}

	/**
	 * Подключает скрипт перестановки языков.
	 *
	 * @param string $hook Идентификатор текущего экрана.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'lingocanvas-settings',
			Assets::url( 'assets/settings.css' ),
			array(),
			Assets::version( 'assets/settings.css' )
		);
		wp_enqueue_script(
			'lingocanvas-settings',
			Assets::url( 'assets/settings.js' ),
			array(),
			Assets::version( 'assets/settings.js' ),
			true
		);
		wp_localize_script(
			'lingocanvas-settings',
			'lingocanvasSettings',
			array(
				'modelsUrl' => esc_url_raw( rest_url( OpenAiModelsController::NAMESPACE . '/openai/models' ) ),
				'enableUrl' => esc_url_raw( rest_url( PostVisibilityController::NAMESPACE . '/posts/visibility/enable-translated' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'saved'         => __( '(saved)', 'lingocanvas' ),
					'custom'        => __( 'Other model…', 'lingocanvas' ),
					'refresh'       => __( 'Refresh list', 'lingocanvas' ),
					'loading'       => __( 'Loading list…', 'lingocanvas' ),
					'error'         => __( 'Could not load the model list', 'lingocanvas' ),
					'enabling'      => __( 'Enabling…', 'lingocanvas' ),
					'enableFailed'  => __( 'Could not enable posts', 'lingocanvas' ),
					'enabledResult' => /* translators: %d: number of posts enabled by the click. */ __( 'Posts enabled: %d', 'lingocanvas' ),
				),
			)
		);
	}

	/**
	 * Регистрирует пункт меню плагина.
	 */
	public function addMenu(): void {
		add_menu_page(
			__( 'Multilingual', 'lingocanvas' ),
			__( 'Multilingual', 'lingocanvas' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-translation',
			76
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Languages', 'lingocanvas' ),
			__( 'Languages', 'lingocanvas' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Языковые URL работают только на «красивых» пермалинках: при пустой
	 * структуре rewrite-правила не применяются и /en/ отдал бы 404.
	 */
	public function maybeShowPermalinkNotice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || '' !== (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: %s: link to permalink settings */
					__( 'LingoCanvas requires pretty permalinks. Open %s and choose any structure except "Plain".', 'lingocanvas' ),
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings → Permalinks', 'lingocanvas' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	/**
	 * Поля формы настроек, которые читаются как одна строка (см. postedInput()).
	 */
	private const TEXT_FIELDS = array(
		'default_locale',
		'switcher_display',
		'discover_strings',
		'discover_from_visitors',
		'delete_data_on_uninstall',
		'openai_daily_char_limit',
		'openai_api_key',
		'openai_api_key_clear',
		'openai_model',
		'provider',
		'cloud_consent',
		self::FIELD_DOWNLOAD_PACK,
	);

	/**
	 * Ключи строки таблицы языков, которые понимает Settings::sanitize() и handleFlagFiles().
	 */
	private const LANGUAGE_ROW_FIELDS = array( 'locale', 'slug', 'label', 'flag', 'wp_locale', 'status', 'delete', 'flag_remove' );

	/**
	 * Собирает из $_POST только те поля, которые читают Settings::sanitize(), handleFlagFiles()
	 * и кнопка скачивания языкового пакета. Чужие ключи отбрасываются, значения очищаются
	 * в момент чтения; Settings::sanitize() остаётся вторым слоем защиты.
	 *
	 * Вызывать только после проверки прав и nonce (handleSave()).
	 *
	 * @return array<string, mixed> Данные без слешей в форме, которую ждёт Settings::sanitize().
	 */
	public function postedInput(): array {
		$input = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверен в handleSave() до вызова; значения очищаются в той же строке чтения (массив языков — через map_deep() + sanitize_text_field).
		foreach ( self::TEXT_FIELDS as $field ) {
			if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) {
				$input[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['untranslated_posts_mode'] ) && is_scalar( $_POST['untranslated_posts_mode'] ) ) {
			$input['untranslated_posts_mode'] = sanitize_key( wp_unslash( (string) $_POST['untranslated_posts_mode'] ) );
		}

		if ( isset( $_POST['openai_base_url'] ) && is_scalar( $_POST['openai_base_url'] ) ) {
			$input['openai_base_url'] = esc_url_raw( trim( wp_unslash( (string) $_POST['openai_base_url'] ) ) );
		}

		/*
		 * Слаги для карты сайта вводят по одному на строку. Процентное кодирование
		 * (так выглядит кириллический адрес, скопированный из адресной строки)
		 * sanitize_textarea_field() вырезает целиком, поэтому оно декодируется заранее;
		 * Settings::sanitize() снова кодирует слаг через sanitize_title() так же, как
		 * ядро делает это для post_name.
		 */
		if ( isset( $_POST['sitemap_excluded_slugs'] ) && is_scalar( $_POST['sitemap_excluded_slugs'] ) ) {
			$input['sitemap_excluded_slugs'] = sanitize_textarea_field( rawurldecode( wp_unslash( (string) $_POST['sitemap_excluded_slugs'] ) ) );
		}

		if ( isset( $_POST['languages'] ) && is_array( $_POST['languages'] ) ) {
			$input['languages'] = $this->postedLanguages( map_deep( wp_unslash( $_POST['languages'] ), 'sanitize_text_field' ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return $input;
	}

	/**
	 * Оставляет в таблице языков только известные поля строк. Номера строк сохраняются:
	 * по ним handleFlagFiles() находит файл `flag_file_<номер>`.
	 *
	 * @param mixed $rows Уже очищенный через sanitize_text_field() массив строк.
	 * @return array<int|string, array<string, string>>
	 */
	private function postedLanguages( mixed $rows ): array {
		$clean = array();

		foreach ( is_array( $rows ) ? $rows : array() as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = is_int( $index ) ? $index : sanitize_key( (string) $index );

			if ( '' === $key ) {
				continue;
			}

			foreach ( self::LANGUAGE_ROW_FIELDS as $field ) {
				if ( ! isset( $row[ $field ] ) || ! is_scalar( $row[ $field ] ) ) {
					continue;
				}

				$value = (string) $row[ $field ];

				$clean[ $key ][ $field ] = 'status' === $field ? sanitize_key( $value ) : $value;
			}

			// Строка без известных полей всё равно строка: номер нужен для flag_file_<номер>.
			$clean[ $key ] ??= array();
		}

		return $clean;
	}

	/**
	 * Принимает форму настроек.
	 */
	public function handleSave(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'lingocanvas' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_SAVE );

		$input  = $this->postedInput();
		$result = $this->settings->sanitize( $input );

		$modeBefore = $this->settings->untranslatedPostsMode();

		$this->settings->save( $result['settings'] );

		/*
		 * Смена режима показа записей меняет состав списков, меню и карты сайта
		 * на дополнительных языках, поэтому готовые страницы в кэше и карту
		 * сайта нужно сбросить — иначе посетители ещё долго видели бы старое.
		 */
		if ( $this->settings->untranslatedPostsMode() !== $modeBefore ) {
			( new TranslationCache() )->flush();
		}

		/*
		 * Флаги обрабатываются ПОСЛЕ сохранения языков: имя файла — это код
		 * языка, а его могли ввести или поправить прямо сейчас, этой же
		 * отправкой формы.
		 */
		$flagErrors = $this->handleFlagFiles( $input );

		// Слаги языков входят в rewrite-правила — их нужно пересобрать.
		flush_rewrite_rules();

		$result['errors'] = array_merge( $result['errors'], $flagErrors );

		$query = array( 'page' => self::MENU_SLUG );

		/*
		 * Пакет качается уже ПОСЛЕ сохранения: локаль могли ввести прямо
		 * сейчас, в этой же отправке формы, и качать нужно именно её.
		 */
		$requestedPack = isset( $input[ self::FIELD_DOWNLOAD_PACK ] )
			? Locale::sanitizeWpLocale( (string) $input[ self::FIELD_DOWNLOAD_PACK ] )
			: '';

		if ( '' !== $requestedPack ) {
			$query['lingocanvas-pack'] = $this->packs->install( $requestedPack ) ? 'ok' : 'failed';
		}

		if ( array() === $result['errors'] ) {
			$query['lingocanvas-updated'] = '1';
		} else {
			set_transient( 'lingocanvas_settings_errors_' . get_current_user_id(), $result['errors'], MINUTE_IN_SECONDS );
			$query['lingocanvas-errors'] = '1';
		}

		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Под выбором режима: сколько записей включено на каждом языке и кнопка «включить все переведённые».
	 *
	 * Предупреждение «язык будет пустым» выводится скрытым для каждого языка,
	 * где включённых записей нет; settings.js показывает его, пока выбран
	 * ручной режим, и прячет, когда кнопка включила хоть одну запись.
	 */
	private function renderVisibilityCounts(): void {
		if ( null === $this->visibility || array() === $this->settings->secondary() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="mlp-vis-counts">';

		foreach ( $this->settings->secondary() as $language ) {
			$count = $this->visibility->count( $language->locale );

			printf(
				'<p class="mlp-vis-lang" data-locale="%1$s" data-count="%2$d">%3$s <strong class="mlp-vis-count">%2$d</strong> <button type="button" class="button mlp-enable-translated" data-locale="%1$s">%4$s</button> <span class="mlp-vis-msg" role="status" aria-live="polite"></span></p>',
				esc_attr( $language->locale ),
				absint( $count ),
				esc_html( sprintf( /* translators: %s: language name. */ __( 'Currently shown in %s — posts and pages:', 'lingocanvas' ), $language->label ) ),
				esc_html( sprintf( /* translators: %s: language name. */ __( 'Enable all posts fully translated into %s', 'lingocanvas' ), $language->label ) )
			);

			printf(
				'<p class="notice notice-warning inline mlp-vis-warning" data-locale="%1$s" hidden="hidden">%2$s</p>',
				esc_attr( $language->locale ),
				esc_html( sprintf( /* translators: %s: language name. */ __( 'No posts are enabled for %s — the language will be empty after saving.', 'lingocanvas' ), $language->label ) )
			);
		}

		echo '</div>';
	}

	/**
	 * Выводит страницу.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$languages = $this->settings->all();
		$raw       = $this->settings->raw();
		$index     = 0;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site languages', 'lingocanvas' ); ?></h1>

			<?php $this->renderNotices(); ?>

			<p class="description">
				<?php esc_html_e( 'The default language is served at the regular site addresses, without a prefix. Other languages live under their own prefix, for example /en/. A draft language is not available to visitors and is not included in hreflang.', 'lingocanvas' ); ?>
			</p>

			<p class="description">
				<strong><?php esc_html_e( 'What each column means:', 'lingocanvas' ); ?></strong>
				<?php esc_html_e( '"Language code" is a short name used inside the plugin. "URL slug" is what appears in the address: /bg/article/. Visitors see "Name" and "Flag" in the language switcher. "WordPress locale" is the name under which WordPress looks up translations of its own interface (bg_BG, en_US); it is why "Reply" becomes "Отговор" without any effort on your part.', 'lingocanvas' ); ?>
			</p>

			<?php // enctype нужен для загрузки картинок флагов в таблице ниже. ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<table class="widefat striped mlp-languages-table" style="max-width:60em;margin-bottom:1em;">
					<thead>
						<tr>
							<th scope="col" class="mlp-order-column">
								<span class="screen-reader-text"><?php esc_html_e( 'Order', 'lingocanvas' ); ?></span>
							</th>
							<th scope="col"><?php esc_html_e( 'Language code', 'lingocanvas' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL slug', 'lingocanvas' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Name', 'lingocanvas' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Flag', 'lingocanvas' ); ?></th>
							<th scope="col">
								<?php esc_html_e( 'WordPress locale', 'lingocanvas' ); ?>
								<span class="dashicons dashicons-editor-help"
									title="<?php esc_attr_e( 'The name under which WordPress stores translations of its own interface: en_US, bg_BG, ru_RU. Core, the theme and plugins use it to find their translation files and show "Ответить" instead of "Reply". Do not confuse it with the language code on the left, which goes into the page address.', 'lingocanvas' ); ?>"></span>
							</th>
							<th scope="col"><?php esc_html_e( 'Status', 'lingocanvas' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Delete', 'lingocanvas' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $languages as $language ) : ?>
							<?php $this->renderRow( $index++, $language ); ?>
						<?php endforeach; ?>
						<?php
						/*
						 * Без JS остаётся ровно одна пустая строка: выбора из
						 * списка нет, язык вписывают руками. С JS скрипт её
						 * убирает — добавлять языки можно выбором ниже, и их
						 * число не ограничено.
						 */
						$this->renderRow( $index++, null, 'blank' );
						?>
					</tbody>
				</table>

				<template id="mlp-language-row-template">
					<?php $this->renderRow( 0, null, 'template' ); ?>
				</template>

				<?php $this->renderLanguagePicker( $languages ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlp-default-locale"><?php esc_html_e( 'Default language', 'lingocanvas' ); ?></label></th>
						<td>
							<select name="default_locale" id="mlp-default-locale">
								<?php foreach ( $languages as $language ) : ?>
									<option value="<?php echo esc_attr( $language->locale ); ?>" <?php selected( $language->isDefault ); ?>>
										<?php echo esc_html( sprintf( '%s (%s)', $language->label, $language->locale ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The language in which you write content in WordPress. Add and save a new language first; only then can you select it here.', 'lingocanvas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-switcher-display"><?php esc_html_e( 'Show in switcher', 'lingocanvas' ); ?></label></th>
						<td>
							<?php $display = $this->settings->switcherDisplay(); ?>
							<select name="switcher_display" id="mlp-switcher-display">
								<option value="<?php echo esc_attr( SwitcherDisplay::LABEL ); ?>" <?php selected( SwitcherDisplay::LABEL, $display ); ?>>
									<?php esc_html_e( 'Language name', 'lingocanvas' ); ?>
								</option>
								<option value="<?php echo esc_attr( SwitcherDisplay::CODE ); ?>" <?php selected( SwitcherDisplay::CODE, $display ); ?>>
									<?php esc_html_e( 'Language code (RU, EN)', 'lingocanvas' ); ?>
								</option>
								<option value="<?php echo esc_attr( SwitcherDisplay::FLAG ); ?>" <?php selected( SwitcherDisplay::FLAG, $display ); ?>>
									<?php esc_html_e( 'Flag only', 'lingocanvas' ); ?>
								</option>
								<option value="<?php echo esc_attr( SwitcherDisplay::FLAG_CODE ); ?>" <?php selected( SwitcherDisplay::FLAG_CODE, $display ); ?>>
									<?php esc_html_e( 'Flag and language code', 'lingocanvas' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'The order of languages in the switcher follows the order of rows in the table above — rearrange them with the arrows on the left.', 'lingocanvas' ); ?>
								<br>
								<?php
								printf(
									/* translators: %s: path to the flags directory */
									esc_html__( 'The flag is taken from the file %s, named by the language code from the first column. If there is no file, the emoji entered by hand is shown, and if there is none, the language code. More about flags is in "Help" at the top right.', 'lingocanvas' ),
									/* translators: placeholder for a language code in a file name, shown as /<code>.svg */
									'<code>' . esc_html( 'wp-content/uploads/' . Flags::DIRECTORY . '/<' . __( 'code', 'lingocanvas' ) . '>.svg' ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'String collection', 'lingocanvas' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="discover_strings" value="1" <?php checked( ! empty( $raw['discover_strings'] ) ); ?>>
								<?php esc_html_e( 'Remember new strings when I (an administrator) open a page in an additional language', 'lingocanvas' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The plugin cannot know in advance what text the theme will output; it finds out only when a page is actually displayed. So the translation list fills up like this: you log in to the admin, open a page in an additional language, the plugin remembers everything it found on it, and those strings appear in "String translation". Only what you chose to open ends up in the list.', 'lingocanvas' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'If the site sits behind a page cache plugin, administrators usually bypass it, so collection works. Turning it off only makes sense once the site is fully translated.', 'lingocanvas' ); ?>
								</p>
								<p>
									<label>
										<input type="checkbox" name="discover_from_visitors" value="1" <?php checked( ! empty( $raw['discover_from_visitors'] ) ); ?>>
										<?php esc_html_e( 'Also remember strings from pages opened by visitors', 'lingocanvas' ); ?>
									</label>
								</p>
								<p class="description">
									<?php esc_html_e( 'Usually not needed: the list will fill with the text of every page anyone opened, even pages you never meant to translate.', 'lingocanvas' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemap', 'lingocanvas' ); ?></th>
						<td>
							<?php $sitemapUrl = home_url( '/' . Sitemap::FILE ); ?>
							<p>
								<a href="<?php echo esc_url( $sitemapUrl ); ?>" target="_blank" rel="noopener noreferrer">
									<code><?php echo esc_html( $sitemapUrl ); ?></code>
								</a>
							</p>
							<p class="description">
								<?php esc_html_e( 'Contains all language versions with reciprocal hreflang links, which tell Google these are one page in different languages, not duplicates. Untranslated pages are not included in the sitemap.', 'lingocanvas' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'The address is already added to robots.txt, so search engines will find it on their own. Submitting it manually in Google Search Console is faster.', 'lingocanvas' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-sitemap-excluded"><?php esc_html_e( 'Exclude from sitemap', 'lingocanvas' ); ?></label></th>
						<td>
							<textarea name="sitemap_excluded_slugs" id="mlp-sitemap-excluded" class="large-text code" rows="4"
								placeholder="oformlenie-zakaza&#10;net-dostupa"><?php echo esc_textarea( implode( "\n", $this->settings->sitemapExcludedSlugs() ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One slug per line: the last segment of the page address, without slashes (for /blog/checkout/ enter checkout). A page with child pages is excluded together with them.', 'lingocanvas' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'You do not always need to fill this in: WooCommerce cart, checkout and account pages, as well as pages marked noindex in Yoast SEO, Rank Math or SEOPress, are excluded automatically without this field. Enter here only what is left without a cart plugin and without a noindex mark — for example, a technical "No access" page or a manually created checkout page.', 'lingocanvas' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Untranslated posts', 'lingocanvas' ); ?></th>
						<td>
							<fieldset id="mlp-untranslated-posts-mode">
								<legend class="screen-reader-text"><span><?php esc_html_e( 'Untranslated posts', 'lingocanvas' ); ?></span></legend>
								<p>
									<label>
										<input type="radio" name="untranslated_posts_mode" value="<?php echo esc_attr( Settings::POSTS_ALL ); ?>" <?php checked( $this->settings->untranslatedPostsMode(), Settings::POSTS_ALL ); ?>>
										<?php esc_html_e( 'Show all posts (translated or not)', 'lingocanvas' ); ?>
									</label>
								</p>
								<p>
									<label>
										<input type="radio" name="untranslated_posts_mode" value="<?php echo esc_attr( Settings::POSTS_MANUAL ); ?>" <?php checked( $this->settings->untranslatedPostsMode(), Settings::POSTS_MANUAL ); ?>>
										<?php esc_html_e( 'In each language, show only posts enabled manually (recommended)', 'lingocanvas' ); ?>
									</label>
								</p>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'In manual mode, a post or page appears in an additional language (in lists, menus, the sitemap and by direct link) only when you have enabled it for that language. The rest link to the default language version.', 'lingocanvas' ); ?>
							</p>
							<?php $this->renderVisibilityCounts(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin uninstall', 'lingocanvas' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $raw['delete_data_on_uninstall'] ) ); ?>>
								<?php esc_html_e( 'Delete all translations and tables when the plugin is uninstalled', 'lingocanvas' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default: after the plugin is uninstalled, translations stay in the database and are restored if you reinstall it.', 'lingocanvas' ); ?></p>
						</td>
					</tr>
					<?php if ( null !== $this->cloud ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI translation provider', 'lingocanvas' ); ?></th>
						<td><?php echo wp_kses( $this->cloud->providerHtml(), AllowedHtml::cloudSection() ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'LingoCanvas Cloud', 'lingocanvas' ); ?></th>
						<td><?php echo wp_kses( $this->cloud->cloudHtml(), AllowedHtml::cloudSection() ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="mlp-openai-key"><?php esc_html_e( 'OpenAI key', 'lingocanvas' ); ?></label></th>
						<td>
							<?php $this->renderOpenAiKeyStatus(); ?>
							<input type="password" name="openai_api_key" id="mlp-openai-key"
								class="regular-text" autocomplete="off"
								<?php wp_readonly( $this->providers->isOverriddenByConstant( ProviderFactory::CONST_API_KEY ) ); ?>
								placeholder="<?php echo esc_attr( $this->settings->openAiApiKey() !== '' ? __( '••••••••  (leave empty to keep unchanged)', 'lingocanvas' ) : 'sk-...' ); ?>">
							<?php if ( $this->settings->openAiApiKey() !== '' ) : ?>
								<label>
									<input type="checkbox" name="openai_api_key_clear" value="1">
									<?php esc_html_e( 'Delete the saved key', 'lingocanvas' ); ?>
								</label>
							<?php endif; ?>
							<p class="description">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to OpenAI API keys page */
										__( 'Stored in the site database, not in files. Get a key: %s.', 'lingocanvas' ),
										'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
									),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-openai-model"><?php esc_html_e( 'OpenAI model', 'lingocanvas' ); ?></label></th>
						<td>
							<?php
							$modelLocked = $this->providers->isOverriddenByConstant( ProviderFactory::CONST_MODEL );
							$hasKey      = '' !== trim( $this->providers->apiKey() );
							?>
							<div class="mlp-model-field" data-enhance="<?php echo esc_attr( ( $hasKey && ! $modelLocked ) ? '1' : '0' ); ?>">
								<input type="text" name="openai_model" id="mlp-openai-model" class="regular-text"
									<?php wp_readonly( $modelLocked ); ?>
									value="<?php echo esc_attr( $this->settings->openAiModel() ); ?>" placeholder="gpt-4o-mini">
							</div>
							<?php if ( ! $hasKey && ! $modelLocked ) : ?>
								<p class="description"><?php esc_html_e( 'Save the OpenAI key first — then a list of models will appear here.', 'lingocanvas' ); ?></p>
							<?php endif; ?>
							<?php if ( $modelLocked ) : ?>
								<p class="description"><?php echo esc_html( $this->overriddenNote( ProviderFactory::CONST_MODEL ) ); ?></p>
							<?php elseif ( '' === trim( $this->providers->model() ) ) : ?>
								<p class="description" style="color:#b32d2e;">
									<?php esc_html_e( 'The field is empty — without it the "Translate with AI" button will not appear, even if a key is saved.', 'lingocanvas' ); ?>
								</p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Choose a model from the list returned by the API, or enter the identifier manually ("Other model…").', 'lingocanvas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-openai-base"><?php esc_html_e( 'API address', 'lingocanvas' ); ?></label></th>
						<td>
							<input type="text" name="openai_base_url" id="mlp-openai-base" class="regular-text"
								<?php wp_readonly( $this->providers->isOverriddenByConstant( ProviderFactory::CONST_BASE_URL ) ); ?>
								value="<?php echo esc_attr( $this->settings->openAiBaseUrlExplicit() ); ?>"
								placeholder="<?php echo esc_attr( Settings::DEFAULT_OPENAI_BASE_URL ); ?>">
							<?php if ( $this->providers->isOverriddenByConstant( ProviderFactory::CONST_BASE_URL ) ) : ?>
								<p class="description"><?php echo esc_html( $this->overriddenNote( ProviderFactory::CONST_BASE_URL ) ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Change only for your own proxy or a compatible gateway instead of api.openai.com.', 'lingocanvas' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-openai-limit"><?php esc_html_e( 'Daily character limit', 'lingocanvas' ); ?></label></th>
						<td>
							<input type="number" name="openai_daily_char_limit" id="mlp-openai-limit"
								min="0" max="10000000" step="1000"
								value="<?php echo esc_attr( (string) $this->settings->openAiDailyCharLimit() ); ?>">
							<p class="description"><?php esc_html_e( 'How many characters in total can be sent to OpenAI per day by clicking "Translate with AI". A safeguard against accidental spending, not automation — the plugin never translates anything on its own.', 'lingocanvas' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save', 'lingocanvas' ) ); ?>
			</form>
			<?php
			if ( null !== $this->cloud ) {
				echo wp_kses( $this->cloud->formsHtml(), AllowedHtml::cloudSection() );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Текст «поле перекрыто константой» для модели и адреса API.
	 *
	 * @param string $constName Одна из ProviderFactory::CONST_*.
	 */
	private function overriddenNote( string $constName ): string {
		return sprintf(
			/* translators: %s: constant name */
			__( 'The value is set in wp-config.php (%s); the field above is not used.', 'lingocanvas' ),
			$constName
		);
	}

	/**
	 * Показывает, откуда взят ключ, — без самого ключа.
	 */
	private function renderOpenAiKeyStatus(): void {
		echo wp_kses( $this->keyStatusHtml(), AllowedHtml::keyStatus() );
	}

	/**
	 * HTML строки статуса ключа OpenAI. Весь вывод экранирован.
	 *
	 * В HTML попадают только последние 4 символа сохранённого в БД ключа для
	 * подтверждения «это тот ключ, что я вводил» — этого недостаточно, чтобы
	 * восстановить ключ целиком, но достаточно, чтобы отличить его от чужого
	 * (ТЗ 13). Для остальных источников ключ не показывается вовсе.
	 */
	public function keyStatusHtml(): string {
		$plain = static fn( string $text ): string => sprintf( '<p class="description">%s</p>', esc_html( $text ) );

		switch ( $this->providers->apiKeySource() ) {
			case ProviderFactory::SOURCE_CONSTANT:
				return $plain(
					sprintf(
						/* translators: %s: constant name */
						__( 'The key is set in wp-config.php (%s); the field below is not used.', 'lingocanvas' ),
						ProviderFactory::CONST_API_KEY
					)
				);

			case ProviderFactory::SOURCE_DATABASE:
				return $plain(
					sprintf(
						/* translators: %s: last 4 characters of the saved key */
						__( 'The key is stored in the database and survives plugin updates. Ends with "%s".', 'lingocanvas' ),
						substr( $this->settings->openAiApiKey(), -4 )
					)
				);

			case ProviderFactory::SOURCE_CONTENT_ENV:
				$html = $plain( __( 'The key is read from wp-content/lingocanvas.env.php and survives plugin updates.', 'lingocanvas' ) );
				break;

			case ProviderFactory::SOURCE_PARENT_ENV:
				$html = $plain( __( 'The key is read from a lingocanvas.env file above the WordPress root and survives plugin updates.', 'lingocanvas' ) );
				break;

			case ProviderFactory::SOURCE_PLUGIN_ENV:
				$html = sprintf(
					'<div class="notice notice-warning inline"><p>%s</p></div>',
					esc_html__( 'The key is read from .env in the plugin folder; it will be deleted when the plugin is replaced via ZIP. Paste the key into the field below or move it to wp-content/lingocanvas.env.php.', 'lingocanvas' )
				);
				break;

			case ProviderFactory::SOURCE_PROCESS_ENV:
				$html = $plain( __( 'The key is set by a server environment variable.', 'lingocanvas' ) );
				break;

			default:
				$html = $plain( __( 'No key saved.', 'lingocanvas' ) );
		}

		// Файл в wp-content без защитной строки читался бы по прямой ссылке — не используем.
		if ( array() !== Env::unguardedFiles() ) {
			$html .= sprintf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'The lingocanvas.env.php file has no protective first line and is not used. The first line must be: <?php exit; ?>', 'lingocanvas' )
			);
		}

		return $html;
	}

	/**
	 * Поле «Добавить язык»: поиск по списку языков WordPress.
	 *
	 * Без JS поле и кнопка скрыты (атрибут hidden), а вместо них работает
	 * пустая строка таблицы. Любую локаль, которой нет в списке, можно
	 * вписать руками: список — подсказка, а не ограничение.
	 *
	 * @param array<string, Language> $languages Уже добавленные языки.
	 */
	private function renderLanguagePicker( array $languages ): void {
		$existing = array();

		foreach ( $languages as $language ) {
			$existing[] = $language->wpLocale;
		}

		$options = LanguageCatalog::options( $existing );

		?>
		<div class="mlp-language-picker" hidden>
			<label for="mlp-language-picker-input"><strong><?php esc_html_e( 'Add language', 'lingocanvas' ); ?></strong></label>
			<input type="text" id="mlp-language-picker-input" list="mlp-language-picker-list"
				class="regular-text" autocomplete="off" autocapitalize="off" spellcheck="false"
				data-1p-ignore data-lpignore="true" data-form-type="other"
				data-invalid="<?php esc_attr_e( 'Choose a language from the list or enter a locale such as de_DE.', 'lingocanvas' ); ?>"
				placeholder="<?php esc_attr_e( 'Start typing: Deutsch, French, pt_BR…', 'lingocanvas' ); ?>">
			<datalist id="mlp-language-picker-list">
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['label'] ); ?>"
						data-locale="<?php echo esc_attr( $option['locale'] ); ?>"
						data-code="<?php echo esc_attr( $option['code'] ); ?>"
						data-flag="<?php echo esc_attr( $option['flag'] ); ?>"
						data-native="<?php echo esc_attr( $option['native'] ); ?>"></option>
				<?php endforeach; ?>
			</datalist>
			<button type="button" class="button" id="mlp-language-picker-add"><?php esc_html_e( 'Add', 'lingocanvas' ); ?></button>
			<p class="description">
				<?php esc_html_e( 'Choose a language from the WordPress list or enter its locale manually (for example, de_DE). The number of languages is not limited. Do not forget to click "Save" at the bottom of the page.', 'lingocanvas' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Строка таблицы языков.
	 *
	 * @param int           $index    Индекс строки в форме.
	 * @param Language|null $language Существующий язык или null для пустой строки.
	 * @param string        $mode     '' — сохранённый язык; 'blank' — единственная пустая строка
	 *                                без JS; 'template' — заготовка для строк, которые добавляет JS.
	 */
	private function renderRow( int $index, ?Language $language, string $mode = '' ): void {
		$isTemplate = 'template' === $mode;
		$name       = 'languages[' . ( $isTemplate ? '__INDEX__' : (string) $index ) . ']';

		/*
		 * Атрибуты против автозаполнения: менеджер паролей принимал поле
		 * локали за поле логина и подставлял в него «admin».
		 */
		?>
		<?php if ( 'blank' === $mode ) : ?>
		<tr class="mlp-row-blank">
		<?php else : ?>
		<tr>
		<?php endif; ?>
			<td class="mlp-order-column">
				<?php if ( null !== $language || $isTemplate ) : ?>
					<?php
					/*
					 * Порядок строк здесь и есть порядок языков в
					 * переключателе: браузер отправляет поля формы в том
					 * порядке, в каком они стоят в разметке, а sanitize()
					 * складывает языки в том порядке, в каком их получил.
					 * Отдельного поля с номером поэтому не нужно.
					 *
					 * Кнопки без JS ничего не делают, и это осознанно: без
					 * скриптов строку всё равно некуда переставить, а
					 * неработающая кнопка честнее скрыта, чем показана
					 * (см. wp-mlp-order в admin.js).
					 */
					?>
					<button type="button" class="button-link mlp-order-up" hidden
						title="<?php esc_attr_e( 'Up', 'lingocanvas' ); ?>">
						<span class="dashicons dashicons-arrow-up-alt2"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Move language up', 'lingocanvas' ); ?></span>
					</button>
					<button type="button" class="button-link mlp-order-down" hidden
						title="<?php esc_attr_e( 'Down', 'lingocanvas' ); ?>">
						<span class="dashicons dashicons-arrow-down-alt2"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Move language down', 'lingocanvas' ); ?></span>
					</button>
				<?php endif; ?>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[locale]' ); ?>"
					value="<?php echo esc_attr( $language->locale ?? '' ); ?>"
					placeholder="<?php echo esc_attr( null === $language ? __( 'e.g. de', 'lingocanvas' ) : '' ); ?>"
					size="10" pattern="[A-Za-z-]{2,20}" autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[slug]' ); ?>"
					value="<?php echo esc_attr( $language->slug ?? '' ); ?>"
					placeholder="<?php echo esc_attr( null === $language ? __( 'same as code', 'lingocanvas' ) : '' ); ?>"
					size="10" autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[label]' ); ?>"
					value="<?php echo esc_attr( $language->label ?? '' ); ?>"
					placeholder="<?php echo esc_attr( null === $language ? __( 'e.g. Deutsch', 'lingocanvas' ) : '' ); ?>"
					autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other">
			</td>
			<td class="mlp-flag-cell">
				<?php $flagUrl = null !== $language ? Flags::url( $language->locale ) : ''; ?>

				<?php if ( '' !== $flagUrl ) : ?>
					<?php
					/*
					 * Отметка времени в адресе — чтобы после замены файла
					 * браузер показал новую картинку, а не ту же из кэша:
					 * имя файла при замене не меняется.
					 */
					$version = (string) @filemtime( Flags::filePath( $language->locale ) );
					?>
					<img class="mlp-flag-preview" src="<?php echo esc_url( add_query_arg( 'v', $version, $flagUrl ) ); ?>"
						alt="" width="24" height="18">
					<label class="mlp-flag-remove">
						<input type="checkbox" name="<?php echo esc_attr( $name . '[flag_remove]' ); ?>" value="1">
						<?php esc_html_e( 'remove', 'lingocanvas' ); ?>
					</label>
				<?php endif; ?>

				<?php if ( null !== $language ) : ?>
					<input type="file" name="<?php echo esc_attr( 'flag_file_' . $index ); ?>"
						accept=".svg,image/svg+xml" class="mlp-flag-file"
						title="<?php esc_attr_e( 'Flag image (SVG). It will be saved under the language code from the first column.', 'lingocanvas' ); ?>">
				<?php endif; ?>

				<input type="text" name="<?php echo esc_attr( $name . '[flag]' ); ?>"
					value="<?php echo esc_attr( $language->flag ?? '' ); ?>"
					size="4" maxlength="16" autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other"
					title="<?php esc_attr_e( 'Fallback: an emoji flag. Shown if no image is uploaded. Windows does not render emoji flags; letters are shown there instead.', 'lingocanvas' ); ?>">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[wp_locale]' ); ?>"
					value="<?php echo esc_attr( $language->wpLocale ?? '' ); ?>"
					placeholder="<?php echo esc_attr( null === $language ? __( 'e.g. de_DE', 'lingocanvas' ) : '' ); ?>"
					size="10" autocomplete="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other"
					title="<?php esc_attr_e( 'WordPress locale: core, the theme and plugins look up their translations under this name', 'lingocanvas' ); ?>">
				<?php if ( $isTemplate ) : ?>
					<p class="description"><?php esc_html_e( 'The language pack will be offered after saving', 'lingocanvas' ); ?></p>
				<?php endif; ?>
				<?php $this->renderLanguagePackStatus( $language ); ?>
			</td>
			<td>
				<?php if ( null !== $language && $language->isDefault ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name . '[status]' ); ?>" value="<?php echo esc_attr( Language::STATUS_PUBLISHED ); ?>">
					<em><?php esc_html_e( 'default', 'lingocanvas' ); ?></em>
				<?php else : ?>
					<select name="<?php echo esc_attr( $name . '[status]' ); ?>">
						<option value="<?php echo esc_attr( Language::STATUS_PUBLISHED ); ?>" <?php selected( Language::STATUS_PUBLISHED, $language->status ?? Language::STATUS_PUBLISHED ); ?>>
							<?php esc_html_e( 'Published', 'lingocanvas' ); ?>
						</option>
						<option value="<?php echo esc_attr( Language::STATUS_DRAFT ); ?>" <?php selected( Language::STATUS_DRAFT, $language->status ?? '' ); ?>>
							<?php esc_html_e( 'Draft', 'lingocanvas' ); ?>
						</option>
					</select>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( null !== $language && ! $language->isDefault ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name . '[delete]' ); ?>" value="1">
						<?php esc_html_e( 'Delete language', 'lingocanvas' ); ?>
					</label>
				<?php elseif ( $isTemplate ) : ?>
					<button type="button" class="button-link mlp-row-remove">
						<span aria-hidden="true">&#10005;</span>
						<?php esc_html_e( 'Delete language', 'lingocanvas' ); ?>
					</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Показывает, установлен ли языковой пакет, и предлагает скачать его.
	 *
	 * Без пакета подмена локали (LocaleSwitcher) внешне «не работает»:
	 * ядро и тема возвращают английские оригиналы, а причину по фронтенду
	 * не увидеть — поэтому состояние показывается явно, рядом с полем.
	 *
	 * @param Language|null $language Существующий язык или null для пустой строки формы.
	 */
	private function renderLanguagePackStatus( ?Language $language ): void {
		if ( null === $language || '' === $language->wpLocale ) {
			return;
		}

		if ( ! LanguagePacks::needsPack( $language->wpLocale ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Built-in locale: no pack needed.', 'lingocanvas' )
			);

			return;
		}

		if ( $this->packs->isInstalled( $language->wpLocale ) ) {
			printf(
				'<p class="description" style="color:#007017;">%s</p>',
				esc_html__( 'Language pack installed.', 'lingocanvas' )
			);

			return;
		}

		printf(
			'<p class="description" style="color:#b32d2e;">%s</p>',
			esc_html__( 'No language pack: interface strings will stay in English.', 'lingocanvas' )
		);

		if ( ! $this->packs->canInstall() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Automatic installation is unavailable: no write permission for wp-content/languages, or access to api.wordpress.org is blocked. Upload the translation files manually.', 'lingocanvas' )
			);

			return;
		}

		printf(
			'<button type="submit" class="button button-secondary" name="%s" value="%s">%s</button>',
			esc_attr( self::FIELD_DOWNLOAD_PACK ),
			esc_attr( $language->wpLocale ),
			esc_html__( 'Download language pack', 'lingocanvas' )
		);
	}

	/**
	 * Сообщения после сохранения.
	 */
	private function renderNotices(): void {
		$this->cloud?->renderNotice();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- только чтение флагов из URL.
		if ( isset( $_GET['lingocanvas-updated'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Settings saved.', 'lingocanvas' )
			);
		}

		if ( isset( $_GET['lingocanvas-pack'] ) ) {
			$installed = 'ok' === sanitize_text_field( wp_unslash( (string) $_GET['lingocanvas-pack'] ) );

			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $installed ? 'success' : 'error' ),
				esc_html(
					$installed
						? __( 'Language pack installed.', 'lingocanvas' )
						: __( 'Could not download the language pack. Check that a translation for this locale exists on translate.wordpress.org and that the site is allowed to access api.wordpress.org.', 'lingocanvas' )
				)
			);
		}

		if ( ! isset( $_GET['lingocanvas-errors'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$key    = 'lingocanvas_settings_errors_' . get_current_user_id();
		$errors = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $errors ) ) {
			return;
		}

		foreach ( $errors as $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( (string) $error ) );
		}
	}
}
