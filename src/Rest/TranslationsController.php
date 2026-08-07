<?php
/**
 * REST-эндпоинты переводов.
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
use WpMlp\Storage\TranslationRepository;
use WpMlp\Storage\TranslationStatus;
use WpMlp\Storage\UsageTracker;
use WpMlp\Support\Hash;
use WpMlp\Support\Hookable;
use WpMlp\Support\Locale;
use WpMlp\Translation\OpenAiProvider;
use WpMlp\Translation\ProviderInterface;
use WpMlp\Translation\TranslationContext;

/**
 * `PUT /wp-json/mlp/v1/translations/{source_id}/{locale}` (ТЗ 10.3).
 *
 * Все проверки из раздела 13 ТЗ выполняются до записи: capability, REST-nonce
 * (его проверяет ядро при cookie-авторизации), допустимость языка по списку
 * из настроек и статуса по allowlist.
 */
final class TranslationsController implements Hookable {

	public const NAMESPACE  = 'mlp/v1';
	public const CAPABILITY = 'manage_options';

	/**
	 * @param SourceRepository      $sources      Исходные строки.
	 * @param TranslationRepository $translations Переводы.
	 * @param TranslationCache      $cache        Кэш переводов.
	 * @param Settings              $settings     Настройки плагина.
	 * @param ProviderInterface     $provider     Провайдер машинного перевода.
	 * @param UsageTracker          $usage        Дневной бюджет символов.
	 */
	public function __construct(
		private readonly SourceRepository $sources,
		private readonly TranslationRepository $translations,
		private readonly TranslationCache $cache,
		private readonly Settings $settings,
		private readonly ProviderInterface $provider,
		private readonly UsageTracker $usage
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
			'/sources/(?P<source_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'source' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => array(
					'source_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		$target = array(
			'source_id' => array(
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'locale'    => array(
				'required'          => true,
				'sanitize_callback' => static fn( $value ): string => Locale::normalize( (string) $value ),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<source_id>\d+)/(?P<locale>[A-Za-z0-9-]{2,20})',
			array(
				array(
					'methods'             => array( 'PUT', 'PATCH' ),
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $target + array(
						'translated_text' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => static fn( $value ): string => (string) wp_unslash( $value ),
						),
						'status'          => array(
							'required' => false,
							'type'     => 'string',
							'enum'     => TranslationStatus::all(),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $target,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<source_id>\d+)/(?P<locale>[A-Za-z0-9-]{2,20})/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $target,
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
	 * Отдаёт исходную строку со всеми её переводами (ТЗ 10.3).
	 *
	 * Нужен визуальному редактору: по клику на элементе он знает только
	 * source_id и подтягивает остальное отсюда.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function source( WP_REST_Request $request ) {
		$sourceId = (int) $request->get_param( 'source_id' );
		$source   = $this->sources->find( $sourceId );

		if ( null === $source ) {
			return new WP_Error(
				'mlp_source_not_found',
				__( 'Исходная строка не найдена.', 'wp-mlp' ),
				array( 'status' => 404 )
			);
		}

		$translations = array();

		foreach ( $this->settings->secondary() as $language ) {
			$row = $this->translations->find( $sourceId, $language->locale );

			$translations[ $language->locale ] = array(
				'label'           => $language->label,
				'translated_text' => (string) ( $row['translated_text'] ?? '' ),
				'status'          => (string) ( $row['status'] ?? TranslationStatus::MISSING ),
			);
		}

		return new WP_REST_Response(
			array(
				'id'           => $sourceId,
				'kind'         => (string) $source['kind'],
				'source_text'  => (string) $source['source_text'],
				'translations' => $translations,
			)
		);
	}

	/**
	 * Удаляет перевод строки на выбранный язык.
	 *
	 * Исходная строка остаётся: она по-прежнему есть на сайте и должна
	 * оставаться в списке для перевода.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$sourceId = (int) $request->get_param( 'source_id' );
		$locale   = (string) $request->get_param( 'locale' );
		$language = $this->settings->get( $locale );

		if ( null === $language || $language->isDefault ) {
			return new WP_Error(
				'mlp_invalid_locale',
				__( 'Такого дополнительного языка нет в настройках.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$this->translations->delete( $sourceId, $language->locale );
		$this->cache->flush();

		return new WP_REST_Response(
			array(
				'source_id'       => $sourceId,
				'locale'          => $language->locale,
				'translated_text' => '',
				'status'          => TranslationStatus::MISSING,
				'status_label'    => TranslationStatus::label( TranslationStatus::MISSING ),
			)
		);
	}

	/**
	 * Просит провайдера перевести строку и возвращает подсказку.
	 *
	 * Ничего не сохраняет: пользователь сам решает, редактировать ли текст
	 * и нажимать ли «Сохранить» — плагин ничего не переводит автоматически.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function suggest( WP_REST_Request $request ) {
		$sourceId = (int) $request->get_param( 'source_id' );
		$locale   = (string) $request->get_param( 'locale' );
		$language = $this->settings->get( $locale );

		if ( null === $language || $language->isDefault ) {
			return new WP_Error(
				'mlp_invalid_locale',
				__( 'Такого дополнительного языка нет в настройках.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$sourceLocale = $this->settings->defaultLanguage()->locale;

		if ( ! $this->provider->supports( $sourceLocale, $language->locale ) ) {
			return new WP_Error(
				'mlp_provider_unavailable',
				__( 'Перевод с ИИ не настроен: заполните OPENAI_API_KEY в .env.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$source = $this->sources->find( $sourceId );

		if ( null === $source ) {
			return new WP_Error(
				'mlp_source_not_found',
				__( 'Исходная строка не найдена.', 'wp-mlp' ),
				array( 'status' => 404 )
			);
		}

		$sourceText = (string) $source['source_text'];
		$dailyLimit = $this->settings->openAiDailyCharLimit();

		if ( $dailyLimit > 0 && $this->usage->remainingToday( $dailyLimit ) < mb_strlen( $sourceText ) ) {
			return new WP_Error(
				'mlp_daily_limit_reached',
				__( 'Дневной лимит символов на перевод исчерпан. Измените лимит в настройках или попробуйте завтра.', 'wp-mlp' ),
				array( 'status' => 429 )
			);
		}

		$hash    = $this->sourceRevision( $source ) ?? Hash::of( $sourceText );
		$context = new TranslationContext(
			(string) $source['kind'],
			null,
			null,
			null,
			array(),
			$language->label
		);

		$result = $this->provider->translateBatch( array( $hash => $sourceText ), $sourceLocale, $language->locale, $context );
		$text   = $result[ $hash ] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$detail = $this->provider instanceof OpenAiProvider ? $this->provider->lastError() : null;

			return new WP_Error(
				'mlp_suggest_failed',
				null !== $detail
					/* translators: %s: причина, которую вернул провайдер */
					? sprintf( __( 'Не удалось получить перевод от OpenAI: %s', 'wp-mlp' ), $detail )
					: __( 'Не удалось получить перевод от OpenAI.', 'wp-mlp' ),
				array( 'status' => 502 )
			);
		}

		if ( Segment::KIND_HTML_BLOCK === (string) $source['kind'] ) {
			$text = BlockSanitizer::sanitize( $text );
		}

		$this->usage->record( mb_strlen( $sourceText ) + mb_strlen( $text ) );

		return new WP_REST_Response(
			array(
				'source_id'      => $sourceId,
				'locale'         => $language->locale,
				'suggested_text' => $text,
				'status'         => TranslationStatus::MACHINE,
			)
		);
	}

	/**
	 * Сохраняет перевод строки.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$sourceId = (int) $request->get_param( 'source_id' );
		$locale   = (string) $request->get_param( 'locale' );

		$language = $this->settings->get( $locale );

		if ( null === $language || $language->isDefault ) {
			return new WP_Error(
				'mlp_invalid_locale',
				__( 'Такого дополнительного языка нет в настройках.', 'wp-mlp' ),
				array( 'status' => 400 )
			);
		}

		$source = $this->sources->find( $sourceId );

		if ( null === $source ) {
			return new WP_Error(
				'mlp_source_not_found',
				__( 'Исходная строка не найдена.', 'wp-mlp' ),
				array( 'status' => 404 )
			);
		}

		$text = trim( (string) $request->get_param( 'translated_text' ) );

		/*
		 * Для translation block перевод — это разметка, а не обычный текст:
		 * без неё исчезли бы <b>/<a> внутри абзаца, ради которых блок и
		 * заводился. Узкий allowlist (BlockSanitizer) вместо wp_strip_all_tags.
		 * Для остальных видов строк теги вырезаются целиком, как в Этапе 1.
		 */
		$text = Segment::KIND_HTML_BLOCK === (string) $source['kind']
			? BlockSanitizer::sanitize( $text )
			: wp_strip_all_tags( $text );

		$status = (string) ( $request->get_param( 'status' ) ?? '' );

		if ( ! TranslationStatus::isValid( $status ) ) {
			// Правка человеком по умолчанию считается готовой.
			$status = '' !== $text ? TranslationStatus::APPROVED : TranslationStatus::MISSING;
		}

		$saved = $this->translations->save(
			$sourceId,
			$language->locale,
			$text,
			$status,
			get_current_user_id(),
			$this->sourceRevision( $source )
		);

		if ( ! $saved ) {
			return new WP_Error(
				'mlp_save_failed',
				__( 'Не удалось сохранить перевод.', 'wp-mlp' ),
				array( 'status' => 500 )
			);
		}

		// Страницы могли закэшировать старое значение.
		$this->cache->flush();

		return new WP_REST_Response(
			array(
				'source_id'       => $sourceId,
				'locale'          => $language->locale,
				'translated_text' => $text,
				'status'          => $status,
				'status_label'    => TranslationStatus::label( $status ),
			)
		);
	}

	/**
	 * Hex исходного хеша строки — чтобы позже увидеть, что оригинал изменился.
	 *
	 * @param array<string, mixed> $source Строка таблицы sources.
	 */
	private function sourceRevision( array $source ): ?string {
		$hash = $source['source_hash'] ?? null;

		if ( ! is_string( $hash ) || '' === $hash ) {
			return null;
		}

		// $wpdb отдаёт binary(32) сырыми байтами.
		return 32 === strlen( $hash ) ? bin2hex( $hash ) : null;
	}
}
