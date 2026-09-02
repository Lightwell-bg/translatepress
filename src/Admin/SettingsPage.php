<?php
/**
 * Админ-страница «Языки».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Frontend\Flags;
use WpMlp\Frontend\Sitemap;
use WpMlp\I18n\LanguagePacks;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Settings\SwitcherDisplay;
use WpMlp\Support\Assets;
use WpMlp\Support\Env;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;

/**
 * Список языков сайта: код, URL-слаг, название, статус, язык по умолчанию.
 */
final class SettingsPage implements Hookable {

	public const MENU_SLUG   = 'wp-mlp';
	public const CAPABILITY  = 'manage_options';
	public const ACTION_SAVE = 'mlp_save_settings';

	/**
	 * Имя submit-кнопки «Скачать языковой пакет»; её значение — локаль.
	 *
	 * Кнопка живёт внутри той же формы настроек, а не в отдельном
	 * admin-post: так она бесплатно получает и проверку прав, и nonce, и —
	 * главное — сохранение только что введённой локали ДО попытки скачать
	 * для неё пакет. Отдельным действием пришлось бы либо дублировать всё
	 * это, либо качать пакет для старого, ещё не сохранённого значения.
	 */
	private const FIELD_DOWNLOAD_PACK = 'mlp_download_pack';

