<?php
/**
 * Админ-страница «Языки».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Support\Env;
use WpMlp\Support\Hookable;

/**
 * Список языков сайта: код, URL-слаг, название, статус, язык по умолчанию.
 */
final class SettingsPage implements Hookable {

	public const MENU_SLUG   = 'wp-mlp';
	public const CAPABILITY  = 'manage_options';
	public const ACTION_SAVE = 'mlp_save_settings';

	/**
	 * @param Settings $settings Настройки плагина.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handleSave' ) );
		add_action( 'admin_notices', array( $this, 'maybeShowPermalinkNotice' ) );
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

		// Слаги языков входят в rewrite-правила — их нужно пересобрать.
		flush_rewrite_rules();

		$query = array( 'page' => self::MENU_SLUG );

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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<table class="widefat striped" style="max-width:60em;margin-bottom:1em;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Код языка', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL-слаг', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Название', 'wp-mlp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Флаг', 'wp-mlp' ); ?></th>
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
						<th scope="row"><?php esc_html_e( 'Сбор строк', 'wp-mlp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="discover_strings" value="1" <?php checked( ! empty( $raw['discover_strings'] ) ); ?>>
								<?php esc_html_e( 'Запоминать новые строки при открытии переведённых страниц', 'wp-mlp' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Словарь пополняется по мере посещения страниц на дополнительном языке. Если выключить, новые строки перестанут появляться в разделе «Перевод строк».', 'wp-mlp' ); ?></p>
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
			<td>
				<input type="text" name="<?php echo esc_attr( $name . '[flag]' ); ?>"
					value="<?php echo esc_attr( $language->flag ?? '' ); ?>"
					placeholder="🇬🇧" size="4" maxlength="16"
					title="<?php esc_attr_e( 'Emoji-флаг для переключателя языков, необязательно', 'wp-mlp' ); ?>">
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
