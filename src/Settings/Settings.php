<?php
/**
 * Чтение и запись настроек плагина.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Settings;

use WpMlp\Support\Locale;

/**
 * Единственная точка доступа к списку языков.
 *
 * Все настройки лежат в одной опции `mlp_settings` (ТЗ 4.1/11): это один
 * автозагружаемый ключ вместо десятка и один атомарный update при сохранении.
 */
final class Settings {

	public const OPTION = 'mlp_settings';

	/**
	 * Адрес API по умолчанию, если владелец сайта не указал свой.
	 */
	public const DEFAULT_OPENAI_BASE_URL = 'https://api.openai.com/v1';

	/**
	 * Разобранные языки, ключ — код языка.
	 *
	 * @var array<string, Language>|null
	 */
	private ?array $languages = null;

	/**
	 * Сырые настройки.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $raw = null;

	/**
	 * Значения по умолчанию: русский как исходный язык, английский на /en/.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'default_locale'           => 'ru',
			'languages'                => array(
				'ru' => array(
					'locale'    => 'ru',
					'slug'      => 'ru',
					'label'     => 'Русский',
					'wp_locale' => 'ru_RU',
					'status'    => Language::STATUS_PUBLISHED,
				),
				'en' => array(
					'locale'    => 'en',
					'slug'      => 'en',
					'label'     => 'English',
					'wp_locale' => 'en_US',
					'status'    => Language::STATUS_PUBLISHED,
				),
			),
			'discover_strings'         => true,
			'delete_data_on_uninstall' => false,
			'openai_daily_char_limit'  => 100000,
			'hide_untranslated_posts'  => true,
			'openai_api_key'           => '',
			'openai_model'             => '',
			'openai_base_url'          => self::DEFAULT_OPENAI_BASE_URL,
			'sitemap_excluded_slugs'   => array(),
		);
	}

	/**
	 * Сырые настройки с подставленными значениями по умолчанию.
	 *
	 * @return array<string, mixed>
	 */
	public function raw(): array {
		if ( null === $this->raw ) {
			$stored    = get_option( self::OPTION, array() );
			$this->raw = is_array( $stored ) ? array_merge( self::defaults(), $stored ) : self::defaults();
		}

		return $this->raw;
	}

	/**
	 * Все языки сайта. Язык по умолчанию всегда первый.
	 *
	 * @return array<string, Language>
	 */
	public function all(): array {
		if ( null !== $this->languages ) {
			return $this->languages;
		}

		$raw           = $this->raw();
		$defaultLocale = Locale::normalize( (string) $raw['default_locale'] );
		$rawLanguages  = is_array( $raw['languages'] ?? null ) ? $raw['languages'] : array();

		$languages = array();

		foreach ( $rawLanguages as $locale => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$data['locale'] = $data['locale'] ?? $locale;
			$normalized     = Locale::normalize( (string) $data['locale'] );

			if ( ! Locale::isValid( $normalized ) ) {
				continue;
			}

			$languages[ $normalized ] = Language::fromArray( $data, $normalized === $defaultLocale );
		}

		// Язык по умолчанию обязан существовать, даже если настройки повреждены.
		if ( ! isset( $languages[ $defaultLocale ] ) && Locale::isValid( $defaultLocale ) ) {
			$languages[ $defaultLocale ] = Language::fromArray(
				array(
					'locale' => $defaultLocale,
					'slug'   => $defaultLocale,
					'label'  => $defaultLocale,
				),
				true
			);
		}

		// Сначала язык по умолчанию — от этого зависит порядок в переключателе.
		uasort(
			$languages,
			static fn( Language $a, Language $b ): int => ( $b->isDefault <=> $a->isDefault )
				?: strcmp( $a->locale, $b->locale )
		);

		$this->languages = $languages;

		return $this->languages;
	}

	/**
	 * Только опубликованные языки: у них есть URL и hreflang.
	 *
	 * @return array<string, Language>
	 */
	public function published(): array {
		return array_filter( $this->all(), static fn( Language $language ): bool => $language->isPublished() );
	}