	/**
	 * @param Settings      $settings Настройки плагина.
	 * @param LanguagePacks $packs    Языковые пакеты WordPress.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguagePacks $packs
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

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен в handleSave().
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
			/* translators: %s: language code */
			return array( sprintf( __( 'Файл флага для языка «%s» не загрузился — попробуйте ещё раз.', 'wp-mlp' ), $locale ) );
		}

		$path = (string) ( $file['tmp_name'] ?? '' );

		/*
		 * is_uploaded_file() отсекает попытку подсунуть путь к любому файлу
		 * сервера вместо загруженного — без неё содержимое чужого файла
		 * можно было бы переписать в картинку флага.
		 */
		if ( '' === $path || ! is_uploaded_file( $path ) ) {
			/* translators: %s: language code */
			return array( sprintf( __( 'Файл флага для языка «%s» не загрузился — попробуйте ещё раз.', 'wp-mlp' ), $locale ) );
		}

		$content = (string) file_get_contents( $path );

		if ( FlagUpload::store( $locale, $content ) ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: 1: language code, 2: maximum file size in kilobytes */
				__( 'Флаг для языка «%1$s» не сохранён: нужен файл SVG размером до %2$d КБ. Другие форматы плагин не принимает.', 'wp-mlp' ),
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
			'wp-mlp-settings',
			Assets::url( 'assets/settings.css' ),
			array(),
			Assets::version( 'assets/settings.css' )
		);
		wp_enqueue_script(
			'wp-mlp-settings',
			Assets::url( 'assets/settings.js' ),
			array(),
			Assets::version( 'assets/settings.js' ),
			true
		);
	}

	/**
	 * Регистрирует пункт меню плагина.
	 */
	public function addMenu(): void {
		add_menu_page(
			__( 'Мультиязычность', 'wp-mlp' ),
			__( 'Мультиязычность', 'wp-mlp' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-translation',
			76
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Языки', 'wp-mlp' ),
			__( 'Языки', 'wp-mlp' ),
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
					__( 'WP Multilang Press требует «красивые» постоянные ссылки. Откройте %s и выберите любую структуру, кроме «Простые».', 'wp-mlp' ),
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Настройки → Постоянные ссылки', 'wp-mlp' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	/**
	 * Принимает форму настроек.
	 */
	public function handleSave(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав для изменения настроек.', 'wp-mlp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_SAVE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$input  = wp_unslash( $_POST );
		$result = $this->settings->sanitize( is_array( $input ) ? $input : array() );

		$this->settings->save( $result['settings'] );

		/*
		 * Флаги обрабатываются ПОСЛЕ сохранения языков: имя файла — это код
		 * языка, а его могли ввести или поправить прямо сейчас, этой же
		 * отправкой формы.
		 */
		$flagErrors = $this->handleFlagFiles( is_array( $input ) ? $input : array() );

		// Слаги языков входят в rewrite-правила — их нужно пересобрать.
		flush_rewrite_rules();

		$result['errors'] = array_merge( $result['errors'], $flagErrors );

		$query = array( 'page' => self::MENU_SLUG );

		/*
		 * Пакет качается уже ПОСЛЕ сохранения: локаль могли ввести прямо
		 * сейчас, в этой же отправке формы, и качать нужно именно её.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен выше.
		$requestedPack = isset( $input[ self::FIELD_DOWNLOAD_PACK ] )
			? Locale::sanitizeWpLocale( (string) $input[ self::FIELD_DOWNLOAD_PACK ] )
			: '';

		if ( '' !== $requestedPack ) {
			$query['mlp-pack'] = $this->packs->install( $requestedPack ) ? 'ok' : 'failed';
		}

		if ( array() === $result['errors'] ) {
			$query['mlp-updated'] = '1';
		} else {
			set_transient( 'mlp_settings_errors_' . get_current_user_id(), $result['errors'], MINUTE_IN_SECONDS );
			$query['mlp-errors'] = '1';
		}

		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
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
			<h1><?php esc_html_e( 'Языки сайта', 'wp-mlp' ); ?></h1>

			<?php $this->renderNotices(); ?>

			<p class="description">
				<?php esc_html_e( 'Язык по умолчанию отдаётся по обычным адресам сайта, без префикса. Остальные языки живут на своём префиксе, например /en/. Черновой язык недоступен посетителям и не попадает в hreflang.', 'wp-mlp' ); ?>
			</p>

			<p class="description">
				<strong><?php esc_html_e( 'Что в какой колонке:', 'wp-mlp' ); ?></strong>
				<?php esc_html_e( '«Код языка» — короткое имя внутри плагина. «URL-слаг» — то, что появится в адресе: /bg/статья/. «Название» и «Флаг» видит посетитель в переключателе языков. «Локаль WordPress» — имя, под которым WordPress ищет переводы своего интерфейса (bg_BG, en_US); именно благодаря ей «Reply» становится «Отговор» без вашего участия.', 'wp-mlp' ); ?>
			</p>

			<?php // enctype нужен для загрузки картинок флагов в таблице ниже. ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<table class="widefat striped mlp-languages-table" style="max-width:60em;margin-bottom:1em;">
					<thead>
						<tr>
							<th scope="col" class="mlp-order-column">
								<span class="screen-reader-text"><?php esc_html_e( 'Порядок', 'wp-mlp' ); ?></span>
							</th>
							<th scope="col"><?php esc_html_e( 'Код языка', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL-слаг', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Название', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Флаг', 'wp-mlp' ); ?></th>
							<th scope="col">
								<?php esc_html_e( 'Локаль WordPress', 'wp-mlp' ); ?>
								<span class="dashicons dashicons-editor-help"
									title="<?php esc_attr_e( 'Имя, под которым WordPress хранит переводы своего интерфейса: en_US, bg_BG, ru_RU. По нему ядро, тема и плагины находят свои файлы перевода и сами отдают «Ответить» вместо «Reply». Не путать с кодом языка слева — тот идёт в адрес страницы.', 'wp-mlp' ); ?>"></span>
							</th>
							<th scope="col"><?php esc_html_e( 'Статус', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Удалить', 'wp-mlp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $languages as $language ) : ?>
							<?php $this->renderRow( $index++, $language ); ?>
						<?php endforeach; ?>
						<?php for ( $i = 0; $i < 2; $i++ ) : ?>
							<?php $this->renderRow( $index++, null ); ?>
						<?php endfor; ?>
					</tbody>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mlp-default-locale"><?php esc_html_e( 'Язык по умолчанию', 'wp-mlp' ); ?></label></th>
						<td>
							<select name="default_locale" id="mlp-default-locale">
								<?php foreach ( $languages as $language ) : ?>
									<option value="<?php echo esc_attr( $language->locale ); ?>" <?php selected( $language->isDefault ); ?>>
										<?php echo esc_html( sprintf( '%s (%s)', $language->label, $language->locale ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Язык, на котором вы пишете контент в WordPress. Новый язык сначала добавьте и сохраните, только потом его можно выбрать здесь.', 'wp-mlp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-switcher-display"><?php esc_html_e( 'Показывать в переключателе', 'wp-mlp' ); ?></label></th>
						<td>
							<?php $display = $this->settings->switcherDisplay(); ?>
							<select name="switcher_display" id="mlp-switcher-display">
								<option value="<?php echo esc_attr( SwitcherDisplay::LABEL ); ?>" <?php selected( SwitcherDisplay::LABEL, $display ); ?>>
									<?php esc_html_e( 'Название языка', 'wp-mlp' ); ?>
								</option>
								<option value="<?php echo esc_attr( SwitcherDisplay::CODE ); ?>" <?php selected( SwitcherDisplay::CODE, $display ); ?>>
									<?php esc_html_e( 'Код языка (RU, EN)', 'wp-mlp' ); ?>
								</option>
								<option value="<?php echo esc_attr( SwitcherDisplay::FLAG ); ?>" <?php selected( SwitcherDisplay::FLAG, $display ); ?>>
									<?php esc_html_e( 'Только флаг', 'wp-mlp' ); ?>
								</option>
								<option value="<?php echo esc_attr( SwitcherDisplay::FLAG_CODE ); ?>" <?php selected( SwitcherDisplay::FLAG_CODE, $display ); ?>>
									<?php esc_html_e( 'Флаг и код языка', 'wp-mlp' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Порядок языков в переключателе задаётся порядком строк в таблице выше — переставьте их стрелками слева.', 'wp-mlp' ); ?>
								<br>
								<?php
								printf(
									/* translators: %s: path to the flags directory */
									esc_html__( 'Флаг берётся из файла %s — по коду языка из первой колонки. Если файла нет, показывается вписанный вручную emoji, а если нет и его — код языка. Подробнее о флагах — в «Помощь» справа вверху.', 'wp-mlp' ),
									'<code>' . esc_html( 'wp-content/uploads/' . Flags::DIRECTORY . '/<код>.svg' ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Сбор строк', 'wp-mlp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="discover_strings" value="1" <?php checked( ! empty( $raw['discover_strings'] ) ); ?>>
								<?php esc_html_e( 'Запоминать новые строки при открытии переведённых страниц', 'wp-mlp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Плагин не знает заранее, какой текст выведет тема, — он узнаёт это только когда страница реально показана. Поэтому список для перевода наполняется так: вы открываете страницу на дополнительном языке, плагин запоминает всё, что на ней нашлось, и эти строки появляются в «Переводе строк».', 'wp-mlp' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Держите включённым. Выключать имеет смысл, только когда сайт полностью переведён и вы не хотите, чтобы список пополнялся дальше.', 'wp-mlp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Карта сайта', 'wp-mlp' ); ?></th>
						<td>
							<?php $sitemapUrl = home_url( '/' . Sitemap::FILE ); ?>
							<p>
								<a href="<?php echo esc_url( $sitemapUrl ); ?>" target="_blank" rel="noopener noreferrer">
									<code><?php echo esc_html( $sitemapUrl ); ?></code>
								</a>
							</p>
							<p class="description">
								<?php esc_html_e( 'Содержит все языковые версии со взаимными hreflang — по ним Google понимает, что это одна страница на разных языках, а не дубли. Непереведённые страницы в карту не попадают.', 'wp-mlp' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Адрес уже добавлен в robots.txt, поэтому поисковики найдут его сами. Отправить его вручную в Google Search Console — быстрее.', 'wp-mlp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-sitemap-excluded"><?php esc_html_e( 'Исключить из карты сайта', 'wp-mlp' ); ?></label></th>
						<td>
							<textarea name="sitemap_excluded_slugs" id="mlp-sitemap-excluded" class="large-text code" rows="4"
								placeholder="oformlenie-zakaza&#10;net-dostupa"><?php echo esc_textarea( implode( "\n", $this->settings->sitemapExcludedSlugs() ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'По одному слагу на строку — последний сегмент адреса страницы, без слешей (для /blog/oformlenie-zakaza/ впишите oformlenie-zakaza). Страница со вложенными страницами исключается вместе с ними.', 'wp-mlp' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Заполнять нужно не всегда: страницы корзины, оформления заказа и личного кабинета WooCommerce, а также страницы, помеченные noindex в Yoast SEO, Rank Math или SEOPress, исключаются сами, без этого поля. Впишите сюда только то, что осталось без плагина корзины и без отметки noindex — например, техническую страницу «Нет доступа» или созданную вручную страницу оформления заказа.', 'wp-mlp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Непереведённые записи', 'wp-mlp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="hide_untranslated_posts" value="1" <?php checked( $this->settings->hidesUntranslatedPosts() ); ?>>
								<?php esc_html_e( 'Не показывать их в списках на дополнительных языках', 'wp-mlp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Блог, рубрики, метки и поиск на /en/ покажут только записи, у которых есть хотя бы один перевод. Отдельная запись остаётся доступной по прямой ссылке — иначе посетитель из поисковика получил бы 404.', 'wp-mlp' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Запись попадает в список после того, как вы хотя бы раз откроете её на дополнительном языке: только тогда плагин узнаёт, какие строки ей принадлежат.', 'wp-mlp' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Удаление плагина', 'wp-mlp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $raw['delete_data_on_uninstall'] ) ); ?>>
								<?php esc_html_e( 'Удалить все переводы и таблицы при удалении плагина', 'wp-mlp' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'По умолчанию выключено: после удаления плагина переводы остаются в базе и восстанавливаются при повторной установке.', 'wp-mlp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-openai-key"><?php esc_html_e( 'Ключ OpenAI', 'wp-mlp' ); ?></label></th>
						<td>
							<?php $this->renderOpenAiKeyStatus(); ?>
							<input type="password" name="openai_api_key" id="mlp-openai-key"
								class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( $this->settings->openAiApiKey() !== '' ? '••••••••  (оставьте пустым, чтобы не менять)' : 'sk-...' ); ?>">
							<?php if ( $this->settings->openAiApiKey() !== '' ) : ?>
								<label>
									<input type="checkbox" name="openai_api_key_clear" value="1">
									<?php esc_html_e( 'Удалить сохранённый ключ', 'wp-mlp' ); ?>
								</label>
							<?php endif; ?>
							<p class="description">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: link to OpenAI API keys page */
										__( 'Хранится в базе данных сайта, не в файлах. Взять ключ: %s.', 'wp-mlp' ),
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
						<th scope="row"><label for="mlp-openai-model"><?php esc_html_e( 'Модель OpenAI', 'wp-mlp' ); ?></label></th>
						<td>
							<input type="text" name="openai_model" id="mlp-openai-model" class="regular-text"
								value="<?php echo esc_attr( $this->settings->openAiModel() ); ?>" placeholder="gpt-4o-mini">
							<?php if ( '' === trim( $this->settings->openAiModel() ) ) : ?>
								<p class="description" style="color:#b32d2e;">
									<?php esc_html_e( 'Поле пустое — без него кнопка «Перевести с ИИ» не появится, даже если ключ сохранён.', 'wp-mlp' ); ?>
								</p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Точный идентификатор модели — сверьте с личным кабинетом OpenAI.', 'wp-mlp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-openai-base"><?php esc_html_e( 'Адрес API', 'wp-mlp' ); ?></label></th>
						<td>
							<input type="text" name="openai_base_url" id="mlp-openai-base" class="regular-text"
								value="<?php echo esc_attr( $this->settings->openAiBaseUrl() ); ?>">
							<p class="description"><?php esc_html_e( 'Меняйте только для своего прокси или совместимого шлюза вместо api.openai.com.', 'wp-mlp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mlp-openai-limit"><?php esc_html_e( 'Дневной лимит символов', 'wp-mlp' ); ?></label></th>
						<td>
							<input type="number" name="openai_daily_char_limit" id="mlp-openai-limit"
								min="0" max="10000000" step="1000"
								value="<?php echo esc_attr( (string) $this->settings->openAiDailyCharLimit() ); ?>">
							<p class="description"><?php esc_html_e( 'Сколько символов в сумме можно отправить в OpenAI за день, нажимая «Перевести с ИИ». Защита от случайных расходов, не автоматизация — плагин ничего не переводит сам.', 'wp-mlp' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Сохранить', 'wp-mlp' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Показывает, сохранён ли ключ — без единого лишнего символа самого ключа.
	 *
	 * В HTML попадают только последние 4 символа для подтверждения «это тот
	 * ключ, что я вводил» — этого недостаточно, чтобы восстановить ключ
	 * целиком, но достаточно, чтобы отличить его от чужого (ТЗ 13).
	 */
	private function renderOpenAiKeyStatus(): void {
		$key = $this->settings->openAiApiKey();

		if ( '' === $key ) {
			// Ключ мог остаться настроенным через .env — это резервный способ
			// для тех, кто предпочитает его файлам БД. Приоритет всегда у БД.
			if ( '' !== Env::get( 'OPENAI_API_KEY' ) ) {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Ключ сейчас берётся из .env — заполните поле ниже, чтобы хранить его в базе данных вместо файла.', 'wp-mlp' )
				);

				return;
			}

			printf( '<p class="description">%s</p>', esc_html__( 'Ключ не сохранён.', 'wp-mlp' ) );

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: last 4 characters of the saved key */
					__( 'Ключ сохранён, заканчивается на «%s».', 'wp-mlp' ),
					substr( $key, -4 )
				)
			)
		);
	}

	/**
	 * Строка таблицы языков.
	 *
	 * @param int           $index    Индекс строки в форме.
	 * @param Language|null $language Существующий язык или null для пустой строки.
	 */
	private function renderRow( int $index, ?Language $language ): void {
		$name = 'languages[' . $index . ']';

		?>
		<tr>
			<td class="mlp-order-column">
				<?php if ( null !== $language ) : ?>
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
						title="<?php esc_attr_e( 'Выше', 'wp-mlp' ); ?>">
						<span class="dashicons dashicons-arrow-up-alt2"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Переместить язык выше', 'wp-mlp' ); ?></span>
					</button>
					<button type="button" class="button-link mlp-order-down" hidden
						title="<?php esc_attr_e( 'Ниже', 'wp-mlp' ); ?>">
						<span class="dashicons dashicons-arrow-down-alt2"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Переместить язык ниже', 'wp-mlp' ); ?></span>
					</button>
				<?php endif; ?>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[locale]' ); ?>"
					value="<?php echo esc_attr( $language->locale ?? '' ); ?>"
					placeholder="en" size="10" pattern="[A-Za-z-]{2,20}">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[slug]' ); ?>"
					value="<?php echo esc_attr( $language->slug ?? '' ); ?>"
					placeholder="en" size="10">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[label]' ); ?>"
					value="<?php echo esc_attr( $language->label ?? '' ); ?>"
					placeholder="English">
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
					$version = (string) @filemtime( Flags::directoryPath() . '/' . Flags::fileName( $language->locale ) );
					?>
					<img class="mlp-flag-preview" src="<?php echo esc_url( add_query_arg( 'v', $version, $flagUrl ) ); ?>"
						alt="" width="24" height="18">
					<label class="mlp-flag-remove">
						<input type="checkbox" name="<?php echo esc_attr( $name . '[flag_remove]' ); ?>" value="1">
						<?php esc_html_e( 'убрать', 'wp-mlp' ); ?>
					</label>
				<?php endif; ?>

				<?php if ( null !== $language ) : ?>
					<input type="file" name="<?php echo esc_attr( 'flag_file_' . $index ); ?>"
						accept=".svg,image/svg+xml" class="mlp-flag-file"
						title="<?php esc_attr_e( 'Картинка флага (SVG). Загрузится под кодом языка из первой колонки.', 'wp-mlp' ); ?>">
				<?php endif; ?>

				<input type="text" name="<?php echo esc_attr( $name . '[flag]' ); ?>"
					value="<?php echo esc_attr( $language->flag ?? '' ); ?>"
					placeholder="🇬🇧" size="4" maxlength="16"
					title="<?php esc_attr_e( 'Запасной вариант: emoji-флаг. Показывается, если картинка не загружена. На Windows emoji-флаги не отображаются — там будут видны буквы.', 'wp-mlp' ); ?>">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[wp_locale]' ); ?>"
					value="<?php echo esc_attr( $language->wpLocale ?? '' ); ?>"
					placeholder="en_US" size="10"
					title="<?php esc_attr_e( 'Локаль WordPress: под этим именем ядро, тема и плагины ищут свои переводы', 'wp-mlp' ); ?>">
				<?php $this->renderLanguagePackStatus( $language ); ?>
			</td>
			<td>
				<?php if ( null !== $language && $language->isDefault ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name . '[status]' ); ?>" value="<?php echo esc_attr( Language::STATUS_PUBLISHED ); ?>">
					<em><?php esc_html_e( 'по умолчанию', 'wp-mlp' ); ?></em>
				<?php else : ?>
					<select name="<?php echo esc_attr( $name . '[status]' ); ?>">
						<option value="<?php echo esc_attr( Language::STATUS_PUBLISHED ); ?>" <?php selected( Language::STATUS_PUBLISHED, $language->status ?? Language::STATUS_PUBLISHED ); ?>>
							<?php esc_html_e( 'Опубликован', 'wp-mlp' ); ?>
						</option>
						<option value="<?php echo esc_attr( Language::STATUS_DRAFT ); ?>" <?php selected( Language::STATUS_DRAFT, $language->status ?? '' ); ?>>
							<?php esc_html_e( 'Черновик', 'wp-mlp' ); ?>
						</option>
					</select>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( null !== $language && ! $language->isDefault ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $name . '[delete]' ); ?>" value="1">
						<?php esc_html_e( 'Удалить язык', 'wp-mlp' ); ?>
					</label>
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
				esc_html__( 'Встроенная локаль — пакет не нужен.', 'wp-mlp' )
			);

			return;
		}

		if ( $this->packs->isInstalled( $language->wpLocale ) ) {
			printf(
				'<p class="description" style="color:#007017;">%s</p>',
				esc_html__( 'Языковой пакет установлен.', 'wp-mlp' )
			);

			return;
		}

		printf(
			'<p class="description" style="color:#b32d2e;">%s</p>',
			esc_html__( 'Языкового пакета нет — строки интерфейса останутся на английском.', 'wp-mlp' )
		);

		if ( ! $this->packs->canInstall() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Автоматическая установка недоступна: нет прав на запись в wp-content/languages или закрыт доступ к api.wordpress.org. Загрузите файлы перевода вручную.', 'wp-mlp' )
			);

			return;
		}

		printf(
			'<button type="submit" class="button button-secondary" name="%s" value="%s">%s</button>',
			esc_attr( self::FIELD_DOWNLOAD_PACK ),
			esc_attr( $language->wpLocale ),
			esc_html__( 'Скачать языковой пакет', 'wp-mlp' )
		);
	}

	/**
	 * Сообщения после сохранения.
	 */
	private function renderNotices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- только чтение флагов из URL.
		if ( isset( $_GET['mlp-updated'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Настройки сохранены.', 'wp-mlp' )
			);
		}

		if ( isset( $_GET['mlp-pack'] ) ) {
			$installed = 'ok' === sanitize_text_field( wp_unslash( (string) $_GET['mlp-pack'] ) );

			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$installed ? 'success' : 'error',
				esc_html(
					$installed
						? __( 'Языковой пакет установлен.', 'wp-mlp' )
						: __( 'Не удалось скачать языковой пакет. Проверьте, что для этой локали существует перевод на translate.wordpress.org и что сайту разрешён доступ к api.wordpress.org.', 'wp-mlp' )
				)
			);
		}

		if ( ! isset( $_GET['mlp-errors'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$key    = 'mlp_settings_errors_' . get_current_user_id();
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
