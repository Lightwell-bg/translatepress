<?php
/**
 * Сборка провайдера перевода из настроек.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

use WpMlp\Settings\Settings;
use WpMlp\Support\Env;

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

	/**
	 * @param Settings $settings Настройки плагина.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Ключ: сначала настройки в БД, затем `.env`.
	 *
	 * Откат к `.env` рассматривается для каждого поля отдельно. Если делать
	 * его «всё или ничего», то у владельца сайта, который перенёс в базу
	 * только ключ, молча потерялась бы модель из файла.
	 */
	public function apiKey(): string {
		return $this->resolve( $this->settings->openAiApiKey(), 'OPENAI_API_KEY' );
	}

	/**
	 * Идентификатор модели: сначала настройки в БД, затем `.env`.
	 */
	public function model(): string {
		return $this->resolve( $this->settings->openAiModel(), 'OPENAI_MODEL' );
	}

	/**
	 * Адрес API: сначала настройки в БД, затем `.env`, затем значение по умолчанию.
	 */
	public function baseUrl(): string {
		$stored = $this->settings->openAiBaseUrl();

		// Значение по умолчанию в настройках не считается «заполненным»:
		// иначе оно перекрыло бы собственный адрес шлюза из .env.
		if ( Settings::DEFAULT_OPENAI_BASE_URL !== $stored ) {
			return $stored;
		}

		return Env::get( 'OPENAI_BASE_URL', Settings::DEFAULT_OPENAI_BASE_URL );
	}

	/**
	 * Каких полей не хватает для перевода с ИИ.
	 *
	 * @return list<string> Пустой массив, если всё настроено.
	 */
	public function missing(): array {
		$missing = array();

		if ( '' === $this->apiKey() ) {
			$missing[] = self::FIELD_KEY;
		}

		if ( '' === $this->model() ) {
			$missing[] = self::FIELD_MODEL;
		}

		return $missing;
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
		$provider = $this->isReady()
			? new OpenAiProvider( $this->apiKey(), $this->model(), rtrim( $this->baseUrl(), '/' ) )
			: new ManualProvider();

		/**
		 * Позволяет подменить провайдера перевода своим.
		 *
		 * @param ProviderInterface $provider Провайдер, выбранный плагином.
		 */
		return apply_filters( 'mlp_translation_provider', $provider );
	}

	/**
	 * Значение из настроек или, если там пусто, из окружения.
	 *
	 * @param string $stored  Значение из БД.
	 * @param string $envName Имя переменной окружения.
	 */
	private function resolve( string $stored, string $envName ): string {
		$stored = trim( $stored );

		return '' !== $stored ? $stored : Env::get( $envName );
	}
}
