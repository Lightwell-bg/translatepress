<?php
/**
 * Ручной показ записей на дополнительных языках: списки, запись, меню.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

use WP_Query;
use WpMlp\Rendering\EditorContext;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;
use WpMlp\Storage\PostLanguageVisibility;
use WpMlp\Support\Hookable;

defined( 'ABSPATH' ) || exit;

/**
 * Пока режим «только включённые вручную», на дополнительном языке посетитель
 * видит записи и страницы, которые владелец отметил для этого языка, и
 * только их. Остальные для него не существуют: нет в блоге, рубриках, поиске,
 * виджетах, «похожих», меню, соседних записях и на собственном адресе (он
 * ведёт на русскую версию).
 *
 * Заменяет прежний автоматический фильтр по «записи с хотя бы одним переводом»:
 * общие строки (названия рубрик в карточках) есть на каждой записи, поэтому
 * переведённой выглядела любая запись, и на /de/ попадал весь русский блог.
 *
 * Работает только на публичной части сайта. Не вмешивается в админку, AJAX,
 * REST и cron; не вмешивается в предпросмотр визуального редактора
 * (EditorContext) — владельцу нужно видеть страницу, которую он как раз
 * переводит. Для остальных исключений (например, показ скрытых страниц
 * администратору) есть фильтр `mlp_visibility_bypass`.
 */
final class PostVisibilityGate implements Hookable {

	/**
	 * Имя именованного условия в meta_query: по нему его можно узнать и
	 * при необходимости убрать из чужого кода.
	 */
	public const CLAUSE_KEY = 'mlp_visible';

	/**
	 * Переменная запроса «не фильтровать»: для внутренних выборок плагина
	 * (карта сайта собирает видимость сама).
	 */
	public const SKIP_QUERY_VAR = 'mlp_ignore_visibility';

	/**
	 * Служебная отметка «условие уже добавлено» — запрос могут разобрать повторно.
	 */
	private const APPLIED_QUERY_VAR = 'mlp_visibility_applied';

	/**
	 * @param Settings               $settings   Настройки плагина.
	 * @param LanguageResolver       $resolver   Язык текущего запроса.
	 * @param PostLanguageVisibility $visibility Флаги видимости записей.
	 * @param EditorContext          $editor     Режим предпросмотра редактора.
	 * @param UrlConverter           $urls       Построение языковых адресов.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly LanguageResolver $resolver,
		private readonly PostLanguageVisibility $visibility,
		private readonly EditorContext $editor,
		private readonly UrlConverter $urls
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'filterQuery' ) );
		add_filter( 'get_previous_post_where', array( $this, 'filterAdjacentWhere' ), 10, 5 );
		add_filter( 'get_next_post_where', array( $this, 'filterAdjacentWhere' ), 10, 5 );
		add_filter( 'get_pages', array( $this, 'filterPages' ), 10, 1 );
		// Раньше пунктов-переключателей языка (NavMenu::injectLanguages, приоритет 10).
		add_filter( 'wp_nav_menu_objects', array( $this, 'filterMenuItems' ), 5, 1 );
		// Блочные темы: меню — это блоки навигации, а не пункты wp_nav_menu().
		add_filter( 'render_block', array( $this, 'filterNavigationBlock' ), 10, 2 );
		// Последние комментарии и ленты комментариев не должны выдавать скрытые записи.
		add_filter( 'comments_clauses', array( $this, 'filterCommentsClauses' ), 10, 2 );
		// После CanonicalRedirect (приоритет 5): сначала приводим адрес к канону.
		add_action( 'template_redirect', array( $this, 'maybeRedirect' ), 6 );
	}

	/**
	 * Нужно ли вообще ограничивать показ в этом запросе.
	 *
	 * Ответ намеренно не запоминается: первые запросы могут идти до `init`,
	 * когда пользователь ещё не определён, и фильтр `mlp_visibility_bypass`
	 * с проверкой прав дал бы неверный ответ на весь остальной запрос.
	 * Составные части (настройки, язык, режим редактора) запоминают себя сами.
	 */
	public function isEnforced(): bool {
		return $this->detectEnforced();
	}

