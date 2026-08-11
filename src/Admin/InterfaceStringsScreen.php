<?php
/**
 * Вкладка «Интерфейс» экрана «Перевод строк».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\I18n\DomainLabel;
use WpMlp\I18n\LanguagePacks;
use WpMlp\Settings\Language;
use WpMlp\Storage\GettextRepository;
use WpMlp\Storage\TranslationStatus;

/**
 * Строки WordPress, темы и плагинов — те, что пришли через `__()`/`_x()`.
 *
 * Отдельный экран, а не фильтр на общем списке, потому что у этих строк
 * другая природа и другие колонки: у них есть домен и контекст, их
 * оригинал английский, а перевод может прийти из официального языкового
 * пакета — то есть вообще не из нашей базы.
 *
 * Про «Официальный перевод». Строки, которые языковой пакет уже закрыл, в
 * нашу базу не пишутся вовсе (см. I18n\GettextRegistry), поэтому в списке
 * их обычно нет. Колонка всё равно нужна: пакет могли установить ПОЗЖЕ,
 * чем строка попала в словарь, — тогда официальный перевод у неё
 * появляется, и его видно рядом со своим. Ищется он живым вызовом
 * `translate()` под целевой локалью, а не в базе.
 */
final class InterfaceStringsScreen {

	/**
	 * @param GettextRepository $gettext Gettext-часть словаря.
	 * @param LanguagePacks     $packs   Языковые пакеты WordPress.
	 */
	public function __construct(
		private readonly GettextRepository $gettext,
		private readonly LanguagePacks $packs
	) {
	}

