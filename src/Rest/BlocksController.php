<?php
/**
 * REST-эндпоинты translation blocks.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpMlp\Rendering\BlockSanitizer;
use WpMlp\Rendering\Segment;
use WpMlp\Settings\Settings;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Support\Hash;
use WpMlp\Support\Hookable;
use WpMlp\Support\Text;

/**
 * `POST /wp-json/mlp/v1/blocks` и `DELETE /wp-json/mlp/v1/blocks/{id}` (ТЗ 10.3).
 *
 * Блок — это целый элемент, который переводится вместе с разметкой внутри.
 * Нужен, когда абзац разорван тегами `<b>` и `<a>`: по кускам такой текст
 * перевести грамотно нельзя, порядок слов в языках разный.
 */
final class BlocksController implements Hookable {

	public const NAMESPACE  = 'mlp/v1';
	public const CAPABILITY = 'manage_options';

	/**
	 * @param SourceRepository $sources  Исходные строки.
	 * @param TranslationCache $cache    Кэш переводов.
	 * @param Settings         $settings Настройки плагина.
	 */
	public function __construct(
		private readonly SourceRepository $sources,
		private readonly TranslationCache $cache,
		private readonly Settings $settings
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Регистрирует маршруты.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/blocks',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => array(
					'html' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn( $value ): string => (string) wp_unslash( $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/blocks/(?P<source_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => array(
					'source_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Право менять переводы.
	 */
	public function canEdit(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Создаёт блок из разметки элемента.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$html = BlockSanitizer::sanitize( (string) $request->get_param( 'html' ) );

		if ( ! Text::isTranslatable( wp_strip_all_tags( $html ) ) ) {
			return new WP_Error(
				'mlp_empty_block',
				__( 'В этом фрагменте нечего переводить.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$locale      = $this->settings->defaultLanguage()->locale;
		$sourceHash  = Hash::of( $html );
		$contextHash = Hash::of( '' );
		$uniqHash    = Hash::ofParts( array( $locale, Segment::KIND_HTML_BLOCK, $sourceHash, $contextHash, '', '', '' ) );

		$this->sources->insertMissing(
			array(
				array(
					'locale'       => $locale,
					'kind'         => Segment::KIND_HTML_BLOCK,
					'text'         => $html,
					'source_hash'  => $sourceHash,
					'context_hash' => $contextHash,
					'uniq_hash'    => $uniqHash,
				),
			)
		);

		$ids = $this->sources->idsByHashes( array( $uniqHash ) );

		if ( ! isset( $ids[ $uniqHash ] ) ) {
			return new WP_Error(
				'mlp_block_failed',
				__( 'Не удалось создать блок.', 'wp-mlp' ),
				array( 'status' => 500 )
			);
		}

		// Список блоков влияет на разбор каждой страницы — сбрасываем его кэш.
		$this->sources->flushBlockHashes();
		$this->cache->flush();

		return new WP_REST_Response(
			array(
				'id'          => $ids[ $uniqHash ],
				'kind'        => Segment::KIND_HTML_BLOCK,
				'source_text' => $html,
			),
			201
		);
	}

	/**
	 * Удаляет блок вместе с его переводами.
	 *
	 * После удаления части абзаца снова переводятся по отдельности.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$sourceId = (int) $request->get_param( 'source_id' );
		$source   = $this->sources->find( $sourceId );

		if ( null === $source || Segment::KIND_HTML_BLOCK !== (string) $source['kind'] ) {
			return new WP_Error(
				'mlp_block_not_found',
				__( 'Блок не найден.', 'wp-mlp' ),
				array( 'status' => 404 )
			);
		}

		$this->sources->deleteWithTranslations( $sourceId );
		$this->sources->flushBlockHashes();
		$this->cache->flush();

		return new WP_REST_Response( array( 'id' => $sourceId ) );
	}
}
