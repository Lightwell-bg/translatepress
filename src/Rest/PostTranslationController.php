<?php
/**
 * REST-эндпоинты массового перевода записи целиком.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rest;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WpMlp\Rendering\BlockSanitizer;
use WpMlp\Rendering\PostContentExtractor;
use WpMlp\Rendering\PostSegment;
use WpMlp\Rendering\Segment;
use WpMlp\Settings\Settings;
use WpMlp\Storage\OccurrenceRepository;
use WpMlp\Storage\PostTranslationSnapshot;
use WpMlp\Storage\SourceRepository;
use WpMlp\Storage\TranslationCache;
use WpMlp\Storage\TranslationRepository;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Storage\UsageTracker;
use WpMlp\Support\Hash;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;
use WpMlp\Support\ShortcodeGuard;
use WpMlp\Translation\BatchChunker;
use WpMlp\Translation\OpenAiProvider;
use WpMlp\Translation\ProviderInterface;
use WpMlp\Translation\TranslationContext;

/**
 * «Перевести весь материал с ИИ» — заголовок, excerpt и `post_content` одной
 * операцией, а не по клику на каждый узел (визуальный редактор Этапа 3 для
 * этого не годился: один клик — одна строка).
 *
 * Три шага, каждый свой REST-запрос — не потому что так красивее, а чтобы
 * не упереться в `max_execution_time` на большой записи и не потерять
 * прогресс при сетевой ошибке на одном из чанков:
 *
 * 1. `GET .../summary` — без единого обращения к ИИ. Разбирает запись,
 *    заводит недостающие строки словаря (как обычное «ленивое обнаружение»,
 *    только по сырым полям записи, а не по готовой странице), сравнивает
 *    со слепком прошлого перевода и возвращает границы чанков — сколько
 *    запросов к ИИ понадобится и что в каждый из них войдёт. Дальше решает
 *    браузер, а не сервер: столько последовательных запросов на chunk,
 *    сколько чанков вернул summary.
 * 2. `POST .../chunk` — один запрос к провайдеру на один чанк id'ов.
 *    Ничего не сохраняет: результат уходит в панель редактора черновиком,
 *    как и одиночный «Перевести с ИИ» (см. TranslationsController::suggest()).
 * 3. `POST .../commit` — единственный шаг, который пишет в БД. Пишет одной
 *    SQL-транзакцией: ошибка на любой строке — откат всех, а не половина
 *    записи с половиной перевода.
 */
final class PostTranslationController implements Hookable {

	public const NAMESPACE  = 'mlp/v1';
	public const CAPABILITY = 'manage_options';

	/** Режим «перевести только пустые сегменты». */
	public const MODE_EMPTY = 'empty';

	/** Режим «перевести заново весь материал». */
	public const MODE_ALL = 'all';

	/**
	 * Сколько id одним запросом принимает `/chunk` и `/commit` — защита от
	 * запроса-переростка, а не от честного использования: чанки собирает
	 * сам сервер в /summary куда меньшего размера.
	 */
	private const MAX_IDS_PER_REQUEST = 400;