	/**
	 * Выводит вкладку.
	 *
	 * @param Language                                                                          $language Целевой язык.
	 * @param array{domain: string, status: string, search: string, page: int}                  $filters  Текущие фильтры.
	 * @param int                                                                               $perPage  Строк на странице.
	 * @param callable(int, int): void                                                          $renderNav Постраничная навигация.
	 */
	public function render( Language $language, array $secondary, array $filters, int $perPage, callable $renderNav ): void {
		$this->renderPackNotice( $language );

		$result = $this->gettext->paginate(
			array(
				'locale'   => $language->locale,
				'domain'   => $filters['domain'],
				'status'   => $filters['status'],
				'search'   => $filters['search'],
				'page'     => $filters['page'],
				'per_page' => $perPage,
			)
		);

		$official = $this->officialTranslations( $result['items'], $language );
		$labels   = $this->domainLabels();

		?>
		<p class="description">
			<?php esc_html_e( 'Строки самого WordPress, темы и плагинов. Большинство из них переводит официальный языковой пакет — здесь показано только то, для чего перевода в пакете не нашлось, и то, что вы поправили вручную.', 'wp-mlp' ); ?>
		</p>

		<?php $this->renderFilters( $language, $secondary, $filters, $result['total'] ); ?>
		<?php $renderNav( $result['total'], (int) $filters['page'] ); ?>

		<table class="widefat striped wp-mlp-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Оригинал', 'wp-mlp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Источник', 'wp-mlp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Официальный перевод', 'wp-mlp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ваш перевод', 'wp-mlp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Статус', 'wp-mlp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $result['items'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Строк не найдено.', 'wp-mlp' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $result['items'] as $row ) : ?>
					<?php $this->renderRow( $row, $language, $official[ (int) $row['id'] ] ?? '', $labels ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php $renderNav( $result['total'], (int) $filters['page'] ); ?>
		<?php
	}

	/**
	 * Одна строка таблицы.
	 *
	 * @param array<string, mixed>  $row      Данные строки.
	 * @param Language              $language Целевой язык.
	 * @param string                $official Перевод из языкового пакета.
	 * @param array<string, string> $labels   Домен => человекочитаемое имя.
	 */
	private function renderRow( array $row, Language $language, string $official, array $labels ): void {
		$override = (string) ( $row['translated_text'] ?? '' );
		$context  = (string) ( $row['gettext_context'] ?? '' );
		$plural   = $row['plural_key'];
		$status   = $this->status( $override, $official, (string) ( $row['status'] ?? '' ) );

		?>
		<tr>
			<td class="wp-mlp-col-source">
				<code><?php echo esc_html( (string) $row['source_text'] ); ?></code>
				<?php if ( '' !== $context ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: gettext context from _x() */
							esc_html__( 'Контекст: %s', 'wp-mlp' ),
							'<code>' . esc_html( $context ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( null !== $plural ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: plural form number */
							esc_html__( 'Форма множественного числа №%d', 'wp-mlp' ),
							(int) $plural
						);
						?>
					</p>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $labels[ (string) ( $row['domain'] ?? '' ) ] ?? DomainLabel::format( (string) ( $row['domain'] ?? '' ) ) ); ?></td>
			<td>
				<?php if ( '' !== $official ) : ?>
					<?php echo esc_html( $official ); ?>
				<?php else : ?>
					<span class="description">—</span>
				<?php endif; ?>
			</td>
			<td class="wp-mlp-col-translation">
				<label class="screen-reader-text" for="mlp-input-<?php echo esc_attr( (string) $row['id'] ); ?>">
					<?php esc_html_e( 'Ваш перевод', 'wp-mlp' ); ?>
				</label>
				<textarea
					id="mlp-input-<?php echo esc_attr( (string) $row['id'] ); ?>"
					class="wp-mlp-input"
					rows="2"
					data-source-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
					data-locale="<?php echo esc_attr( $language->locale ); ?>"><?php echo esc_textarea( $override ); ?></textarea>
				<button type="button" class="button button-secondary wp-mlp-save">
					<?php esc_html_e( 'Сохранить', 'wp-mlp' ); ?>
				</button>
				<?php if ( '' !== $override ) : ?>
					<button type="button" class="button-link wp-mlp-delete"
						title="<?php esc_attr_e( 'Убрать своё переопределение — вернётся официальный перевод', 'wp-mlp' ); ?>">
						<?php esc_html_e( 'Сбросить к официальному', 'wp-mlp' ); ?>
					</button>
				<?php endif; ?>
			</td>
			<td>
				<span class="wp-mlp-status wp-mlp-status--<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( TranslationStatus::label( $status ) ); ?>
				</span>
			</td>
		</tr>
		<?php
	}

	/**
	 * Статус строки для показа. Чистая функция.
	 *
	 * `locale_file` появляется только здесь и только в интерфейсе: строки,
	 * переведённой пакетом, в нашей базе нет, и хранить этот статус негде
	 * (см. Storage\TranslationStatus::LOCALE_FILE).
	 *
	 * @param string $override Наше переопределение.
	 * @param string $official Перевод из языкового пакета.
	 * @param string $stored   Статус из нашей таблицы переводов.
	 */
	public static function status( string $override, string $official, string $stored ): string {
		if ( '' !== $override ) {
			return TranslationStatus::isValid( $stored ) ? $stored : TranslationStatus::APPROVED;
		}

		return '' !== $official ? TranslationStatus::LOCALE_FILE : TranslationStatus::MISSING;
	}

	/**
	 * Официальные переводы показанных строк — живым вызовом под целевой
	 * локалью, а не из нашей базы (их там нет по определению).
	 *
	 * Переключение локали делается один раз на всю страницу списка, а не
	 * на каждую строку: `switch_to_locale()` перезагружает файлы переводов,
	 * и делать это двадцать раз подряд было бы заметно дорого.
	 *
	 * @param list<array<string, mixed>> $items    Строки текущей страницы.
	 * @param Language                   $language Целевой язык.
	 * @return array<int, string> Идентификатор строки => официальный перевод.
	 */
	private function officialTranslations( array $items, Language $language ): array {
		if ( array() === $items || ! function_exists( 'switch_to_locale' ) ) {
			return array();
		}

		$switched = switch_to_locale( $language->wpLocale );
		$result   = array();

		foreach ( $items as $row ) {
			$msgid   = (string) $row['source_text'];
			$domain  = (string) ( $row['domain'] ?? '' );
			$domain  = '' !== $domain ? $domain : DomainLabel::CORE;
			$context = (string) ( $row['gettext_context'] ?? '' );

			$translated = '' !== $context
				? translate_with_gettext_context( $msgid, $context, $domain )
				: translate( $msgid, $domain );

			// Вернулся оригинал — значит в пакете этой строки нет.
			if ( $translated !== $msgid ) {
				$result[ (int) $row['id'] ] = (string) $translated;
			}
		}

		if ( $switched ) {
			restore_previous_locale();
		}

		return $result;
	}

	/**
	 * Карта «домен → человекочитаемое имя» для показанных строк.
	 *
	 * @return array<string, string>
	 */
	private function domainLabels(): array {
		$themes  = array();
		$plugins = array();

		if ( function_exists( 'wp_get_theme' ) ) {
			foreach ( wp_get_themes() as $theme ) {
				$domain = (string) $theme->get( 'TextDomain' );

				if ( '' !== $domain ) {
					$themes[ $domain ] = (string) $theme->get( 'Name' );
				}
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $plugin ) {
			$domain = (string) ( $plugin['TextDomain'] ?? '' );

			if ( '' !== $domain ) {
				$plugins[ $domain ] = (string) ( $plugin['Name'] ?? $domain );
			}
		}

		$labels = array();

		foreach ( $this->gettext->domains() as $domain ) {
			$labels[ $domain ] = DomainLabel::format( $domain, $themes, $plugins );
		}

		$labels[ DomainLabel::CORE ] = DomainLabel::format( DomainLabel::CORE );

		return $labels;
	}

	/**
	 * Фильтры над таблицей.
	 *
	 * @param array{domain: string, status: string, search: string, page: int} $filters Текущие фильтры.
	 * @param int                                                              $total   Всего строк.
	 */
	private function renderFilters( Language $language, array $secondary, array $filters, int $total ): void {
		$labels = $this->domainLabels();

		?>
		<form method="get" class="wp-mlp-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( StringTranslationPage::MENU_SLUG ); ?>">
			<input type="hidden" name="mlp_tab" value="<?php echo esc_attr( StringTranslationPage::TAB_INTERFACE ); ?>">

			<label for="mlp-locale" class="screen-reader-text"><?php esc_html_e( 'Язык', 'wp-mlp' ); ?></label>
			<select name="mlp_locale" id="mlp-locale">
				<?php foreach ( $secondary as $option ) : ?>
					<option value="<?php echo esc_attr( $option->locale ); ?>" <?php selected( $option->locale, $language->locale ); ?>>
						<?php echo esc_html( sprintf( '%s (%s)', $option->label, $option->locale ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mlp-domain" class="screen-reader-text"><?php esc_html_e( 'Источник', 'wp-mlp' ); ?></label>
			<select name="mlp_domain" id="mlp-domain">
				<option value=""><?php esc_html_e( 'Все источники', 'wp-mlp' ); ?></option>
				<?php foreach ( $labels as $domain => $label ) : ?>
					<option value="<?php echo esc_attr( $domain ); ?>" <?php selected( $domain, $filters['domain'] ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mlp-gettext-status" class="screen-reader-text"><?php esc_html_e( 'Статус', 'wp-mlp' ); ?></label>
			<select name="mlp_status" id="mlp-gettext-status">
				<option value=""><?php esc_html_e( 'Все строки', 'wp-mlp' ); ?></option>
				<option value="<?php echo esc_attr( GettextRepository::STATUS_MISSING ); ?>" <?php selected( GettextRepository::STATUS_MISSING, $filters['status'] ); ?>>
					<?php esc_html_e( 'Без вашего перевода', 'wp-mlp' ); ?>
				</option>
				<option value="<?php echo esc_attr( GettextRepository::STATUS_OVERRIDDEN ); ?>" <?php selected( GettextRepository::STATUS_OVERRIDDEN, $filters['status'] ); ?>>
					<?php esc_html_e( 'Переопределены вручную', 'wp-mlp' ); ?>
				</option>
			</select>

			<label for="mlp-search" class="screen-reader-text"><?php esc_html_e( 'Поиск', 'wp-mlp' ); ?></label>
			<input type="search" name="s" id="mlp-search" value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Поиск по оригиналу и переводу', 'wp-mlp' ); ?>">

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
	 * Предупреждает, что без языкового пакета этот экран бессмыслен.
	 *
	 * Без пакета WordPress возвращает английские оригиналы КАЖДОЙ строки —
	 * и ядра, и темы, и всех плагинов. Формально они все «не покрыты
	 * переводом», но список из тысяч строк ядра (включая служебные вроде
	 * `en dash` и правил `wptexturize`) — не рабочий перечень, а шум, в
	 * котором не найти те несколько строк, что правда ждут перевода.
	 * Поэтому сбор для такого языка выключен (см. GettextRegistry), а
	 * экран честно объясняет, почему он пуст, и что делать.
	 *
	 * @param Language $language Целевой язык.
	 */
	private function renderPackNotice( Language $language ): void {
		if ( $this->packs->isInstalled( $language->wpLocale ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: language label, 2: WordPress locale */
					__( 'Языковой пакет для «%1$s» (%2$s) не установлен. Пока его нет, WordPress отдаёт английские оригиналы вообще всех строк интерфейса, и собирать их в словарь бессмысленно — поэтому этот список для данного языка не пополняется.', 'wp-mlp' ),
					$language->label,
					$language->wpLocale
				)
			),
			wp_kses(
				sprintf(
					/* translators: %s: link to the languages settings page */
					__( 'Установите пакет на странице %s — после этого сюда попадёт только то, чего в нём действительно не хватает.', 'wp-mlp' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) . '">' . esc_html__( 'Мультиязычность → Языки', 'wp-mlp' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}
}