	/**
	 * Добавляет в запрос условие «запись включена для текущего языка».
	 *
	 * Затрагиваются все запросы публичных типов записей — главный, а также
	 * вторичные (виджеты, «похожие», «свежие записи»), кроме выбора одной
	 * записи главным запросом: её судьбу решает редирект (maybeRedirect()).
	 *
	 * Запросы с `suppress_filters` тоже затрагиваются: `pre_get_posts` у
	 * них срабатывает, а `get_posts()` ставит этот флаг по умолчанию — иначе
	 * виджеты, построенные на `get_posts()`, обходили бы скрытие. Условие
	 * кладётся в `meta_query` (параметры запроса, а не SQL-фильтр), поэтому от
	 * флага не зависит. Вложения, пункты меню и непубличные типы не
	 * затрагиваются; запрос, где такой тип смешан с публичными, тоже
	 * пропускается целиком — подменить его осмысленно нельзя.
	 *
	 * @param WP_Query|mixed $query Выполняемый запрос.
	 */
	public function filterQuery( $query ): void {
		if ( ! $query instanceof WP_Query || ! $this->isEnforced() ) {
			return;
		}

		if ( $query->get( self::SKIP_QUERY_VAR ) || $query->get( self::APPLIED_QUERY_VAR ) ) {
			return;
		}

		// Выбор одной записи главным запросом — не перечисление.
		if ( $query->is_main_query() && $query->is_singular() ) {
			return;
		}

		if ( ! $this->appliesToPostTypes( $query->get( 'post_type' ) ) ) {
			return;
		}

		$key = PostLanguageVisibility::metaKey( $this->resolver->currentLocale() );

		if ( '' === $key ) {
			return;
		}

		$clause   = array(
			'key'     => $key,
			'compare' => 'EXISTS',
		);
		$existing = $query->get( 'meta_query' );

		/*
		 * Чужой meta_query не затираем и не вливаем в одну плоскость: у него
		 * может быть своё `relation => OR`, и условие, добавленное рядом,
		 * сработало бы как «или». Вкладываем его целиком и соединяем с нашим
		 * через AND.
		 */
		if ( is_array( $existing ) && array() !== $existing ) {
			$metaQuery = array(
				'relation'       => 'AND',
				$existing,
				self::CLAUSE_KEY => $clause,
			);
		} else {
			$metaQuery = array( self::CLAUSE_KEY => $clause );
		}

		$query->set( 'meta_query', $metaQuery );
		$query->set( self::APPLIED_QUERY_VAR, true );
	}

