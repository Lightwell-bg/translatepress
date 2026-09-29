<?php
/**
 * Тесты REST-поиска страниц для визуального редактора.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WpMlp\Admin\EditorLink;
use WpMlp\Rest\EditorPagesController;
use WpMlp\Routing\LanguageResolver;
use WpMlp\Routing\UrlConverter;
use WpMlp\Settings\Settings;

#[CoversClass( EditorPagesController::class )]
final class EditorPagesControllerTest extends TestCase {

	/** @var array<int, \WP_Post> */
	private array $posts = array();

	protected function setUp(): void {
		wp_mlp_test_options(
			array(
				'home'           => 'https://example.test',
				Settings::OPTION => array(
					'default_locale' => 'ru',
					'languages'      => array(
						'ru' => array( 'locale' => 'ru', 'slug' => 'ru', 'label' => 'Русский', 'status' => 'published' ),
						'en' => array( 'locale' => 'en', 'slug' => 'en', 'label' => 'English', 'status' => 'published' ),
					),
				),
			)
		);
		$this->posts = array();
		wp_mlp_test_wp( array() );
		\WP_Query::$calls = array();
	}

	protected function tearDown(): void {
		wp_mlp_test_options( array() );
		wp_mlp_test_wp( array() );
	}

	private function controller(): EditorPagesController {
		$settings = new Settings();
		$urls     = new UrlConverter( $settings, new LanguageResolver( $settings ) );

		return new EditorPagesController( $settings, $urls, new EditorLink( $settings, $urls ) );
	}

	private function addPost( int $id, string $title, string $name, string $type = 'post' ): \WP_Post {
		$post               = new \WP_Post(
			array(
				'ID'         => $id,
				'post_title' => $title,
				'post_name'  => $name,
				'post_type'  => $type,
			)
		);
		$this->posts[ $id ] = $post;

		return $post;
	}

	/**
	 * @param array<string, mixed> $extra Дополнительные ключи хранилища.
	 */
	private function sync( array $extra = array() ): void {
		wp_mlp_test_wp(
			array_merge(
				array(
					'posts' => $this->posts,
					'query' => fn(): array => array_values( $this->posts ),
				),
				$extra
			)
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function search( string $q ): array {
		return $this->controller()->search( new \WP_REST_Request( array( 'search' => $q ) ) )->get_data();
	}

	public function testPermissionCallbackFollowsCapability(): void {
		$this->sync( array( 'caps' => array( 'manage_options' => false ) ) );
		$this->assertFalse( $this->controller()->canEdit() );

		$this->sync( array( 'caps' => array( 'manage_options' => true ) ) );
		$this->assertTrue( $this->controller()->canEdit() );
	}

	public function testEmptySearchPutsSyntheticFrontPageFirstWhenNoStaticFront(): void {
		$this->addPost( 1, 'Первая', 'first' );
		$this->sync();

		$items = $this->search( '' );

		$this->assertSame( 'Главная', $items[0]['title'] );
		$this->assertSame( '/', $items[0]['path'] );
		$this->assertSame( 0, $items[0]['id'] );
		$this->assertSame( 1, $items[1]['id'] );
		$this->assertSame( '/first/', $items[1]['path'] );
		$this->assertSame( 'Запись', $items[1]['type_label'] );
		$this->assertStringContainsString( 'post=1', $items[1]['edit_url'] );
	}

	public function testEmptySearchUsesStaticFrontPageOnceAndFirst(): void {
		$this->addPost( 1, 'Первая', 'first' );
		$this->addPost( 7, 'Домашняя', '', 'page' );
		$this->sync();
		wp_mlp_test_options( array_merge( wp_mlp_test_options(), array( 'show_on_front' => 'page', 'page_on_front' => 7 ) ) );

		$items = $this->search( '' );

		$this->assertSame( array( 7, 1 ), array_column( $items, 'id' ) );
		$this->assertSame( '/', $items[0]['path'] );
	}

	public function testEmptySearchQueriesRecentPublishedWithoutAttachments(): void {
		$this->sync();
		$this->search( '' );

		$args = \WP_Query::$calls[0];

		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertSame( 'modified', $args['orderby'] );
		$this->assertSame( array( 'post', 'page' ), $args['post_type'] );
	}

	public function testResultIsCappedAtTwenty(): void {
		for ( $i = 1; $i <= 40; $i++ ) {
			$this->addPost( $i, 'Пост ' . $i, 'p' . $i );
		}
		$this->sync();

		$this->assertCount( 20, $this->search( '' ) );
		$this->assertCount( 20, $this->search( 'Пост' ) );
	}

	public function testTextSearchUsesRelevanceAndExactTitleFirst(): void {
		$this->addPost( 1, 'Про кошек', 'cats' );
		$this->addPost( 2, 'Кошки', 'cats-2' );
		$this->sync(
			array(
				'query' => fn( array $args ): array => isset( $args['title'] ) ? array( $this->posts[2] ) : array( $this->posts[1], $this->posts[2] ),
			)
		);

		$items = $this->search( 'Кошки' );

		$this->assertSame( array( 2, 1 ), array_column( $items, 'id' ) );
		$this->assertSame( 'relevance', \WP_Query::$calls[1]['orderby'] );
		$this->assertSame( 'Кошки', \WP_Query::$calls[1]['s'] );
	}

	public function testSearchIsSanitizedAndCapped(): void {
		$this->sync();
		$this->search( '<b>' . str_repeat( 'я', 300 ) . '</b>' );

		$this->assertSame( str_repeat( 'я', 200 ), \WP_Query::$calls[1]['s'] );
	}

	public function testTitlesAreDecodedToPlainText(): void {
		$this->addPost( 1, 'Tom &amp; Jerry &#039;s', 'tom' );
		$this->addPost( 2, '', 'untitled' );
		$this->sync();

		$items = $this->search( 'x' );

		$this->assertSame( "Tom & Jerry 's", $items[0]['title'] );
		$this->assertSame( '(без названия)', $items[1]['title'] );
	}

	public function testPathQueryAddsSyntheticAddressItem(): void {
		$this->addPost( 1, 'О нас', 'about', 'page' );
		$this->sync( array( 'url_to_id' => array( 'https://example.test/about/' => 1 ) ) );

		$items = $this->search( '/en/about/' );

		$this->assertSame( 1, $items[0]['id'] );
		$this->assertSame( 0, $items[1]['id'] );
		$this->assertSame( 'Адрес', $items[1]['type_label'] );
		$this->assertSame( '/about/', $items[1]['path'] );
		$this->assertSame( '/about/', $items[1]['title'] );
		$this->assertSame( array(), \WP_Query::$calls );
	}

	public function testUrlQueryWithoutPostGivesOnlySyntheticItem(): void {
		$this->sync();

		$items = $this->search( 'https://example.test/en/category/news/' );

		$this->assertCount( 1, $items );
		$this->assertSame( 0, $items[0]['id'] );
		$this->assertSame( '/category/news/', $items[0]['path'] );
	}
}
