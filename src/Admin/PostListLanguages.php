<?php
/**
 * Колонка «Языки» и фильтр «Перевод» в списках записей и страниц.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

use WpMlp\Frontend\Flags;
use WpMlp\Settings\Language;
use WpMlp\Settings\Settings;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Storage\PostStringScope;
use WpMlp\Storage\Schema;
use WpMlp\Storage\SourceRepository;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Показывает в стандартных списках WordPress, насколько запись переведена
 * на каждый дополнительный язык, и позволяет отобрать недопереведённые.
 *
 * Правила «что считать строками записи» и «что считать переведённым» взяты
 * у экрана «Перевод строк» (SourceRepository::paginate()) дословно: строка
 * принадлежит записи, если найдена на ней и больше ни на одной записи
 * (нет вхождений на другой записи), а переведена, если текст перевода не
 * пуст. Иначе цифры в списке и на экране строк разошлись бы, а владелец
 * сайта перестал бы верить обоим.
 *
 * Ничего не ищет сам: если на запись ещё ни разу не заходили, строк о ней
 * в базе нет, и честный ответ — «строк пока нет», а не выдуманные нули.
 */
final class PostListLanguages implements Hookable {

	/** Ключ колонки в списке. */
	public const COLUMN = 'mlp_languages';

	/** Имя параметра фильтра в адресной строке. */
	public const FILTER_PARAM = 'mlp_untranslated';

	/** Префиксы значений фильтра «Показано на / Скрыто на языке» (`shown:en`, `hidden:en`). */
	public const FILTER_SHOWN  = 'shown:';
	public const FILTER_HIDDEN = 'hidden:';

	/** Префиксы массовых действий; после префикса идёт код языка. */
	public const BULK_SHOW = 'mlp_show_';
	public const BULK_HIDE = 'mlp_hide_';

	/** Параметры адреса возврата после массового действия. */
	public const NOTICE_COUNT   = 'mlp_vis_done';
	public const NOTICE_SKIPPED = 'mlp_vis_skipped';
	public const NOTICE_LOCALE  = 'mlp_vis_locale';
	public const NOTICE_STATE   = 'mlp_vis_state';

	/** Запись открыть нельзя (не опубликована и т. п.). */
	public const STATE_UNAVAILABLE = 'unavailable';

	/** Строки записи неизвестны: на неё не заходили. */
	public const STATE_NEVER = 'never';

	/** Строки известны, ни одна не переведена. */
	public const STATE_NONE = 'none';

	/** Переведена часть строк. */
	public const STATE_PARTIAL = 'partial';

	/** Переведены все строки. */
	public const STATE_FULL = 'full';

	/**
	 * Посчитанные данные: id записи => код языка => [всего строк, переведено].
	 *
	 * @var array<int, array<string, array{0: int, 1: int}>>
	 */
	private array $stats = array();

	/**
	 * Пути записей для редактора (null — открыть нельзя): считаются один раз.
	 *
	 * @var array<int, string|null>
	 */
	private array $paths = array();

	/**
	 * Кеш адресов картинок флагов: проверка файла на диске одна на язык, а не на строку.
	 *
	 * @var array<string, string>
	 */
	private array $flagUrls = array();

	/**
	 * @param Settings                    $settings   Настройки плагина.
	 * @param EditorLink                  $link       Пути и адреса редактора.
	 * @param PostLanguageVisibility|null $visibility Флаги «показывать на языке»; без них переключателей, массовых действий и фильтра показа нет.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly EditorLink $link,
		private readonly ?PostLanguageVisibility $visibility = null
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'manage_posts_columns', array( $this, 'addColumn' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'addColumn' ), 10, 1 );
		add_action( 'manage_posts_custom_column', array( $this, 'renderColumn' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'renderColumn' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'renderFilter' ), 10, 2 );
		add_filter( 'posts_clauses', array( $this, 'filterClauses' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filterByVisibility' ) );
		add_action( 'admin_init', array( $this, 'registerBulkActions' ) );
		add_action( 'admin_notices', array( $this, 'renderBulkNotice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Подключает скрипт переключателей на экране списка записей.
	 *
	 * @param string $hook Идентификатор текущего экрана.
	 */
	public function enqueue( $hook = '' ): void {
		if ( 'edit.php' !== $hook || ! $this->togglesAvailable() || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return;
		}

		VisibilityAssets::enqueue();
	}

