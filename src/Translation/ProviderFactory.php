<?php
/**
 * Сборка провайдера перевода из настроек.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Translation;

use LingoCanvas\Settings\Settings;
use LingoCanvas\Support\Env;

/**
 * Единственное место, где решается, откуда берутся доступы к OpenAI.
 *
 * Раньше эта логика жила прямо в фабрике контейнера, и админка отдельно
 * гадала о готовности провайдера по `supports()`. Из-за этого экран мог
 * написать «ключ не настроен», когда на самом деле не хватало модели.
 * Теперь и провайдер, и подсказки в интерфейсе смотрят на один источник.
 */
final class ProviderFactory {

	/** Поле «ключ» — не заполнено. */
	public const FIELD_KEY = 'key';

	/** Поле «модель» — не заполнено. */
	public const FIELD_MODEL = 'model';

	/** Источник ключа: константа в wp-config.php. */
	public const SOURCE_CONSTANT = 'constant';

	/** Источник ключа: настройки в базе данных. */
	public const SOURCE_DATABASE = 'database';

	/** Источник ключа: защищённый файл wp-content/lingocanvas.env.php. */
	public const SOURCE_CONTENT_ENV = 'content_env';

	/** Источник ключа: файл lingocanvas.env выше корня WordPress. */
	public const SOURCE_PARENT_ENV = 'parent_env';

	/** Источник ключа: файл .env в папке плагина (удаляется при замене плагина). */
	public const SOURCE_PLUGIN_ENV = 'plugin_env';

	/** Источник ключа: переменная окружения сервера. */
	public const SOURCE_PROCESS_ENV = 'process_env';

	/** Ключ нигде не задан. */
	public const SOURCE_NONE = 'none';

	/** Константа wp-config.php с ключом. */
	public const CONST_API_KEY = 'LINGOCANVAS_OPENAI_API_KEY';

	/** Константа wp-config.php с моделью. */
	public const CONST_MODEL = 'LINGOCANVAS_OPENAI_MODEL';

	/** Константа wp-config.php с адресом API. */
	public const CONST_BASE_URL = 'LINGOCANVAS_OPENAI_BASE_URL';

	/** Поле «токен Cloud» — сайт не подключён к LingoCanvas Cloud. */
	public const FIELD_CLOUD_TOKEN = 'cloud_token';

	/** Поле «согласие» — владелец не подтвердил отправку текстов в Cloud. */
	public const FIELD_CLOUD_CONSENT = 'cloud_consent';

	/** Константа wp-config.php с токеном Cloud. */
	public const CONST_CLOUD_TOKEN = 'LINGOCANVAS_CLOUD_TOKEN';

	/** Константа wp-config.php с адресом API Cloud. */
	public const CONST_CLOUD_API_URL = 'LINGOCANVAS_CLOUD_API_URL';

	/**
	 * Поиск констант: имя => непустая строка или null.
	 *
	 * @var callable(string): ?string
	 */
	private $constantLookup;

	/**
	 * Путь к защищённому файлу окружения в wp-content (или null без WP_CONTENT_DIR).
	 */
	public static function contentEnvPath(): ?string {
		return defined( 'WP_CONTENT_DIR' ) ? rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/lingocanvas.env.php' : null;
	}

	/**
	 * Путь к файлу окружения выше корня WordPress (или null без ABSPATH).
	 */
	public static function parentEnvPath(): ?string {
		return defined( 'ABSPATH' ) ? dirname( rtrim( (string) ABSPATH, '/\\' ) ) . '/lingocanvas.env' : null;
	}

