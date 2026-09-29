<?php
/**
 * REST-поиск страниц для выбора в визуальном редакторе.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WpMlp\Admin\EditorLink;
use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;
use WpMlp\Routing\UrlConverter;

/**
 * `GET /wp-json/mlp/v1/editor/pages?search=…`.
 *
 * Отдаёт до 20 элементов `{id, title, type_label, path, edit_url?}` для
 * выпадающего списка выбора страницы. Заголовки уходят простым текстом, без
 * HTML: интерфейс обязан выводить их через `textContent`.
 */
final class EditorPagesController implements Hookable {

	public const NAMESPACE  = 'mlp/v1';
	public const CAPABILITY = 'manage_options';
	public const LIMIT      = 20;
	public const MAX_QUERY  = 200;

	/**
	 * @param Settings     $settings Настройки плагина.
	 * @param UrlConverter $urls     Построение языковых адресов.
	 * @param EditorLink   $link     Пути и адреса редактора.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly UrlConverter $urls,
		private readonly EditorLink $link
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Регистрирует маршрут.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/editor/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => array(
					'search' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);
	}

	/**
	 * Право пользоваться редактором.
	 */
	public function canEdit(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Ищет страницы.
	 *
	 * @param WP_REST_Request $request Запрос.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$query = sanitize_text_field( wp_unslash( (string) $request->get_param( 'search' ) ) );
		$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, self::MAX_QUERY ) : substr( $query, 0, self::MAX_QUERY );

		if ( '' === $query ) {
			$items = $this->recent();
		} elseif ( '/' === $query[0] || 1 === preg_match( '#^https?://#i', $query ) ) {
			$items = $this->byAddress( $query );
		} else {
			$items = $this->byText( $query );
		}

		return new WP_REST_Response( array_slice( $items, 0, self::LIMIT ) );
	}

	/**
	 * Пустой запрос: главная и последние изменённые записи.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function recent(): array {
		$items   = array();
		$frontId = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front', 0 ) : 0;
		$front   = $frontId > 0 ? $this->item( $frontId ) : null;

		$items[] = $front ?? array(
			'id'         => 0,
			'title'      => __( 'Главная', 'wp-mlp' ),
			'type_label' => __( 'Адрес', 'wp-mlp' ),
			'path'       => '/',
		);

		foreach ( $this->query(
			array(
				'orderby' => 'modified',
				'order'   => 'DESC',
			)
		) as $post ) {
			if ( (int) $post->ID !== $frontId ) {
				$items[] = $this->item( $post );
			}
		}

		return array_values( array_filter( $items ) );
	}

	/**
	 * Текстовый поиск: точное совпадение заголовка первым, дальше по релевантности.
	 *
	 * @param string $query Строка поиска.
	 * @return list<array<string, mixed>>
	 */
	private function byText( string $query ): array {
		$posts = array();

		foreach ( $this->query( array( 'title' => $query ), 5 ) as $post ) {
			$posts[ (int) $post->ID ] = $post;
		}

		foreach ( $this->query(
			array(
				's'       => $query,
				'orderby' => 'relevance',
			)
		) as $post ) {
			$posts[ (int) $post->ID ] = $posts[ (int) $post->ID ] ?? $post;
		}

		return array_values( array_filter( array_map( fn( $post ) => $this->item( $post ), array_values( $posts ) ) ) );
	}

	/**
	 * Адрес или путь: найденная запись плюс синтетический элемент «Адрес»,
	 * чтобы можно было открыть и то, что записью не является (рубрику, архив).
	 *
	 * @param string $query Адрес или путь.
	 * @return list<array<string, mixed>>
	 */
	private function byAddress( string $query ): array {
		$path  = $this->link->normalizeInput( $query );
		$items = array();
		$id    = (int) url_to_postid( $this->urls->absolute( $path, $this->settings->defaultLanguage() ) );
		$found = $id > 0 ? $this->item( $id ) : null;

		if ( null !== $found ) {
			$items[] = $found;
		}

		$items[] = array(
			'id'         => 0,
			'title'      => $path,
			'type_label' => __( 'Адрес', 'wp-mlp' ),
			'path'       => $path,
		);

		return $items;
	}

	/**
	 * Выборка опубликованных записей всех публичных типов, кроме вложений.
	 *
	 * @param array<string, mixed> $args  Дополнительные параметры WP_Query.
	 * @param int                  $limit Сколько записей взять.
	 * @return list<\WP_Post>
	 */
	private function query( array $args, int $limit = self::LIMIT ): array {
		$types = array_values( array_diff( array_values( (array) get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) ) );

		$wpQuery = new \WP_Query(
			array_merge(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'posts_per_page'      => $limit,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
				),
				$args
			)
		);

		return array_values( array_filter( (array) $wpQuery->posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
	}

	/**
	 * Собирает элемент ответа. null — запись открыть в редакторе нельзя.
	 *
	 * @param \WP_Post|int $post Запись или её id.
	 * @return array<string, mixed>|null
	 */
	private function item( $post ): ?array {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$path = $this->link->pathForPost( $post );

		if ( null === $path ) {
			return null;
		}

		$title = trim( html_entity_decode( wp_specialchars_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES ), ENT_QUOTES, 'UTF-8' ) );
		$type  = get_post_type_object( (string) $post->post_type );
		$item  = array(
			'id'         => (int) $post->ID,
			'title'      => '' !== $title ? $title : __( '(без названия)', 'wp-mlp' ),
			'type_label' => (string) ( $type->labels->singular_name ?? $post->post_type ),
			'path'       => $path,
		);

		$edit = current_user_can( 'edit_post', (int) $post->ID ) ? get_edit_post_link( (int) $post->ID, 'raw' ) : '';

		if ( is_string( $edit ) && '' !== $edit ) {
			$item['edit_url'] = $edit;
		}

		return $item;
	}
}