	/**
	 * @param Settings               $settings     Настройки плагина.
	 * @param PostContentExtractor   $extractor    Разбор записи на сегменты.
	 * @param SourceRepository       $sources      Исходные строки.
	 * @param OccurrenceRepository   $occurrences  Места использования строк.
	 * @param TranslationRepository  $translations Переводы.
	 * @param TranslationCache       $cache        Кэш переводов.
	 * @param ProviderInterface      $provider     Провайдер машинного перевода.
	 * @param UsageTracker           $usage        Дневной бюджет символов.
	 * @param PostTranslationSnapshot $snapshot    Слепок прошлого массового перевода.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly PostContentExtractor $extractor,
		private readonly SourceRepository $sources,
		private readonly OccurrenceRepository $occurrences,
		private readonly TranslationRepository $translations,
		private readonly TranslationCache $cache,
		private readonly ProviderInterface $provider,
		private readonly UsageTracker $usage,
		private readonly PostTranslationSnapshot $snapshot
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
		$postAndLocale = array(
			'post_id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'locale'  => array(
				'required'          => true,
				'sanitize_callback' => static fn( $value ): string => Locale::normalize( (string) $value ),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>\d+)/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $postAndLocale + array(
					'mode' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( self::MODE_EMPTY, self::MODE_ALL ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>\d+)/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chunk' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $postAndLocale + array(
					'hashes' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>\d+)/commit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'commit' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $postAndLocale + array(
					'segments' => array(
						'required' => true,
						'type'     => 'array',
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
	 * Шаг 1: разбирает запись, заводит недостающие строки, возвращает
	 * полный список сегментов и границы чанков для шага 2.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function summary( WP_REST_Request $request ) {
		$context = $this->resolveContext( $request );

		if ( $context instanceof WP_Error ) {
			return $context;
		}

		list( $post, $language ) = $context;

		$mode        = (string) $request->get_param( 'mode' );
		$sourceLocale = $this->settings->defaultLanguage()->locale;

		$result = $this->extractor->extract( $post, $sourceLocale, $this->sources->blockHashes() );

		// Одна и та же фраза в заголовке и в тексте — одна строка словаря:
		// в ИИ и в отклик она идёт один раз, а не по разу на каждое появление.
		$unique = array();

		foreach ( $result->segments as $postSegment ) {
			$unique[ $postSegment->segment->uniqHash ] ??= $postSegment;
		}

		if ( array() === $unique ) {
			return new WP_REST_Response(
				array(
					'total'   => 0,
					'chunks'  => array(),
					'segments' => array(),
				)
			);
		}

		$this->registerDiscovered( $unique, $sourceLocale, (int) $post->ID );

		$ids   = $this->sources->idsByHashes( array_keys( $unique ) );
		$found = $this->sources->lookup( array_keys( $unique ), $language->locale );

		$previousHashes = $this->snapshot->hashesFor( (int) $post->ID, $language->locale );
		$changed        = PostTranslationSnapshot::changed( array_keys( $unique ), $previousHashes );

		$segments = array();
		$toSend   = array();

		foreach ( $unique as $hash => $postSegment ) {
			$existingText   = (string) ( $found[ $hash ]['text'] ?? '' );
			$existingStatus = (string) ( $found[ $hash ]['status'] ?? TranslationStatus::MISSING );
			$needsTranslation = self::MODE_ALL === $mode || '' === trim( $existingText );

			$segments[] = array(
				'uniq_hash'       => $hash,
				'id'              => $ids[ $hash ] ?? null,
				'field'           => $postSegment->field,
				'kind'            => $postSegment->segment->kind,
				'attribute'       => $postSegment->segment->attribute,
				'source_text'     => $postSegment->segment->text,
				'translated_text' => $existingText,
				'status'          => $existingStatus,
				'changed'         => isset( $changed[ $hash ] ),
			);

			if ( $needsTranslation ) {
				$toSend[ $hash ] = $postSegment->segment->text;
			}
		}

		return new WP_REST_Response(
			array(
				'total'    => count( $unique ),
				'to_translate' => count( $toSend ),
				'chunks'   => array_map( 'array_keys', BatchChunker::chunk( $toSend ) ),
				'segments' => $segments,
			)
		);
	}

	/**
	 * Шаг 2: переводит один чанк через провайдера. Ничего не сохраняет.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chunk( WP_REST_Request $request ) {
		$context = $this->resolveContext( $request );

		if ( $context instanceof WP_Error ) {
			return $context;
		}

		list( , $language ) = $context;

		$sourceLocale = $this->settings->defaultLanguage()->locale;

		if ( ! $this->provider->supports( $sourceLocale, $language->locale ) ) {
			return new WP_Error(
				'mlp_provider_unavailable',
				__( 'Перевод с ИИ не настроен.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$hashes = $this->sanitizeHashList( $request->get_param( 'hashes' ) );

		if ( array() === $hashes ) {
			return new WP_Error(
				'mlp_empty_chunk',
				__( 'В запросе нет ни одной строки.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$rows = $this->sources->findMany( array_values( $this->sources->idsByHashes( $hashes ) ) );

		// idsByHashes() отдаёт id по хешу — findMany() нужны сами id.
		$byHash = array();

		foreach ( $rows as $row ) {
			$byHash[ (string) $row['uniq_hash'] ] = $row;
		}

		$items = array();

		foreach ( $hashes as $hash ) {
			if ( isset( $byHash[ $hash ] ) ) {
				$items[ $hash ] = (string) $byHash[ $hash ]['source_text'];
			}
		}

		if ( array() === $items ) {
			return new WP_Error(
				'mlp_unknown_hashes',
				__( 'Эти строки не найдены — обновите список и попробуйте снова.', 'wp-mlp' ),
				array( 'status' => 404 )
			);
		}

		$totalChars = array_sum( array_map( 'mb_strlen', $items ) );
		$dailyLimit = $this->settings->openAiDailyCharLimit();

		if ( $dailyLimit > 0 && $this->usage->remainingToday( $dailyLimit ) < $totalChars ) {
			return new WP_Error(
				'mlp_daily_limit_reached',
				__( 'Дневной лимит символов на перевод исчерпан. Измените лимит в настройках или попробуйте завтра.', 'wp-mlp' ),
				array( 'status' => 429 )
			);
		}

		$hasHtmlBlock = false;

		foreach ( $byHash as $row ) {
			if ( Segment::KIND_HTML_BLOCK === (string) $row['kind'] ) {
				$hasHtmlBlock = true;

				break;
			}
		}

		$context = new TranslationContext(
			$hasHtmlBlock ? Segment::KIND_HTML_BLOCK : Segment::KIND_TEXT,
			'post',
			(int) $request->get_param( 'post_id' ),
			null,
			array(),
			$language->label
		);

		$result = $this->provider->translateBatch( $items, $sourceLocale, $language->locale, $context );

		if ( array() === $result ) {
			$detail = $this->provider instanceof OpenAiProvider ? $this->provider->lastError() : null;

			return new WP_Error(
				'mlp_translate_failed',
				null !== $detail
					/* translators: %s: причина, которую вернул провайдер */
					? sprintf( __( 'Не удалось получить перевод от OpenAI: %s', 'wp-mlp' ), $detail )
					: __( 'Не удалось получить перевод от OpenAI.', 'wp-mlp' ),
				array( 'status' => 502 )
			);
		}

		$translations = array();
		$rejected     = array();
		$sentChars    = 0;

		foreach ( $result as $hash => $text ) {
			$sourceText = $items[ $hash ] ?? null;

			if ( null === $sourceText ) {
				continue;
			}

			$kind = (string) ( $byHash[ $hash ]['kind'] ?? Segment::KIND_TEXT );
			$text = Segment::KIND_HTML_BLOCK === $kind ? BlockSanitizer::sanitize( $text ) : $text;

			if ( ShortcodeGuard::containsShortcode( $sourceText ) && ! ShortcodeGuard::isPreserved( $sourceText, $text ) ) {
				$rejected[] = array(
					'uniq_hash' => $hash,
					'reason'    => 'shortcode_mismatch',
				);

				continue;
			}

			$translations[ $hash ] = $text;
			$sentChars             += mb_strlen( $sourceText ) + mb_strlen( $text );
		}

		$this->usage->record( $sentChars );

		return new WP_REST_Response(
			array(
				'translations' => $translations,
				'rejected'     => $rejected,
			)
		);
	}

	/**
	 * Шаг 3: сохраняет весь присланный список одной транзакцией.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function commit( WP_REST_Request $request ) {
		$context = $this->resolveContext( $request );

		if ( $context instanceof WP_Error ) {
			return $context;
		}

		list( $post, $language ) = $context;

		$rows = is_array( $request->get_param( 'segments' ) ) ? $request->get_param( 'segments' ) : array();

		if ( array() === $rows || count( $rows ) > self::MAX_IDS_PER_REQUEST ) {
			return new WP_Error(
				'mlp_invalid_segments',
				__( 'Список сегментов пуст или слишком велик.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$hashes = array();

		foreach ( $rows as $row ) {
			$hash = is_array( $row ) ? (string) ( $row['uniq_hash'] ?? '' ) : '';

			if ( Hash::isValid( $hash ) ) {
				$hashes[] = $hash;
			}
		}

		$ids  = $this->sources->idsByHashes( $hashes );
		$meta = $this->sources->findMany( array_values( $ids ) );

		$byHash = array();

		foreach ( $meta as $id => $row ) {
			$byHash[ (string) $row['uniq_hash'] ] = array( 'id' => $id, 'kind' => (string) $row['kind'], 'source_hash' => $row['source_hash'] );
		}

		// Каждый id обязан быть строкой, реально найденной в ЭТОЙ записи —
		// иначе запросом можно было бы переписать чужой перевод где угодно
		// на сайте, просто зная его uniq_hash.
		foreach ( $hashes as $hash ) {
			$row = $byHash[ $hash ] ?? null;

			if ( null === $row || ! $this->occurrences->belongsToObject( (int) $row['id'], (int) $post->ID ) ) {
				return new WP_Error(
					'mlp_foreign_segment',
					__( 'Один из сегментов не принадлежит этой записи. Обновите список и попробуйте снова.', 'wp-mlp' ),
					array( 'status' => 400 )
				);
			}
		}

		global $wpdb;

		$saved  = 0;
		$failed = false;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- своя таблица, атомарность нескольких save() требует ручной транзакции.
		$wpdb->query( 'START TRANSACTION' );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$hash    = (string) ( $row['uniq_hash'] ?? '' );
			$rowMeta = $byHash[ $hash ] ?? null;

			if ( null === $rowMeta ) {
				continue;
			}

			$text = trim( (string) ( $row['translated_text'] ?? '' ) );
			$text = Segment::KIND_HTML_BLOCK === $rowMeta['kind'] ? BlockSanitizer::sanitize( $text ) : wp_strip_all_tags( $text );

			$status = (string) ( $row['status'] ?? '' );

			if ( ! TranslationStatus::isValid( $status ) ) {
				$status = '' !== $text ? TranslationStatus::APPROVED : TranslationStatus::MISSING;
			}

			$ok = $this->translations->save(
				(int) $rowMeta['id'],
				$language->locale,
				$text,
				$status,
				get_current_user_id(),
				$this->sourceRevision( $rowMeta )
			);

			if ( ! $ok ) {
				$failed = true;

				break;
			}

			++$saved;
		}

		$wpdb->query( $failed ? 'ROLLBACK' : 'COMMIT' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( $failed ) {
			return new WP_Error(
				'mlp_commit_failed',
				__( 'Не удалось сохранить перевод — изменения отменены, ничего не потеряно.', 'wp-mlp' ),
				array( 'status' => 500 )
			);
		}

		$this->snapshot->save( (int) $post->ID, $language->locale, array_keys( $byHash ) );
		$this->cache->flush();

		return new WP_REST_Response(
			array(
				'saved'   => $saved,
				'locale'  => $language->locale,
				'post_id' => (int) $post->ID,
			)
		);
	}

	/**
	 * Hex исходного хеша строки — SourceRepository::findMany() уже отдаёт
	 * его в hex (см. её докблок), в отличие от find(), после которого
	 * TranslationsController::sourceRevision() ещё сам делает bin2hex().
	 *
	 * @param array{source_hash: mixed} $meta Строка sources, урезанная до id/kind/source_hash.
	 */
	private function sourceRevision( array $meta ): ?string {
		$hash = $meta['source_hash'] ?? null;

		return is_string( $hash ) && Hash::isValid( $hash ) ? $hash : null;
	}

	/**
	 * Заводит недостающие строки и их места использования.
	 *
	 * @param array<string, PostSegment> $unique       Уникальные сегменты, ключ — uniq_hash.
	 * @param string                     $sourceLocale Исходный язык.
	 * @param int                        $postId       Идентификатор записи.
	 */
	private function registerDiscovered( array $unique, string $sourceLocale, int $postId ): void {
		$rows = array();

		foreach ( $unique as $hash => $postSegment ) {
			$rows[] = array(
				'locale'       => $sourceLocale,
				'kind'         => $postSegment->segment->kind,
				'text'         => $postSegment->segment->text,
				'source_hash'  => $postSegment->segment->sourceHash,
				'context_hash' => Hash::of( '' ),
				'uniq_hash'    => $hash,
			);
		}

		$this->sources->insertMissing( $rows );

		$ids = $this->sources->idsByHashes( array_keys( $unique ) );

		$occurrenceRows = array();

		foreach ( $unique as $hash => $postSegment ) {
			if ( ! isset( $ids[ $hash ] ) ) {
				continue;
			}

			$occurrenceRows[] = array(
				'source_id'      => $ids[ $hash ],
				'object_type'    => 'post',
				'object_id'      => $postId,
				'url_hash'       => null,
				'attribute_name' => $postSegment->segment->attribute,
				'uniq_hash'      => Hash::ofParts( array( $ids[ $hash ], 'post', '', (string) $postSegment->segment->attribute ) ),
			);
		}

		$this->occurrences->insertMany( $occurrenceRows );
	}

	/**
	 * Достаёт и проверяет запись и язык, общие для всех трёх шагов.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_Error|array{0: WP_Post, 1: \WpMlp\Settings\Language}
	 */
	private function resolveContext( WP_REST_Request $request ) {
		$postId = (int) $request->get_param( 'post_id' );
		$post   = get_post( $postId );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'mlp_post_not_found',
				__( 'Запись не найдена.', 'wp-mlp' ),
				array( 'status' => 404 )
			);
		}

		$locale   = (string) $request->get_param( 'locale' );
		$language = $this->settings->get( $locale );

		if ( null === $language || $language->isDefault ) {
			return new WP_Error(
				'mlp_invalid_locale',
				__( 'Такого дополнительного языка нет в настройках.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		return array( $post, $language );
	}

	/**
	 * Приводит параметр `hashes` к списку валидных hex-хешей. Чистая функция.
	 *
	 * @param mixed $raw Сырое значение параметра запроса.
	 * @return list<string>
	 */
	private function sanitizeHashList( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$hashes = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $value ): string => (string) $value, $raw ),
					array( Hash::class, 'isValid' )
				)
			)
		);

		return array_slice( $hashes, 0, self::MAX_IDS_PER_REQUEST );
	}
}