	/**
	 * Приводит путь к сравнимому виду: прямые слеши, без хвостового.
	 *
	 * @param string $path Путь.
	 */
	private static function normalizePath( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	/**
	 * Пути env-файлов, по которым определяется источник ключа.
	 *
	 * @var array{content: ?string, parent: ?string}
	 */
	private array $envPaths;

	/**
	 * @param Settings                     $settings       Настройки плагина.
	 * @param (callable(string): ?string)|null $constantLookup Подмена поиска констант
	 *                                                     (для тестов: константу PHP нельзя «разопределить»).
	 * @param array{content?: ?string, parent?: ?string}|null $envPaths Подмена путей env-файлов (для тестов).
	 */
	public function __construct( private readonly Settings $settings, ?callable $constantLookup = null, ?array $envPaths = null ) {
		$this->envPaths = array(
			'content' => $envPaths['content'] ?? ( null === $envPaths ? self::contentEnvPath() : null ),
			'parent'  => $envPaths['parent'] ?? ( null === $envPaths ? self::parentEnvPath() : null ),
		);

		$this->constantLookup = $constantLookup ?? static function ( string $name ): ?string {
			if ( ! defined( $name ) ) {
				return null;
			}

			$value = constant( $name );

			return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
		};
	}

	/**
	 * Ключ: константа wp-config.php, затем настройки в БД, затем окружение.
	 *
	 * Откат рассматривается для каждого поля отдельно. Если делать
	 * его «всё или ничего», то у владельца сайта, который перенёс в базу
	 * только ключ, молча потерялась бы модель из файла.
	 */
	public function apiKey(): string {
		return $this->resolve( self::CONST_API_KEY, $this->settings->openAiApiKey(), 'OPENAI_API_KEY' );
	}

	/**
	 * Идентификатор модели: константа, затем настройки в БД, затем окружение.
	 */
	public function model(): string {
		return $this->resolve( self::CONST_MODEL, $this->settings->openAiModel(), 'OPENAI_MODEL' );
	}

	/**
	 * Адрес API: константа, затем настройки в БД, затем окружение, затем значение по умолчанию.
	 */
	public function baseUrl(): string {
		$constant = $this->constant( self::CONST_BASE_URL );

		if ( null !== $constant ) {
			return $constant;
		}

		// Пустое поле — «не задан»; явно сохранённый адрес (даже стандартный)
		// приоритетнее шлюза из окружения.
		$stored = trim( $this->settings->openAiBaseUrlExplicit() );

		if ( '' !== $stored ) {
			return $stored;
		}

		$fromEnv = trim( Env::get( 'OPENAI_BASE_URL' ) );

		return '' !== $fromEnv ? $fromEnv : Settings::DEFAULT_OPENAI_BASE_URL;
	}

	/**
	 * Задано ли поле константой wp-config.php (тогда поле в админке не работает).
	 *
	 * @param string $constName Одна из CONST_*.
	 */
	public function isOverriddenByConstant( string $constName ): bool {
		return null !== $this->constant( $constName );
	}

	/**
	 * Откуда взят ключ: одна из констант SOURCE_*. Сам ключ не возвращается.
	 */
	public function apiKeySource(): string {
		if ( null !== $this->constant( self::CONST_API_KEY ) ) {
			return self::SOURCE_CONSTANT;
		}

		if ( '' !== trim( $this->settings->openAiApiKey() ) ) {
			return self::SOURCE_DATABASE;
		}

		$source = Env::sourceOf( 'OPENAI_API_KEY' );

		if ( null === $source ) {
			return self::SOURCE_NONE;
		}

		if ( 'process' === $source ) {
			return self::SOURCE_PROCESS_ENV;
		}

		// Сравниваем с точными путями загруженных файлов, а не по имени:
		// произвольный файл с тем же именем не должен считаться «защищённым».
		$source = self::normalizePath( $source );

		foreach ( array(
			'content' => self::SOURCE_CONTENT_ENV,
			'parent'  => self::SOURCE_PARENT_ENV,
		) as $slot => $constName ) {
			$path = $this->envPaths[ $slot ];

			if ( null !== $path && self::normalizePath( $path ) === $source ) {
				return $constName;
			}
		}

		return self::SOURCE_PLUGIN_ENV;
	}

	/**
	 * Каких полей не хватает для перевода с ИИ.
	 *
	 * @return list<string> Пустой массив, если всё настроено.
	 */
	public function missing(): array {
		$missing = array();

		if ( $this->isCloudSelected() ) {
			if ( '' === $this->cloudToken() ) {
				$missing[] = self::FIELD_CLOUD_TOKEN;
			}

			if ( $this->settings->cloudConsentAt() <= 0 ) {
				$missing[] = self::FIELD_CLOUD_CONSENT;
			}

			return $missing;
		}

		if ( '' === $this->apiKey() ) {
			$missing[] = self::FIELD_KEY;
		}

		if ( '' === $this->model() ) {
			$missing[] = self::FIELD_MODEL;
		}

		return $missing;
	}

	/**
	 * Текст ошибки «перевод с ИИ не настроен» с названием ровно тех полей, которых не хватает.
	 *
	 * @param list<string> $missing Результат missing().
	 */
	public static function unavailableMessage( array $missing ): string {
		if ( in_array( self::FIELD_CLOUD_TOKEN, $missing, true ) ) {
			return __( 'LingoCanvas Cloud is not connected: connect this site in the plugin settings.', 'lingocanvas' );
		}

		if ( in_array( self::FIELD_CLOUD_CONSENT, $missing, true ) ) {
			return __( 'LingoCanvas Cloud is not enabled: confirm sending texts to the service in the plugin settings.', 'lingocanvas' );
		}

		$noKey   =in_array( self::FIELD_KEY, $missing, true );
		$noModel = in_array( self::FIELD_MODEL, $missing, true );

		if ( $noKey && $noModel ) {
			$what = __( 'enter the key and the model in the plugin settings', 'lingocanvas' );
		} elseif ( $noModel ) {
			$what = __( 'enter the model in the plugin settings', 'lingocanvas' );
		} else {
			$what = __( 'enter the key in the plugin settings', 'lingocanvas' );
		}

		return sprintf(
			/* translators: %s: what to configure, e.g. "enter the key in the plugin settings" */
			__( 'AI translation is not set up: %s.', 'lingocanvas' ),
			$what
		);
	}

	/**
	 * Готов ли перевод с ИИ к работе.
	 */
	public function isReady(): bool {
		return array() === $this->missing();
	}

	/**
	 * Создаёт провайдера: настоящий при полной настройке, иначе заглушку.
	 */
	public function create(): ProviderInterface {
		if ( $this->isCloudSelected() ) {
			$provider = $this->isReady()
				? new CenterAiCloudProvider( $this->cloudClient() )
				: new ManualProvider();
		} else {
			$provider = $this->isReady()
				? new OpenAiProvider( $this->apiKey(), $this->model(), rtrim( $this->baseUrl(), '/' ) )
				: new ManualProvider();
		}

		/**
		 * Позволяет подменить провайдера перевода своим.
		 *
		 * @since 0.3.13
		 *
		 * @param ProviderInterface $provider Провайдер, выбранный плагином.
		 */
		return apply_filters( 'lingocanvas_translation_provider', $provider );
	}

	/**
	 * Выбран ли в настройках провайдер LingoCanvas Cloud.
	 */
	public function isCloudSelected(): bool {
		return Settings::PROVIDER_CLOUD === $this->settings->provider();
	}

	/**
	 * Токен Cloud: константа wp-config.php, затем настройки в БД. Наружу (HTML/JS) не выводится.
	 */
	public function cloudToken(): string {
		return $this->constant( self::CONST_CLOUD_TOKEN ) ?? $this->settings->cloudToken();
	}

	/**
	 * Адрес API Cloud: допустимая константа wp-config.php, затем настройки, затем значение по умолчанию.
	 */
	public function cloudApiUrl(): string {
		$constant = $this->constant( self::CONST_CLOUD_API_URL );

		if ( null !== $constant ) {
			$valid = Settings::sanitizeCloudApiUrl( $constant );

			if ( '' !== $valid ) {
				return $valid;
			}
		}

		return $this->settings->cloudApiUrl();
	}

	/**
	 * Подключён ли сайт к Cloud и дано ли согласие (токен есть и согласие > 0).
	 */
	public function isCloudConnected(): bool {
		return '' !== $this->cloudToken() && $this->settings->cloudConsentAt() > 0;
	}

	/**
	 * Клиент API Cloud с текущим адресом и токеном.
	 */
	public function cloudClient(): CloudClient {
		return new CloudClient( $this->cloudApiUrl(), $this->cloudToken(), $this->settings );
	}

	/**
	 * Значение константы wp-config.php или null, если она не задана или пуста.
	 *
	 * @param string $name Имя константы.
	 */
	private function constant( string $name ): ?string {
		$value = ( $this->constantLookup )( $name );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * Значение из константы, настроек или, если там пусто, из окружения.
	 *
	 * @param string $constName Имя константы wp-config.php.
	 * @param string $stored    Значение из БД.
	 * @param string $envName   Имя переменной окружения.
	 */
	private function resolve( string $constName, string $stored, string $envName ): string {
		$constant = $this->constant( $constName );

		if ( null !== $constant ) {
			return $constant;
		}

		$stored = trim( $stored );

		return '' !== $stored ? $stored : trim( Env::get( $envName ) );
	}
}