	/**
	 * Предыдущая/следующая запись не должна вести на скрытую.
	 *
	 * `get_adjacent_post()` ходит в базу сам, мимо WP_Query, но у него есть
	 * фильтр условия WHERE (алиас таблицы записей — `p`).
	 *
	 * @param string          $where       Условие WHERE.
	 * @param bool            $inSameTerm  Только из той же рубрики.
	 * @param string|string[] $excluded    Исключённые рубрики.
	 * @param string          $taxonomy    Таксономия.
	 * @param \WP_Post|null   $post        Текущая запись.
	 */
	public function filterAdjacentWhere( $where, $inSameTerm = false, $excluded = '', $taxonomy = 'category', $post = null ): string {
		global $wpdb;

		unset( $inSameTerm, $excluded, $taxonomy );

		$where = (string) $where;

		if ( ! $this->isEnforced() || ! $post instanceof \WP_Post || ! PostLanguageVisibility::isEligibleType( (string) $post->post_type ) ) {
			return $where;
		}

		$key = PostLanguageVisibility::metaKey( $this->resolver->currentLocale() );

		if ( '' === $key ) {
			return $where;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- таблица метаполей из $wpdb, значение через prepare().
		return $where . $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} mlp_pm WHERE mlp_pm.post_id = p.ID AND mlp_pm.meta_key = %s)", $key );
	}

	/**
	 * Убирает скрытые страницы из `get_pages()` (виджет «Страницы», `wp_page_menu`).
	 *
	 * @param array<int, mixed>|mixed $pages Найденные страницы.
	 * @return array<int, mixed>|mixed
	 */
	public function filterPages( $pages ) {
		if ( ! is_array( $pages ) || array() === $pages || ! $this->isEnforced() ) {
			return $pages;
		}

		$ids = array();

		foreach ( $pages as $page ) {
			if ( $page instanceof \WP_Post ) {
				$ids[] = (int) $page->ID;
			}
		}

		$this->visibility->prime( $ids );

		$locale = $this->resolver->currentLocale();

		return array_values(
			array_filter(
				$pages,
				fn( $page ): bool => ! $page instanceof \WP_Post
					|| ! PostLanguageVisibility::isEligibleType( (string) $page->post_type )
					|| $this->visibility->isVisible( (int) $page->ID, $locale )
			)
		);
	}

	/**
	 * Убирает из меню пункты, ведущие на скрытые записи и страницы.
	 *
	 * Дочерние пункты скрытого пункта убираются вместе с ним, даже если сами
	 * они видны: в обычной вёрстке меню они раскрываются из родителя, и без
	 * него остались бы висеть выпадающим подменю без пункта-заголовка или
	 * поднялись бы на верхний уровень, поменяв структуру меню. Владелец
	 * может включить родителя — тогда вернётся всё поддерево.
	 *
	 * @param array<int, object>|mixed $items Пункты меню, готовые к выводу.
	 * @return array<int, object>|mixed
	 */
	public function filterMenuItems( $items ) {
		if ( ! is_array( $items ) || array() === $items || ! $this->isEnforced() ) {
			return $items;
		}

		$postIds = array();

		foreach ( $items as $item ) {
			if ( $this->isPostItem( $item ) ) {
				$postIds[] = (int) $item->object_id;
			}
		}

		if ( array() === $postIds ) {
			return $items;
		}

		$this->visibility->prime( $postIds );

		$locale = $this->resolver->currentLocale();
		$hidden = array();

		foreach ( $items as $item ) {
			if ( $this->isPostItem( $item ) && ! $this->visibility->isVisible( (int) $item->object_id, $locale ) ) {
				$hidden[ (int) ( $item->db_id ?? $item->ID ?? 0 ) ] = true;
			}
		}

		if ( array() === $hidden ) {
			return $items;
		}

		// Потомки: порядок пунктов в массиве не гарантирует «родитель раньше детей» — идём, пока находятся новые.
		do {
			$grew = false;

			foreach ( $items as $item ) {
				$dbId   = (int) ( $item->db_id ?? $item->ID ?? 0 );
				$parent = (int) ( $item->menu_item_parent ?? 0 );

				if ( ! isset( $hidden[ $dbId ] ) && $parent > 0 && isset( $hidden[ $parent ] ) ) {
					$hidden[ $dbId ] = true;
					$grew            = true;
				}
			}
		} while ( $grew );

		return array_values(
			array_filter(
				$items,
				static fn( $item ): bool => ! isset( $hidden[ (int) ( $item->db_id ?? $item->ID ?? 0 ) ] )
			)
		);
	}

	/**
	 * Убирает из блочного меню ссылки (и подменю) на скрытые записи и страницы.
	 *
	 * Блоки `core/navigation-link` и `core/navigation-submenu` с `kind = post-type`
	 * хранят id записи в `attrs.id`. Подменю убирается целиком вместе с
	 * вложенными ссылками — как поддерево скрытого пункта в классическом меню.
	 *
	 * @param string|mixed         $html  Готовая разметка блока.
	 * @param array<string, mixed>|mixed $block Разобранный блок.
	 * @return string|mixed
	 */
	public function filterNavigationBlock( $html, $block = array() ) {
		if ( ! is_string( $html ) || '' === $html || ! is_array( $block ) ) {
			return $html;
		}

		if ( ! in_array( $block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
			return $html;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		if ( 'post-type' !== ( $attrs['kind'] ?? '' ) ) {
			return $html;
		}

		$postId = (int) ( $attrs['id'] ?? 0 );

		if ( $postId <= 0 || ! $this->isEnforced() ) {
			return $html;
		}

		$type = isset( $attrs['type'] ) && is_string( $attrs['type'] ) ? $attrs['type'] : '';

		if ( '' !== $type ? ! PostLanguageVisibility::isEligibleType( $type ) : ! $this->visibility->isEligiblePost( $postId ) ) {
			return $html;
		}

		$this->visibility->prime( array( $postId ) );

		return $this->visibility->isVisible( $postId, $this->resolver->currentLocale() ) ? $html : '';
	}

	/**
	 * Исключает из выборки комментариев те, что оставлены к скрытым записям.
	 *
	 * Затрагивает виджет «Свежие комментарии» и ленты комментариев. К записям
	 * неподходящих типов (вложения, непубличные) условие не относится. На
	 * странице скрытой записи, которую редактор видит вместо редиректа, его
	 * собственные комментарии остаются.
	 *
	 * @param array<string, mixed>|mixed $clauses Части SQL запроса комментариев.
	 * @param mixed                      $query   WP_Comment_Query.
	 * @return array<string, mixed>|mixed
	 */
	public function filterCommentsClauses( $clauses, $query = null ) {
		global $wpdb;

		if ( ! is_array( $clauses ) || ! $this->isEnforced() ) {
			return $clauses;
		}

		$key   = PostLanguageVisibility::metaKey( $this->resolver->currentLocale() );
		$types = PostLanguageVisibility::eligibleTypes();

		if ( '' === $key || array() === $types ) {
			return $clauses;
		}

		$vars   = is_object( $query ) && isset( $query->query_vars ) && is_array( $query->query_vars ) ? $query->query_vars : array();
		$postId = (int) ( $vars['post_id'] ?? 0 );

		if ( $postId > 0 && self::editorSeesHiddenPost( $postId ) ) {
			return $clauses;
		}

		$typeMarks = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$condition = $wpdb->prepare(
			"( NOT EXISTS (SELECT 1 FROM {$wpdb->posts} mlp_cp WHERE mlp_cp.ID = {$wpdb->comments}.comment_post_ID AND mlp_cp.post_type IN ({$typeMarks}))"
			. " OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} mlp_cm WHERE mlp_cm.post_id = {$wpdb->comments}.comment_post_ID AND mlp_cm.meta_key = %s) )",
			array_merge( $types, array( $key ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$where            = trim( (string) ( $clauses['where'] ?? '' ) );
		$clauses['where'] = '' === $where ? $condition : $where . ' AND ' . $condition;

		return $clauses;
	}

	/**
	 * Уводит со скрытой записи на её русскую версию.
	 *
	 * Хук `template_redirect`.
	 */
	public function maybeRedirect(): void {
		$target = $this->redirectTarget( CurrentPageLanguages::queriedPostId() );

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * Куда увести со страницы записи, или null, если уводить не нужно.
	 *
	 * 302, а не 301: решение владельца обратимо — завтра он включит запись, и
	 * браузеры с поисковиками не должны навсегда запомнить редирект.
	 * Целевой адрес — тот же путь на языке по умолчанию. Этот язык ограничению
	 * не подчиняется, поэтому петли быть не может. Статическая главная и
	 * страница записей ведут себя так же, как любая страница: если они не
	 * включены для языка, /de/ уводит на /.
	 *
	 * @param int $postId Запись, которую показывает главный запрос (0 — не запись).
	 */
	public function redirectTarget( int $postId ): ?string {
		if ( $postId <= 0 || ! $this->isEnforced() || ! $this->visibility->isEligiblePost( $postId ) ) {
			return null;
		}

		if ( $this->visibility->isVisible( $postId, $this->resolver->currentLocale() ) ) {
			return null;
		}

		if ( self::editorSeesHiddenPost( $postId ) ) {
			return null;
		}

		return $this->urls->canonicalUrlFor( $this->settings->defaultLanguage() );
	}

	/**
	 * Видит ли пользователь скрытую запись вместо редиректа: может её править и видит админ-панель.
	 *
	 * Обход касается только страницы самой записи. Списки, меню, карта сайта
	 * и соседние записи фильтруются как обычно, поэтому глобальный фильтр
	 * `mlp_visibility_bypass` для этого не годится: он снял бы и их. Админ-панель
	 * обязательна: в ней плашка «Скрыто на DE — посетители не видят»; без неё
	 * владелец принял бы скрытую страницу за опубликованную.
	 *
	 * @param int $postId Запись.
	 */
	public static function editorSeesHiddenPost( int $postId ): bool {
		return $postId > 0 && function_exists( 'is_admin_bar_showing' ) && is_admin_bar_showing() && current_user_can( 'edit_post', $postId );
	}

	/**
	 * Определяет, действует ли ограничение в этом запросе.
	 */
	private function detectEnforced(): bool {
		if ( ! $this->settings->isManualPostVisibility() || $this->resolver->isDefault() || $this->resolver->isUnavailable() ) {
			return false;
		}

		/*
		 * Запросы до `init` (редкие, служебные) не трогаем: пользователь ещё не
		 * определён, а режим редактора и фильтр обхода без этого дали бы
		 * неверный и запомненный ответ (EditorContext запоминает первый).
		 */
		if ( ! did_action( 'init' ) ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		/**
		 * Позволяет не скрывать записи в этом запросе (например, для администратора).
		 *
		 * @param bool $bypass По умолчанию false.
		 */
		if ( (bool) apply_filters( 'mlp_visibility_bypass', false ) ) {
			return false;
		}

		return ! $this->editor->isActive();
	}

	/**
	 * Подходят ли типы записей запроса для ограничения.
	 *
	 * @param mixed $postTypes Значение переменной запроса `post_type`.
	 */
	private function appliesToPostTypes( $postTypes ): bool {
		if ( null === $postTypes || false === $postTypes || '' === $postTypes || array() === $postTypes || 'any' === $postTypes ) {
			return true;
		}

		foreach ( (array) $postTypes as $type ) {
			if ( ! is_string( $type ) || ! PostLanguageVisibility::isEligibleType( $type ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Пункт меню ведёт на запись или страницу.
	 *
	 * @param mixed $item Пункт меню.
	 */
	private function isPostItem( $item ): bool {
		return is_object( $item )
			&& 'post_type' === ( $item->type ?? '' )
			&& (int) ( $item->object_id ?? 0 ) > 0
			&& PostLanguageVisibility::isEligibleType( (string) ( $item->object ?? '' ) );
	}
}
