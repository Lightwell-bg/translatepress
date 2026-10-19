<?php
/**
 * Вкладка «Интерфейс» экрана «Перевод строк».
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\I18n\DomainLabel;
use LingoCanvas\I18n\GettextKey;
use LingoCanvas\I18n\LanguagePacks;
use LingoCanvas\I18n\OfficialTranslation;
use LingoCanvas\Settings\Language;
use LingoCanvas\Storage\GettextRepository;
use LingoCanvas\Storage\TranslationStatus;

defined( 'ABSPATH' ) || exit;

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
 * появляется, и его видно рядом со своим. Ищется он под целевой локалью
 * в объекте переводов домена (I18n\OfficialTranslation), а не в базе.
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
			<?php esc_html_e( 'These are not your own content but the labels drawn by WordPress itself, the theme and plugins: "Reply", "Leave a comment", the trash buttons. You did not write them and rarely need to translate them by hand, because WordPress takes the translation from the official language pack.', 'lingocanvas' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Only strings that the language pack has no translation for (usually from a theme or a niche plugin) and strings you edited by hand appear here. Check the "Source" column: theme strings matter most because visitors see them on every page, while admin plugins such as Rank Math can be left alone, since their labels are only visible to you.', 'lingocanvas' ); ?>
		</p>

		<?php $this->renderFilters( $language, $secondary, $filters, $result['total'] ); ?>
		<?php $renderNav( $result['total'], (int) $filters['page'] ); ?>

		<table class="widefat striped wp-mlp-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Original', 'lingocanvas' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'lingocanvas' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Official translation', 'lingocanvas' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Your translation', 'lingocanvas' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'lingocanvas' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $result['items'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No strings found.', 'lingocanvas' ); ?></td></tr>
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
							esc_html__( 'Context: %s', 'lingocanvas' ),
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
							esc_html__( 'Plural form no. %d', 'lingocanvas' ),
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
					<?php esc_html_e( 'Your translation', 'lingocanvas' ); ?>
				</label>
				<textarea
					id="mlp-input-<?php echo esc_attr( (string) $row['id'] ); ?>"
					class="wp-mlp-input"
					rows="2"
					data-source-id="<?php echo esc_attr( (string) $row['id'] ); ?>"
					data-locale="<?php echo esc_attr( $language->locale ); ?>"><?php echo esc_textarea( $override ); ?></textarea>
				<button type="button" class="button button-secondary wp-mlp-save">
					<?php esc_html_e( 'Save', 'lingocanvas' ); ?>
				</button>
				<?php if ( '' !== $override ) : ?>
					<button type="button" class="button-link wp-mlp-delete"
						title="<?php esc_attr_e( 'Remove your override and use the official translation again', 'lingocanvas' ); ?>">
						<?php esc_html_e( 'Reset to official', 'lingocanvas' ); ?>
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
		if ( array() === $items || ! function_exists( 'switch_to_locale' ) || ! function_exists( 'get_translations_for_domain' ) ) {
			return array();
		}

		$switched = switch_to_locale( $language->wpLocale );
		$result   = array();
		$objects  = array();

		foreach ( $items as $row ) {
			$domain = (string) ( $row['domain'] ?? '' );
			$domain = '' !== $domain ? $domain : DomainLabel::CORE;

			// Объект переводов домена — сами загруженные файлы, без фильтров gettext
			// (иначе вместо официального перевода показалось бы наше же переопределение).
			$objects[ $domain ] ??= get_translations_for_domain( $domain );

			$pluralKey  = isset( $row['plural_key'] ) && null !== $row['plural_key'] ? (int) $row['plural_key'] : null;
			$translated = OfficialTranslation::lookup(
				$objects[ $domain ],
				(string) $row['source_text'],
				(string) ( $row['gettext_context'] ?? '' ),
				$pluralKey
			);

			if ( null !== $translated ) {
				$result[ (int) $row['id'] ] = $translated;
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
			<input type="hidden" name="lingocanvas_tab" value="<?php echo esc_attr( StringTranslationPage::TAB_INTERFACE ); ?>">

			<label for="mlp-locale" class="screen-reader-text"><?php esc_html_e( 'Language', 'lingocanvas' ); ?></label>
			<select name="lingocanvas_locale" id="mlp-locale">
				<?php foreach ( $secondary as $option ) : ?>
					<option value="<?php echo esc_attr( $option->locale ); ?>" <?php selected( $option->locale, $language->locale ); ?>>
						<?php echo esc_html( sprintf( '%s (%s)', $option->label, $option->locale ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mlp-domain" class="screen-reader-text"><?php esc_html_e( 'Source', 'lingocanvas' ); ?></label>
			<select name="lingocanvas_domain" id="mlp-domain">
				<option value=""><?php esc_html_e( 'All sources', 'lingocanvas' ); ?></option>
				<?php foreach ( $labels as $domain => $label ) : ?>
					<option value="<?php echo esc_attr( $domain ); ?>" <?php selected( $domain, $filters['domain'] ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mlp-gettext-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'lingocanvas' ); ?></label>
			<select name="lingocanvas_status" id="mlp-gettext-status">
				<option value=""><?php esc_html_e( 'All strings', 'lingocanvas' ); ?></option>
				<option value="<?php echo esc_attr( GettextRepository::STATUS_MISSING ); ?>" <?php selected( GettextRepository::STATUS_MISSING, $filters['status'] ); ?>>
					<?php esc_html_e( 'Without your translation', 'lingocanvas' ); ?>
				</option>
				<option value="<?php echo esc_attr( GettextRepository::STATUS_OVERRIDDEN ); ?>" <?php selected( GettextRepository::STATUS_OVERRIDDEN, $filters['status'] ); ?>>
					<?php esc_html_e( 'Overridden by hand', 'lingocanvas' ); ?>
				</option>
			</select>

			<label for="mlp-search" class="screen-reader-text"><?php esc_html_e( 'Search', 'lingocanvas' ); ?></label>
			<input type="search" name="s" id="mlp-search" value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Search the original and the translation', 'lingocanvas' ); ?>">

			<?php submit_button( __( 'Show', 'lingocanvas' ), 'secondary', '', false ); ?>

			<span class="wp-mlp-total">
				<?php
				printf(
					/* translators: %s: number of strings */
					esc_html__( 'Strings found: %s', 'lingocanvas' ),
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
		if ( GettextKey::SOURCE_LOCALE === $language->wpLocale ) {
			/*
			 * Словарь интерфейса общий для всех языков: `msgid` английский
			 * по определению, и одна строка обслуживает и болгарский, и
			 * любой другой. Поэтому на английской вкладке видны строки,
			 * собранные на ДРУГИХ языках, — и у каждой честно написано
			 * «нет перевода», хотя переводить нечего: оригинал уже
			 * английский. Без этого объяснения экран выглядит поломанным.
			 */
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'English is the language of the originals themselves: the interface strings are already written in it and do not need translating. The list below is shared by all languages (it was collected wherever a translation was missing), so the strings show "No translation", which is normal for English. Switch the language to see where a translation is really needed.', 'lingocanvas' )
			);

			return;
		}

		if ( $this->packs->isInstalled( $language->wpLocale ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: language label, 2: WordPress locale */
					__( 'The language pack for "%1$s" (%2$s) is not installed. Until it is, WordPress serves the English originals for all interface strings, and collecting them into the dictionary is pointless, so this list is not filled for this language.', 'lingocanvas' ),
					$language->label,
					$language->wpLocale
				)
			),
			wp_kses(
				sprintf(
					/* translators: %s: link to the languages settings page */
					__( 'Install the pack on the %s page; after that, only what it truly lacks will appear here.', 'lingocanvas' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ) . '">' . esc_html__( 'Multilingual → Languages', 'lingocanvas' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}
}