	/**
	 * Дополнительные языки (все, кроме языка по умолчанию).
	 *
	 * @return array<string, Language>
	 */
	public function secondary(): array {
		return array_filter( $this->all(), static fn( Language $language ): bool => ! $language->isDefault );
	}

	/**
	 * Язык по умолчанию.
	 */
	public function defaultLanguage(): Language {
		foreach ( $this->all() as $language ) {
			if ( $language->isDefault ) {
				return $language;
			}
		}

		// Недостижимо: all() гарантирует наличие языка по умолчанию.
		return Language::fromArray( array( 'locale' => 'en' ), true );
	}

	/**
	 * Язык по коду.
	 *
	 * @param string $locale Код языка.
	 */
	public function get( string $locale ): ?Language {
		return $this->all()[ Locale::normalize( $locale ) ] ?? null;
	}

	/**
	 * Опубликованный язык по URL-слагу. Язык по умолчанию не ищется:
	 * он отдаётся без префикса.
	 *
	 * @param string $slug Первый сегмент пути.
	 */
	public function publishedBySlug( string $slug ): ?Language {
		$slug = Locale::normalizeSlug( $slug );

		foreach ( $this->published() as $language ) {
			if ( ! $language->isDefault && $language->slug === $slug ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Любой язык по URL-слагу, включая черновики и язык по умолчанию.
	 *
	 * Нужен маршрутизатору, чтобы отличить черновой язык (404) от чужого
	 * сегмента пути (обычная страница).
	 *
	 * @param string $slug Первый сегмент пути.
	 */
	public function anyBySlug( string $slug ): ?Language {
		$slug = Locale::normalizeSlug( $slug );

		foreach ( $this->all() as $language ) {
			if ( $language->slug === $slug ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Разрешено ли записывать в БД новые строки, найденные на фронтенде (ТЗ 4.6).
	 */
	public function isDiscoveryEnabled(): bool {
		return (bool) ( $this->raw()['discover_strings'] ?? true );
	}

	/**
	 * Удалять ли таблицы и опции при удалении плагина (критерий приёмки 16).
	 */
	public function shouldDeleteDataOnUninstall(): bool {
		return (bool) ( $this->raw()['delete_data_on_uninstall'] ?? false );
	}

	/**
	 * Прятать ли непереведённые записи из списков на дополнительных языках.
	 */
	public function hidesUntranslatedPosts(): bool {
		return (bool) ( $this->raw()['hide_untranslated_posts'] ?? true );
	}

	/**
	 * Дневной лимит символов на машинный перевод (ТЗ 9.3).
	 *
	 * Защищает от неожиданного счёта от OpenAI: повторные клики по «Перевести
	 * с ИИ» или ошибка в коде не смогут потратить больше, чем разрешено.
	 */
	public function openAiDailyCharLimit(): int {
		return max( 0, (int) ( $this->raw()['openai_daily_char_limit'] ?? 100000 ) );
	}

	/**
	 * Ключ OpenAI, сохранённый в настройках плагина.
	 *
	 * Хранится в БД по явному запросу владельца сайта, а не в `.env` — так
	 * ключ вводится один раз через админку, без доступа к файлам хостинга.
	 * Плагин по-прежнему никогда не выводит его ни в HTML, ни в REST-ответ,
	 * ни в лог (ТЗ 13) — только последние 4 символа для подтверждения в форме.
	 */
	public function openAiApiKey(): string {
		return (string) ( $this->raw()['openai_api_key'] ?? '' );
	}

	/**
	 * Идентификатор модели OpenAI.
	 */
	public function openAiModel(): string {
		return (string) ( $this->raw()['openai_model'] ?? '' );
	}

	/**
	 * Адрес API OpenAI или совместимого шлюза.
	 */
	public function openAiBaseUrl(): string {
		$url = trim( (string) ( $this->raw()['openai_base_url'] ?? '' ) );

		return '' !== $url ? $url : self::DEFAULT_OPENAI_BASE_URL;
	}

	/**
	 * Слаги страниц/записей, исключённых из карты сайта вручную.
	 *
	 * Только для того, что нельзя распознать программно: обычная страница,
	 * созданная руками (не через WooCommerce и не через другой известный
	 * плагин с собственным API), исключается только по явному указанию
	 * владельца сайта — угадывать по названию или адресу небезопасно.
	 * Исключается и сама страница, и всё, что вложено под ней (см.
	 * Sitemap::withoutServicePages()).
	 *
	 * @return list<string>
	 */
	public function sitemapExcludedSlugs(): array {
		$raw = $this->raw()['sitemap_excluded_slugs'] ?? array();

		return is_array( $raw ) ? array_values( array_filter( array_map( 'strval', $raw ) ) ) : array();
	}

	/**
	 * Сохраняет уже очищенные настройки.
	 *
	 * @param array<string, mixed> $settings Результат sanitize().
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION, $settings );
		$this->reset();
	}

	/**
	 * Сбрасывает мемоизацию после записи.
	 */
	public function reset(): void {
		$this->raw       = null;
		$this->languages = null;
	}

	/**
	 * Приводит данные формы к безопасному виду.
	 *
	 * Возвращает готовые к сохранению настройки и список ошибок: некорректные
	 * языки отбрасываются, а не сохраняются «как есть».
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 * @return array{settings: array<string, mixed>, errors: list<string>}
	 */
	public function sanitize( array $input ): array {
		$errors    = array();
		$languages = array();
		$slugs     = array();

		$rawLanguages = is_array( $input['languages'] ?? null ) ? $input['languages'] : array();

		foreach ( $rawLanguages as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( ! empty( $row['delete'] ) ) {
				continue;
			}

			$locale = Locale::normalize( (string) ( $row['locale'] ?? '' ) );

			if ( '' === $locale ) {
				continue;
			}

			if ( ! Locale::isValid( $locale ) ) {
				/* translators: %s: language code entered by the user */
				$errors[] = sprintf( __( 'Код языка «%s» недопустим: разрешены 2–8 латинских букв и субтеги через дефис.', 'wp-mlp' ), $locale );
				continue;
			}

			$slug = Locale::normalizeSlug( (string) ( $row['slug'] ?? '' ) );
			$slug = '' !== $slug ? $slug : $locale;

			if ( ! Locale::isValidSlug( $slug ) ) {
				/* translators: %s: URL slug entered by the user */
				$errors[] = sprintf( __( 'URL-слаг «%s» недопустим: разрешены строчные латинские буквы, цифры и дефис.', 'wp-mlp' ), $slug );
				continue;
			}

			if ( isset( $slugs[ $slug ] ) ) {
				/* translators: %s: duplicated URL slug */
				$errors[] = sprintf( __( 'URL-слаг «%s» уже занят другим языком.', 'wp-mlp' ), $slug );
				continue;
			}

			$slugs[ $slug ] = true;

			/*
			 * Пустое поле — это «выведи сама», а не ошибка: у языков,
			 * заведённых до появления поля, значения ещё нет, и требовать
			 * его заполнения вручную значило бы ломать сохранение настроек
			 * до того, как владелец сайта вообще узнает про новое поле.
			 * Разбор пустого значения берёт на себя Language::fromArray().
			 */
			$wpLocale = Locale::sanitizeWpLocale( (string) ( $row['wp_locale'] ?? '' ) );

			if ( '' !== $wpLocale && ! Locale::isValidWpLocale( $wpLocale ) ) {
				/* translators: %s: WordPress locale entered by the user */
				$errors[] = sprintf( __( 'Локаль WordPress «%s» недопустима: разрешены латинские буквы, цифры и подчёркивание, например en_US.', 'wp-mlp' ), $wpLocale );
				$wpLocale = '';
			}

			$languages[ $locale ] = array(
				'locale'    => $locale,
				'slug'      => $slug,
				'label'     => sanitize_text_field( (string) ( $row['label'] ?? $locale ) ),
				'flag'      => Language::sanitizeFlag( (string) ( $row['flag'] ?? '' ) ),
				'wp_locale' => $wpLocale,
				'status'    => ( $row['status'] ?? '' ) === Language::STATUS_DRAFT
					? Language::STATUS_DRAFT
					: Language::STATUS_PUBLISHED,
			);
		}

		$defaultLocale = Locale::normalize( (string) ( $input['default_locale'] ?? '' ) );

		if ( ! isset( $languages[ $defaultLocale ] ) ) {
			$errors[]      = __( 'Язык по умолчанию должен быть в списке языков. Настройки языка по умолчанию не изменены.', 'wp-mlp' );
			$defaultLocale = $this->defaultLanguage()->locale;

			if ( ! isset( $languages[ $defaultLocale ] ) ) {
				$languages[ $defaultLocale ] = $this->defaultLanguage()->toArray();
			}
		}

		// Язык по умолчанию нельзя оставить черновиком: он отдаётся без префикса.
		$languages[ $defaultLocale ]['status'] = Language::STATUS_PUBLISHED;

		$dailyLimit = isset( $input['openai_daily_char_limit'] ) ? (int) $input['openai_daily_char_limit'] : $this->openAiDailyCharLimit();

		return array(
			'settings' => array(
				'default_locale'           => $defaultLocale,
				'languages'                => $languages,
				'discover_strings'         => ! empty( $input['discover_strings'] ),
				'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
				'hide_untranslated_posts'  => ! empty( $input['hide_untranslated_posts'] ),
				'openai_daily_char_limit'  => max( 0, min( 10000000, $dailyLimit ) ),
				'openai_api_key'           => $this->sanitizeApiKey( $input ),
				'openai_model'             => sanitize_text_field( (string) ( $input['openai_model'] ?? $this->openAiModel() ) ),
				'openai_base_url'          => $this->sanitizeBaseUrl( $input ),
				'sitemap_excluded_slugs'   => $this->sanitizeSitemapExcludedSlugs( $input ),
			),
			'errors'   => $errors,
		);
	}

	/**
	 * Разбирает список слагов из textarea формы — по одному на строку.
	 *
	 * Только нижний регистр и обрезка пробелов/слешей по краям — без
	 * `sanitize_title()`: он транслитерирует кириллицу не всегда так же, как
	 * это когда-то сделал WordPress при создании страницы, и слаг перестал
	 * бы совпадать с `post_name`. Ожидается, что владелец сайта скопирует
	 * слаг из адресной строки (последний сегмент пути), а не впишет
	 * заголовок страницы.
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 * @return list<string>
	 */
	private function sanitizeSitemapExcludedSlugs( array $input ): array {
		$raw   = (string) ( $input['sitemap_excluded_slugs'] ?? '' );
		$lines = preg_split( '/[\r\n]+/', $raw );

		$slugs = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$slug = strtolower( trim( (string) $line, " \t\n\r\0\x0B/" ) );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Решает новое значение ключа OpenAI по данным формы.
	 *
	 * Поле ключа в форме — `type="password"` и никогда не приходит заполненным
	 * значением текущего ключа (мы его туда не выводим). Поэтому пустое поле
	 * означает «оставить как есть», а не «стереть», иначе ключ слетал бы при
	 * каждом сохранении любых других настроек. Стереть можно только явной
	 * галочкой.
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 */
	private function sanitizeApiKey( array $input ): string {
		if ( ! empty( $input['openai_api_key_clear'] ) ) {
			return '';
		}

		$submitted = isset( $input['openai_api_key'] ) ? trim( (string) $input['openai_api_key'] ) : '';

		return '' !== $submitted ? $submitted : $this->openAiApiKey();
	}

	/**
	 * Адрес API из формы, с проверкой на правдоподобность.
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 */
	private function sanitizeBaseUrl( array $input ): string {
		$url = isset( $input['openai_base_url'] ) ? trim( (string) $input['openai_base_url'] ) : '';

		if ( '' === $url ) {
			return self::DEFAULT_OPENAI_BASE_URL;
		}

		$sanitized = esc_url_raw( $url );

		return '' !== $sanitized ? untrailingslashit( $sanitized ) : self::DEFAULT_OPENAI_BASE_URL;
	}
}