	/**
	 * Переключатели, массовые действия и фильтр показа нужны: ручной режим и есть хранилище флагов.
	 */
	private function togglesAvailable(): bool {
		return null !== $this->visibility && $this->settings->isManualPostVisibility();
	}

	/**
	 * Вешает массовые действия «Показывать на языке» / «Скрыть на языке» на списки всех публичных типов.
	 *
	 * Имя фильтра содержит тип записи, поэтому вешаем по одному на каждый тип.
	 * Вызывается на `admin_init`: типы записей к этому моменту уже зарегистрированы.
	 */
	public function registerBulkActions(): void {
		if ( ! $this->togglesAvailable() ) {
			return;
		}

		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( ! is_string( $type ) || ! $this->enabledFor( $type ) ) {
				continue;
			}

			add_filter( "bulk_actions-edit-{$type}", array( $this, 'addBulkActions' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handleBulkAction' ), 10, 3 );
		}
	}

	/**
	 * Добавляет в меню массовых действий пары «Показывать на языке: X» / «Скрыть на языке: X».
	 *
	 * @param array<string, string>|mixed $actions Действия.
	 * @return array<string, string>|mixed
	 */
	public function addBulkActions( $actions ) {
		if ( ! is_array( $actions ) || ! $this->togglesAvailable() ) {
			return $actions;
		}

		foreach ( $this->settings->secondary() as $language ) {
			/* translators: %s: language name */
			$actions[ self::BULK_SHOW . $language->locale ] = sprintf( __( 'Show on language: %s', 'lingocanvas' ), $language->label );
			/* translators: %s: language name */
			$actions[ self::BULK_HIDE . $language->locale ] = sprintf( __( 'Hide on language: %s', 'lingocanvas' ), $language->label );
		}

		return $actions;
	}

	/**
	 * Выполняет массовое действие и возвращает адрес для возврата в список с итогом.
	 *
	 * Подлинность запроса (nonce `bulk-posts`) проверяет сам список WordPress
	 * до вызова этого фильтра. Право `edit_post` проверяется на каждую запись:
	 * чужие записи молча пропускаются и считаются в итоге. Язык берётся только
	 * из настроенных дополнительных.
	 *
	 * @param string|mixed $redirect Адрес возврата.
	 * @param string|mixed $action   Выбранное действие.
	 * @param array<int, mixed>|mixed $postIds Отмеченные записи.
	 * @return string|mixed
	 */
	public function handleBulkAction( $redirect, $action = '', $postIds = array() ) {
		if ( ! is_string( $redirect ) || ! is_string( $action ) || ! $this->togglesAvailable() || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return $redirect;
		}

		$visible = null;
		$locale  = '';

		if ( str_starts_with( $action, self::BULK_SHOW ) ) {
			$visible = true;
			$locale  = substr( $action, strlen( self::BULK_SHOW ) );
		} elseif ( str_starts_with( $action, self::BULK_HIDE ) ) {
			$visible = false;
			$locale  = substr( $action, strlen( self::BULK_HIDE ) );
		}

		if ( null === $visible || ! isset( $this->settings->secondary()[ $locale ] ) || ! is_array( $postIds ) ) {
			return $redirect;
		}

		$allowed = array();
		$skipped = 0;

		foreach ( array_unique( array_map( 'intval', $postIds ) ) as $id ) {
			if ( $id > 0 && current_user_can( 'edit_post', $id ) && $this->visibility->isEligiblePost( $id ) ) {
				$allowed[] = $id;
			} else {
				++$skipped;
			}
		}

		$this->visibility->setMany( $allowed, $locale, $visible );

		return add_query_arg(
			array(
				self::NOTICE_COUNT   => count( $allowed ),
				self::NOTICE_SKIPPED => $skipped,
				self::NOTICE_LOCALE  => $locale,
				self::NOTICE_STATE   => $visible ? 1 : 0,
			),
			$redirect
		);
	}

	/**
	 * Итог массового действия над списком: «Показано на DE: 12 записей».
	 */
	public function renderBulkNotice(): void {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- только показывает итог, ничего не меняет.
		if ( 'edit.php' !== ( $pagenow ?? '' ) || ! isset( $_GET[ self::NOTICE_LOCALE ], $_GET[ self::NOTICE_COUNT ] ) || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return;
		}

		$locale  = Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET[ self::NOTICE_LOCALE ] ) ) );
		$count   = absint( wp_unslash( (string) $_GET[ self::NOTICE_COUNT ] ) );
		$skipped = isset( $_GET[ self::NOTICE_SKIPPED ] ) ? absint( wp_unslash( (string) $_GET[ self::NOTICE_SKIPPED ] ) ) : 0;
		$shown   = isset( $_GET[ self::NOTICE_STATE ] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET[ self::NOTICE_STATE ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $this->settings->secondary()[ $locale ] ) ) {
			return;
		}

		$text = sprintf(
			/* translators: 1: "Shown" or "Hidden", 2: language code, 3: number of posts with the noun */
			__( '%1$s on %2$s: %3$s', 'lingocanvas' ),
			$shown ? __( 'Shown', 'lingocanvas' ) : __( 'Hidden', 'lingocanvas' ),
			strtoupper( $locale ),
			sprintf(
				/* translators: %d: number of posts */
				_n( '%d post', '%d posts', $count, 'lingocanvas' ),
				$count
			)
		);

		if ( $skipped > 0 ) {
			/* translators: %d: number of skipped posts */
			$text .= ' ' . sprintf( __( '(skipped, no permission or unsuitable post type: %d)', 'lingocanvas' ), $skipped );
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
	}

	/**
	 * Русская форма существительного по числу. Чистая функция.
	 *
	 * @param int    $n    Число.
	 * @param string $one  Форма для 1 («запись»).
	 * @param string $few  Форма для 2–4 («записи»).
	 * @param string $many Форма для 5–20 и 0 («записей»).
	 */
	public static function plural( int $n, string $one, string $few, string $many ): string {
		$n   = abs( $n );
		$mod = $n % 100;

		if ( $mod >= 11 && $mod <= 14 ) {
			return $many;
		}

		switch ( $n % 10 ) {
			case 1:
				return $one;
			case 2:
			case 3:
			case 4:
				return $few;
			default:
				return $many;
		}
	}

	/**
	 * Сужает главный запрос списка до записей, показанных (или скрытых) на языке.
	 *
	 * Через `meta_query` с EXISTS / NOT EXISTS по ключу флага — тот же приём,
	 * что у ограничения показа на сайте (PostVisibilityGate).
	 *
	 * @param \WP_Query|mixed $query Запрос.
	 */
	public function filterByVisibility( $query ): void {
		global $pagenow;

		if ( ! $query instanceof \WP_Query || ! is_admin() || 'edit.php' !== ( $pagenow ?? '' ) || ! $query->is_main_query() || ! $this->togglesAvailable() ) {
			return;
		}

		$type = $query->get( 'post_type' );
		$type = is_string( $type ) && '' !== $type ? $type : 'post';

		if ( ! $this->enabledFor( $type ) ) {
			return;
		}

		$requested = $this->requestedVisibility();

		if ( null === $requested ) {
			return;
		}

		list( $shown, $locale ) = $requested;

		$key = PostLanguageVisibility::metaKey( $locale );

		if ( '' === $key ) {
			return;
		}

		$clause   = array(
			'key'     => $key,
			'compare' => $shown ? 'EXISTS' : 'NOT EXISTS',
		);
		$existing = $query->get( 'meta_query' );

		// Чужой meta_query (например, от другого плагина) вкладываем целиком и соединяем через AND.
		if ( is_array( $existing ) && array() !== $existing ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'AND',
					$existing,
					$clause,
				)
			);

			return;
		}

		$query->set( 'meta_query', array( $clause ) );
	}

	/**
	 * Добавляет колонку «Языки» перед датой.
	 *
	 * @param array<string, string>|mixed $columns  Колонки списка.
	 * @param string                      $postType Тип записи списка.
	 * @return array<string, string>|mixed
	 */
	public function addColumn( $columns, $postType = 'page' ) {
		if ( ! is_array( $columns ) || ! $this->enabledFor( is_string( $postType ) ? $postType : '' ) ) {
			return $columns;
		}

		$result = array();
		$added  = false;

		foreach ( $columns as $key => $title ) {
			if ( 'date' === $key ) {
				$result[ self::COLUMN ] = __( 'Languages', 'lingocanvas' );
				$added                  = true;
			}

			$result[ $key ] = $title;
		}

		if ( ! $added ) {
			$result[ self::COLUMN ] = __( 'Languages', 'lingocanvas' );
		}

		return $result;
	}

	/**
	 * Выводит ячейку колонки.
	 *
	 * Отрабатывает и при быстром редактировании (AJAX): тогда в основном
	 * запросе других записей нет, и счёт идёт по одной строке.
	 *
	 * @param string $column Ключ колонки.
	 * @param int    $postId Запись строки.
	 */
	public function renderColumn( $column, $postId = 0 ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$post = get_post( (int) $postId );

		if ( ! $post instanceof \WP_Post || ! $this->enabledFor( (string) $post->post_type ) ) {
			return;
		}

		echo $this->cellHtml( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- экранируется внутри.
	}

	/**
	 * Выпадающий список «Перевод» над таблицей.
	 *
	 * @param string $postType Тип записи списка.
	 * @param string $which    Положение панели (top/bottom).
	 */
	public function renderFilter( $postType = '', $which = 'top' ): void {
		if ( 'top' !== $which || ! is_string( $postType ) || ! $this->enabledFor( $postType ) ) {
			return;
		}

		$current   = $this->requestedLocale();
		$requested = $this->requestedVisibility();
		$currentV  = null === $requested ? '' : ( $requested[0] ? self::FILTER_SHOWN : self::FILTER_HIDDEN ) . $requested[1];

		printf( '<select name="%s">', esc_attr( self::FILTER_PARAM ) );
		printf( '<option value="">%s</option>', esc_html__( 'Translation: all', 'lingocanvas' ) );

		foreach ( $this->settings->secondary() as $language ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $language->locale ),
				$current === $language->locale ? ' selected="selected"' : '',
				esc_html( sprintf( __( 'Not fully translated: %s', 'lingocanvas' ), $language->label ) )
			);
		}

		// Показ на языке: только в ручном режиме, когда флаги вообще что-то значат.
		if ( $this->togglesAvailable() ) {
			foreach ( $this->settings->secondary() as $language ) {
				foreach ( array( self::FILTER_HIDDEN => /* translators: %s: language name */ __( 'Hidden on %s', 'lingocanvas' ), self::FILTER_SHOWN => /* translators: %s: language name */ __( 'Shown on %s', 'lingocanvas' ) ) as $prefix => $format ) {
					$value = $prefix . $language->locale;

					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $value ),
						$currentV === $value ? ' selected="selected"' : '',
						esc_html( sprintf( $format, $language->label ) )
					);
				}
			}
		}

		echo '</select>';
	}

	/**
	 * Фильтр показа из адресной строки: [показано ли, язык] либо null.
	 *
	 * Значение приходит из адреса, поэтому принимается только `shown:`/`hidden:`
	 * и код из настроенного списка языков.
	 *
	 * @return array{0: bool, 1: string}|null
	 */
	private function requestedVisibility(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- фильтр только читает данные.
		if ( ! isset( $_GET[ self::FILTER_PARAM ] ) ) {
			return null;
		}

		$raw = sanitize_text_field( wp_unslash( (string) $_GET[ self::FILTER_PARAM ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		foreach ( array( self::FILTER_SHOWN => true, self::FILTER_HIDDEN => false ) as $prefix => $shown ) {
			if ( str_starts_with( $raw, $prefix ) ) {
				$locale = Locale::normalize( substr( $raw, strlen( $prefix ) ) );

				return isset( $this->settings->secondary()[ $locale ] ) ? array( $shown, $locale ) : null;
			}
		}

		return null;
	}

	/**
	 * Сужает главный запрос списка до записей, переведённых не полностью.
	 *
	 * Условие встроено в WHERE (а не отдельным запросом id), поэтому
	 * SQL_CALC_FOUND_ROWS и постраничная навигация считают верно.
	 *
	 * @param array<string, string>|mixed $clauses Части SQL-запроса.
	 * @param \WP_Query|mixed             $query   Запрос.
	 * @return array<string, string>|mixed
	 */
	public function filterClauses( $clauses, $query = null ) {
		global $wpdb, $pagenow;

		if ( ! is_array( $clauses ) || ! $query instanceof \WP_Query || ! is_admin() || 'edit.php' !== ( $pagenow ?? '' ) || ! $query->is_main_query() ) {
			return $clauses;
		}

		$type = $query->get( 'post_type' );
		$type = is_string( $type ) && '' !== $type ? $type : 'post';

		if ( ! $this->enabledFor( $type ) ) {
			return $clauses;
		}

		$locale = $this->requestedLocale();

		if ( '' === $locale ) {
			return $clauses;
		}

		$occurrences  = Schema::table( 'occurrences' );
		$translations = Schema::table( 'translations' );

		/*
		 * «Строки этой записи» — как в SourceRepository::paginate() для области
		 * object:ID: найдена на записи и больше ни на одной.
		 */
		/*
		 * «Нет вхождения на другой записи» равно `COUNT(DISTINCT object_id) = 1`
		 * экрана строк: вхождения без записи (object_id NULL — архивы, поиск)
		 * в сравнении `<>` дают NULL и, как и там, не считаются. Работает по
		 * индексу (source_id, object_id) без подсчёта на каждую строку.
		 */
		$own = PostStringScope::ownCondition( 'a', "{$wpdb->posts}.ID" );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$condition = $wpdb->prepare(
			" AND (NOT EXISTS (SELECT 1 FROM {$occurrences} a WHERE {$own})"
			. " OR EXISTS (SELECT 1 FROM {$occurrences} a LEFT JOIN {$translations} t ON t.source_id = a.source_id AND t.target_locale = %s"
			. " WHERE {$own} AND (t.id IS NULL OR t.translated_text IS NULL OR t.translated_text = '')))",
			$locale
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$clauses['where'] = ( $clauses['where'] ?? '' ) . $condition;

		return $clauses;
	}

	/**
	 * Условие «строка — не адрес ссылки». Чистая функция.
	 *
	 * Правило живёт в PostStringScope (его делит с массовым включением
	 * записей на языке); здесь оставлено под прежним именем.
	 *
	 * @param string $sourceIdExpr SQL-выражение с id строки (`a.source_id`).
	 */
	public static function notLinkSql( string $sourceIdExpr ): string {
		return PostStringScope::notLinkSql( $sourceIdExpr );
	}

	/**
	 * SQL-выражение 1/0: строка относится к SEO/GEO. Чистая функция.
	 *
	 * @param string $sourceIdExpr SQL-выражение с id строки.
	 */
	public static function isSeoSql( string $sourceIdExpr ): string {
		return PostStringScope::isSeoSql( $sourceIdExpr );
	}

	/**
	 * Состояние перевода по счётчикам. Чистая функция.
	 *
	 * @param int $total Всего строк записи.
	 * @param int $done  Из них переведено.
	 * @return array{0: string, 1: int} Состояние и процент (для partial).
	 */
	public static function classify( int $total, int $done ): array {
		if ( $total <= 0 ) {
			return array( self::STATE_NEVER, 0 );
		}

		if ( $done <= 0 ) {
			return array( self::STATE_NONE, 0 );
		}

		if ( $done >= $total ) {
			return array( self::STATE_FULL, 100 );
		}

		// Округление вниз и потолок 99: «100%» у неполного перевода был бы ложью.
		return array( self::STATE_PARTIAL, max( 1, min( 99, (int) floor( $done * 100 / $total ) ) ) );
	}

	/**
	 * Колонка и фильтр нужны: есть право, есть дополнительный язык, тип поддерживается.
	 *
	 * Тип проверяется теми же правилами, что и метабокс «Перевод»
	 * (EditorPostLinks): публичный, не вложение.
	 *
	 * @param string $postType Тип записи.
	 */
	private function enabledFor( string $postType ): bool {
		if ( '' === $postType || 'attachment' === $postType || ! current_user_can( EditorPage::CAPABILITY ) ) {
			return false;
		}

		if ( array() === $this->settings->secondary() ) {
			return false;
		}

		$type = get_post_type_object( $postType );

		return null !== $type && ! empty( $type->public );
	}

	/**
	 * Язык из фильтра; неизвестный или пустой — пустая строка.
	 *
	 * Значение приходит из адресной строки, поэтому принимается только код
	 * из настроенного списка языков.
	 */
	private function requestedLocale(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- фильтр только читает данные.
		if ( ! isset( $_GET[ self::FILTER_PARAM ] ) ) {
			return '';
		}

		$locale = Locale::normalize( sanitize_text_field( wp_unslash( (string) $_GET[ self::FILTER_PARAM ] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return isset( $this->settings->secondary()[ $locale ] ) ? $locale : '';
	}

	/**
	 * HTML ячейки одной записи.
	 *
	 * @param \WP_Post $post Запись строки.
	 */
	private function cellHtml( \WP_Post $post ): string {
		$id = (int) $post->ID;

		$this->prime( $post );

		$path = $this->paths[ $id ] ?? null;

		if ( null === $path ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">'
				. esc_html__( 'Unavailable: the post is not published', 'lingocanvas' ) . '</span>';
		}

		$items = array();

		foreach ( $this->settings->secondary() as $language ) {
			$items[] = $this->languageItem( $language, $id, $path ) . $this->toggleHtml( $post, $language );
		}

		return implode( '<br>', $items );
	}

	/**
	 * Переключатель «на сайте» для языка записи; пустая строка вне ручного режима.
	 *
	 * Недоступный (серый, с пояснением в подсказке) он показывается, когда у
	 * пользователя нет права править именно эту запись или её тип нельзя
	 * включать для языка — так видно, что функция есть, но здесь закрыта.
	 *
	 * @param \WP_Post $post     Запись строки.
	 * @param Language $language Язык.
	 */
	private function toggleHtml( \WP_Post $post, Language $language ): string {
		if ( ! $this->togglesAvailable() ) {
			return '';
		}

		$id       = (int) $post->ID;
		$disabled = '';

		if ( ! PostLanguageVisibility::isEligibleType( (string) $post->post_type ) ) {
			$disabled = __( 'Visibility per language cannot be set for this post type', 'lingocanvas' );
		} elseif ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( EditorPage::CAPABILITY ) ) {
			$disabled = __( 'You do not have permission to edit this post', 'lingocanvas' );
		}

		return ' ' . VisibilityAssets::toggleHtml(
			$id,
			$language->locale,
			$this->visibility->isVisible( $id, $language->locale ),
			__( 'on site', 'lingocanvas' ),
			$disabled
		);
	}

	/**
	 * Строка одного языка: флаг/код, статус и ссылка на строки.
	 *
	 * @param Language $language Язык.
	 * @param int      $postId   Запись.
	 * @param string   $path     Путь записи для редактора.
	 */
	private function languageItem( Language $language, int $postId, string $path ): string {
		list( $total, $done, $seoMissing ) = $this->stats[ $postId ][ $language->locale ] ?? array( 0, 0, 0 );
		list( $state, $percent )           = self::classify( $total, $done );

		switch ( $state ) {
			case self::STATE_FULL:
				$text = '✅';
				$hint = __( 'Fully translated', 'lingocanvas' );
				break;
			case self::STATE_PARTIAL:
				$text = $percent . '%';
				$hint = sprintf( /* translators: 1: translated count, 2: total count */ __( 'Strings translated: %1$d of %2$d', 'lingocanvas' ), $done, $total );
				break;
			case self::STATE_NONE:
				$text = __( 'no translation', 'lingocanvas' );
				$hint = sprintf( /* translators: %d: total number of strings */ __( 'Strings: %d, translated: 0', 'lingocanvas' ), $total );
				break;
			default:
				/*
				 * Строки записи появляются в базе, когда её открыл на сайте на
				 * дополнительном языке владелец (или посетитель — если включён
				 * сбор со страниц посетителей) либо когда запущено «Перевести
				 * весь материал с ИИ». Визуальный редактор строк записи не
				 * собирает, поэтому на него в подсказке не ссылаемся.
				 */
				$text = __( 'no strings yet', 'lingocanvas' );
				$hint = __( 'The plugin has not collected the strings of this post yet: open it on the site in a secondary language or run "Translate all content with AI"', 'lingocanvas' );
		}

		if ( ! $language->isPublished() ) {
			$hint .= ' · ' . __( 'draft language', 'lingocanvas' );
		}

		$stringsUrl = add_query_arg(
			array(
				'page'       => StringTranslationPage::MENU_SLUG,
				'mlp_locale' => $language->locale,
				'mlp_scope'  => SourceRepository::SCOPE_OBJECT . ':' . $postId,
			),
			admin_url( 'admin.php' )
		);

		$title = esc_attr( $language->label . ': ' . $hint );
		$label = $this->flagHtml( $language ) . ' ' . esc_html( $text );

		/*
		 * У языка-черновика адреса на сайте нет (404), а предпросмотр
		 * редактора грузит именно его — ссылку на редактор не даём, остаётся
		 * статус текстом и ссылка на строки.
		 */
		if ( $language->isPublished() ) {
			$head = sprintf( '<a href="%s" title="%s">%s</a>', esc_url( $this->link->urlForPath( $path, $language->locale ) ), $title, $label );
		} else {
			$head = sprintf( '<span title="%s">%s</span> <em>%s</em>', $title, $label, esc_html__( '(draft)', 'lingocanvas' ) );
		}

		$html = sprintf(
			'<span class="mlp-post-lang">%s <a href="%s" title="%s">%s</a>',
			$head,
			esc_url( $stringsUrl ),
			esc_attr__( 'Translate the strings of this post', 'lingocanvas' ),
			esc_html__( 'strings', 'lingocanvas' )
		);

		// Вкладка «Контент» SEO-строк не показывает, а в счёт они входят — даём отдельную ссылку.
		if ( $seoMissing > 0 ) {
			$seoUrl = add_query_arg(
				array(
					'page'       => StringTranslationPage::MENU_SLUG,
					'mlp_tab'    => StringTranslationPage::parseTab( StringTranslationPage::TAB_SEO ),
					'mlp_locale' => $language->locale,
					'mlp_scope'  => SourceRepository::SCOPE_OBJECT . ':' . $postId,
				),
				admin_url( 'admin.php' )
			);

			$html .= sprintf(
				' <a href="%s" title="%s">%s</a>',
				esc_url( $seoUrl ),
				esc_attr__( 'Untranslated SEO/GEO strings of this post', 'lingocanvas' ),
				esc_html( sprintf( 'SEO: %d', $seoMissing ) )
			);
		}

		return $html . '</span>';
	}

	/**
	 * Флаг-картинка, если файл есть, иначе код языка (экранированный).
	 *
	 * @param Language $language Язык.
	 */
	private function flagHtml( Language $language ): string {
		if ( ! array_key_exists( $language->locale, $this->flagUrls ) ) {
			$this->flagUrls[ $language->locale ] = Flags::url( $language->locale );
		}

		$url = $this->flagUrls[ $language->locale ];

		if ( '' !== $url ) {
			return sprintf( '<img src="%s" alt="" width="20" height="15">', esc_url( $url ) ) . ' <strong>' . esc_html( strtoupper( $language->locale ) ) . '</strong>';
		}

		return '<strong>' . esc_html( strtoupper( $language->locale ) ) . '</strong>';
	}

	/**
	 * Считает данные для всех ещё не посчитанных записей текущей страницы списка одним запросом.
	 *
	 * @param \WP_Post $current Запись, ради которой вызвано (нужна даже вне основного запроса — при quick edit).
	 */
	private function prime( \WP_Post $current ): void {
		global $wpdb, $wp_query;

		if ( array_key_exists( (int) $current->ID, $this->paths ) ) {
			return;
		}

		$posts = array( $current );

		if ( isset( $wp_query ) && is_object( $wp_query ) && is_array( $wp_query->posts ?? null ) ) {
			foreach ( $wp_query->posts as $candidate ) {
				if ( $candidate instanceof \WP_Post ) {
					$posts[] = $candidate;
				}
			}
		}

		$ids = array();

		foreach ( $posts as $post ) {
			$id = (int) $post->ID;

			if ( array_key_exists( $id, $this->paths ) ) {
				continue;
			}

			$this->paths[ $id ] = $this->enabledFor( (string) $post->post_type ) ? $this->link->pathForPost( $post ) : null;

			if ( null !== $this->paths[ $id ] ) {
				$ids[ $id ] = $id;
			}
		}

		$locales = array_keys( $this->settings->secondary() );

		// Флаги показа всех строк страницы — одним запросом к метаполям, а не по одному на запись.
		if ( $this->togglesAvailable() && array() !== $ids ) {
			$this->visibility->prime( array_values( $ids ) );
		}

		if ( array() === $ids || array() === $locales ) {
			return;
		}

		$translations = Schema::table( 'translations' );

		$ids      = array_values( $ids );
		$idMarks  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$joins    = '';
		$selects  = '';
		$position = array();

		/*
		 * Один LEFT JOIN на язык вместо группировки по языку: у строки, на
		 * которую перевода ещё нет, нет и записи в translations, и группировка
		 * по target_locale потеряла бы именно непереведённые строки.
		 */
		foreach ( $locales as $i => $locale ) {
			$joins   .= " LEFT JOIN {$translations} t{$i} ON t{$i}.source_id = x.source_id AND t{$i}.target_locale = %s";
			$selects .= ", SUM(CASE WHEN t{$i}.translated_text IS NOT NULL AND t{$i}.translated_text <> '' THEN 1 ELSE 0 END) AS done_{$i}";
			// Непереведённые SEO-строки — отдельным счётчиком для ссылки на вкладку SEO/GEO.
			$selects .= ", SUM(CASE WHEN x.is_seo = 1 AND (t{$i}.id IS NULL OR t{$i}.translated_text IS NULL OR t{$i}.translated_text = '') THEN 1 ELSE 0 END) AS seo_{$i}";

			$position[ $i ] = (string) $locale;
		}

		// Подзапрос x — строки записи как на экране «Перевод строк» (область object:ID).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- SQL собран из кода: таблицы из Schema::table() (allowlist), счётчики $i — целые, подзапрос PostStringScope собран из констант; значения идут через prepare().
		$sql ="SELECT x.object_id AS object_id, COUNT(*) AS total{$selects}
			FROM (
				" . PostStringScope::ownRowsSql( "o.object_id IN ({$idMarks})", true ) . "
			) x{$joins}
			GROUP BY x.object_id";

		// Плейсхолдеры JOIN идут в тексте позже подзапроса: переставляем параметры под порядок в SQL.
		$params = array_merge( $ids, $position );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery

		foreach ( $ids as $id ) {
			foreach ( $locales as $locale ) {
				$this->stats[ $id ][ (string) $locale ] = array( 0, 0, 0 );
			}
		}

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) ( $row['object_id'] ?? 0 );

			foreach ( $locales as $i => $locale ) {
				$this->stats[ $id ][ (string) $locale ] = array(
						(int) ( $row['total'] ?? 0 ),
						(int) ( $row[ 'done_' . $i ] ?? 0 ),
						(int) ( $row[ 'seo_' . $i ] ?? 0 ),
					);
			}
		}
	}
}
