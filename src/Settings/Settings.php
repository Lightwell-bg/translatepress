<?php
/**
 * Чтение и запись настроек плагина.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Settings;

use LingoCanvas\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Единственная точка доступа к списку языков.
 *
 * Все настройки лежат в одной опции `lingocanvas_settings` (ТЗ 4.1/11): это один
 * автозагружаемый ключ вместо десятка и один атомарный update при сохранении.
 */
final class Settings {

	/**
	 * Имя опции с настройками.
	 */
	public static function optionName(): string {
		return 'lingocanvas_settings';
	}

	/**
	 * Режим показа записей на дополнительных языках: показывать все.
	 */
	public const POSTS_ALL = 'all';

	/**
	 * Режим показа записей на дополнительных языках: только включённые владельцем вручную.
	 */
	public const POSTS_MANUAL = 'manual';

	/**
	 * Адрес API по умолчанию, если владелец сайта не указал свой.
	 */
	public const DEFAULT_OPENAI_BASE_URL = 'https://api.openai.com/v1';

	/**
	 * Провайдер перевода: OpenAI-совместимый API со своим ключом.
	 */
	public const PROVIDER_OPENAI = 'openai';

	/**
	 * Провайдер перевода: LingoCanvas Cloud (CenterAI Cloud).
	 */
	public const PROVIDER_CLOUD = 'cloud';

	/**
	 * Адрес API LingoCanvas Cloud по умолчанию.
	 */
	public const DEFAULT_CLOUD_API_URL = 'https://cloud.centerai.eu';

	/**
	 * Сколько слагов можно исключить из карты сайта вручную.
	 */
	public const SITEMAP_SLUGS_MAX = 200;

	/**
	 * Максимальная длина слага (колонка `post_name` — varchar(200)).
	 */
	public const SITEMAP_SLUG_MAX_LENGTH = 200;

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
			'switcher_display'         => SwitcherDisplay::LABEL,
			'discover_strings'         => true,
			'discover_from_visitors'   => false,
			'delete_data_on_uninstall' => false,
			'openai_daily_char_limit'  => 100000,
			'untranslated_posts_mode'  => self::POSTS_MANUAL,
			'openai_api_key'           => '',
			'openai_model'             => '',
			'openai_base_url'          => '',
			'openai_base_url_explicit' => false,
			'sitemap_excluded_slugs'   => array(),
			'provider'                 => self::PROVIDER_OPENAI,
			'cloud_token'              => '',
			'cloud_api_url'            => self::DEFAULT_CLOUD_API_URL,
			'cloud_consent_at'         => 0,
		);
	}

	/**
	 * Сырые настройки с подставленными значениями по умолчанию.
	 *
	 * @return array<string, mixed>
	 */
	public function raw(): array {
		if ( null === $this->raw ) {
			$stored    = get_option( self::optionName(), array() );
			$this->raw = is_array( $stored ) ? array_merge( self::defaults(), $stored ) : self::defaults();

			/*
			 * Миграция со старой галочки `hide_untranslated_posts`. Сайт, у
			 * которого настройки уже сохранены, но режима ещё нет, получает
			 * «показывать все» — независимо от старой галочки. Режим «только
			 * включённые вручную» по умолчанию для такого сайта мгновенно
			 * опустошил бы /en/: ни одна запись ещё не включена. Владелец
			 * переключает режим сам, когда отметит нужные записи. Свежая
			 * установка (настроек в базе ещё нет, либо в них уже записан
			 * режим) получает значение по умолчанию — «manual».
			 */
			if ( is_array( $stored ) && array() !== $stored && ! array_key_exists( 'untranslated_posts_mode', $stored ) ) {
				$this->raw['untranslated_posts_mode'] = self::POSTS_ALL;
			}
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

		/*
		 * Порядок НЕ трогаем: он и есть порядок языков в переключателе, и
		 * задаёт его владелец сайта стрелками в таблице настроек. Раньше
		 * здесь стояла жёсткая сортировка — язык по умолчанию первым, дальше
		 * по алфавиту кода, — из-за которой поменять порядок было нельзя
		 * вовсе. Позиция языка по умолчанию тоже больше ничего не значит:
		 * defaultLanguage() ищет его по собственному признаку, а не по месту
		 * в списке.
		 */
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
	 * Что переключатель языков показывает посетителю.
	 *
	 * Пустое или незнакомое значение означает `label` — вид, который был у
	 * переключателя до появления этой настройки. Так сайт, где её ни разу
	 * не сохраняли, выглядит после обновления плагина ровно как прежде.
	 */
	public function switcherDisplay(): string {
		return SwitcherDisplay::sanitize( (string) ( $this->raw()['switcher_display'] ?? '' ) );
	}

	/**
	 * Разрешено ли записывать в БД новые строки, найденные на фронтенде (ТЗ 4.6).
	 */
	public function isDiscoveryEnabled(): bool {
		return (bool) ( $this->raw()['discover_strings'] ?? true );
	}

	/**
	 * Собирать ли строки со страниц, которые открывают посетители, а не владелец.
	 *
	 * По умолчанию нет: иначе список наполнится текстом всех страниц, которые
	 * кто-то открыл, даже если переводить их никто не собирался.
	 */
	public function isDiscoveryFromVisitorsEnabled(): bool {
		return (bool) ( $this->raw()['discover_from_visitors'] ?? false );
	}

	/**
	 * Нужно ли запоминать строки для текущего пользователя.
	 *
	 * Вызывать только когда пользователь уже определён (после `init`):
	 * до этого `current_user_can()` даёт неверный ответ.
	 */
	public function shouldDiscoverForCurrentUser(): bool {
		if ( ! $this->isDiscoveryEnabled() ) {
			return false;
		}

		/*
		 * Поисковые запросы, 404, фиды и предпросмотр порождают произвольный
		 * текст (строка поиска, мусорный адрес), которого нет в контенте сайта:
		 * по ним посетитель мог бы наполнять таблицу строк чем угодно.
		 */
		foreach ( array( 'is_search', 'is_404', 'is_feed', 'is_preview' ) as $conditional ) {
			if ( function_exists( $conditional ) && $conditional() ) {
				return false;
			}
		}

		return $this->isDiscoveryFromVisitorsEnabled() || current_user_can( 'manage_options' );
	}

	/**
	 * Удалять ли таблицы и опции при удалении плагина (критерий приёмки 16).
	 */
	public function shouldDeleteDataOnUninstall(): bool {
		return (bool) ( $this->raw()['delete_data_on_uninstall'] ?? false );
	}

	/**
	 * Режим показа записей на дополнительных языках: `all` или `manual`.
	 *
	 * Незнакомое значение (повреждённые настройки) считается `all`: лучше
	 * показать лишнее, чем молча опустошить язык.
	 */
	public function untranslatedPostsMode(): string {
		return self::sanitizePostsMode( (string) ( $this->raw()['untranslated_posts_mode'] ?? self::POSTS_ALL ), self::POSTS_ALL );
	}

	/**
	 * Включён ли ручной режим: на дополнительном языке видны только отмеченные записи.
	 */
	public function isManualPostVisibility(): bool {
		return self::POSTS_MANUAL === $this->untranslatedPostsMode();
	}

	/**
	 * Приводит значение режима к допустимому. Чистая функция.
	 *
	 * @param string $value    Значение из формы или базы.
	 * @param string $fallback Что вернуть для незнакомого значения.
	 */
	public static function sanitizePostsMode( string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );

		return in_array( $value, array( self::POSTS_ALL, self::POSTS_MANUAL ), true ) ? $value : $fallback;
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
	 * Адрес API, который владелец сайта задал сам, или пустая строка, если не задан.
	 *
	 * Пустое поле в форме сохраняется как '' — «не задан», тогда действует адрес
	 * из окружения или значение по умолчанию. Непустое значение (в том числе
	 * ровно api.openai.com/v1) — явное и приоритетнее окружения. Совместимость:
	 * до появления флага `openai_base_url_explicit` в базе лежало значение по
	 * умолчанию у всех сайтов; без флага оно по-прежнему считается «не задан».
	 */
	public function openAiBaseUrlExplicit(): string {
		$raw = $this->raw();
		$url = trim( (string) ( $raw['openai_base_url'] ?? '' ) );

		if ( '' === $url ) {
			return '';
		}

		if ( self::DEFAULT_OPENAI_BASE_URL === $url && true !== ( $raw['openai_base_url_explicit'] ?? false ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Выбранный провайдер перевода: `openai` (по умолчанию) или `cloud`.
	 */
	public function provider(): string {
		return self::sanitizeProvider( (string) ( $this->raw()['provider'] ?? '' ), self::PROVIDER_OPENAI );
	}

	/**
	 * Приводит имя провайдера к допустимому. Чистая функция.
	 *
	 * @param string $value    Значение из формы или базы.
	 * @param string $fallback Что вернуть для незнакомого значения.
	 */
	public static function sanitizeProvider( string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );

		return in_array( $value, array( self::PROVIDER_OPENAI, self::PROVIDER_CLOUD ), true ) ? $value : $fallback;
	}

	/**
	 * Токен сайта LingoCanvas Cloud, сохранённый в настройках.
	 *
	 * Секрет: никогда не выводится ни в HTML, ни в JS, ни в REST-ответ, ни в лог.
	 */
	public function cloudToken(): string {
		return trim( (string) ( $this->raw()['cloud_token'] ?? '' ) );
	}

	/**
	 * Адрес API LingoCanvas Cloud из настроек (допустимый или адрес по умолчанию).
	 */
	public function cloudApiUrl(): string {
		$url = self::sanitizeCloudApiUrl( (string) ( $this->raw()['cloud_api_url'] ?? '' ) );

		return '' !== $url ? $url : self::DEFAULT_CLOUD_API_URL;
	}

	/**
	 * Момент явного согласия владельца на отправку текстов в Cloud (0 — нет согласия).
	 */
	public function cloudConsentAt(): int {
		return max( 0, (int) ( $this->raw()['cloud_consent_at'] ?? 0 ) );
	}

	/**
	 * Проверяет адрес API Cloud: https, либо http только для локальной разработки.
	 *
	 * @param string $url Адрес.
	 * @return string Адрес без хвостового слеша или пустая строка, если он недопустим.
	 */
	public static function sanitizeCloudApiUrl( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$url   = esc_url_raw( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );

		if ( 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) ) {
			return untrailingslashit( $url );
		}

		return '';
	}

	/**
	 * Меняет часть настроек Cloud, не затрагивая остальные ключи.
	 *
	 * @param array<string, mixed> $changes Ключи: provider, cloud_token, cloud_api_url, cloud_consent_at.
	 */
	public function saveCloud( array $changes ): void {
		$allowed = array_intersect_key( $changes, array_flip( array( 'provider', 'cloud_token', 'cloud_api_url', 'cloud_consent_at' ) ) );

		$this->save( array_merge( $this->raw(), $allowed ) );
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
		update_option( self::optionName(), $settings );
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
				$errors[] = sprintf( /* translators: %s: language code entered by the user */ __( 'The language code "%s" is not valid: 2–8 Latin letters and hyphen-separated subtags are allowed.', 'lingocanvas' ), $locale );
				continue;
			}

			$slug = Locale::normalizeSlug( (string) ( $row['slug'] ?? '' ) );
			$slug = '' !== $slug ? $slug : $locale;

			if ( ! Locale::isValidSlug( $slug ) ) {
				/* translators: %s: URL slug entered by the user */
				$errors[] = sprintf( /* translators: %s: URL slug entered by the user */ __( 'The URL slug "%s" is not valid: lowercase Latin letters, digits and hyphens are allowed.', 'lingocanvas' ), $slug );
				continue;
			}

			if ( isset( $slugs[ $slug ] ) ) {
				/* translators: %s: duplicated URL slug */
				$errors[] = sprintf( /* translators: %s: URL slug entered by the user */ __( 'The URL slug "%s" is already used by another language.', 'lingocanvas' ), $slug );
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
				$errors[] = sprintf( /* translators: %s: WordPress locale entered by the user */ __( 'The WordPress locale "%s" is not valid: Latin letters, digits and underscores are allowed, for example en_US.', 'lingocanvas' ), $wpLocale );
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
			$errors[]      = __( 'The default language must be in the list of languages. The default language settings were not changed.', 'lingocanvas' );
			$defaultLocale = $this->defaultLanguage()->locale;

			if ( ! isset( $languages[ $defaultLocale ] ) ) {
				$languages[ $defaultLocale ] = $this->defaultLanguage()->toArray();
			}
		}

		// Язык по умолчанию нельзя оставить черновиком: он отдаётся без префикса.
		$languages[ $defaultLocale ]['status'] = Language::STATUS_PUBLISHED;

		$dailyLimit = isset( $input['openai_daily_char_limit'] ) ? (int) $input['openai_daily_char_limit'] : $this->openAiDailyCharLimit();
		$baseUrl    = $this->sanitizeBaseUrl( $input );

		return array(
			'settings' => array(
				'default_locale'           => $defaultLocale,
				'languages'                => $languages,
				'switcher_display'         => SwitcherDisplay::sanitize( (string) ( $input['switcher_display'] ?? '' ) ),
				'discover_strings'         => ! empty( $input['discover_strings'] ),
				'discover_from_visitors'   => ! empty( $input['discover_from_visitors'] ),
				'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
				// Нет в форме (старая форма с галочкой) — режим не меняется.
				'untranslated_posts_mode'  => self::sanitizePostsMode( (string) ( $input['untranslated_posts_mode'] ?? '' ), $this->untranslatedPostsMode() ),
				'openai_daily_char_limit'  => max( 0, min( 10000000, $dailyLimit ) ),
				'openai_api_key'           => $this->sanitizeApiKey( $input ),
				'openai_model'             => sanitize_text_field( (string) ( $input['openai_model'] ?? $this->openAiModel() ) ),
				'openai_base_url'          => $baseUrl,
				// Непустое значение из формы — явный выбор владельца, он бьёт окружение.
				'openai_base_url_explicit' => '' !== $baseUrl,
				'sitemap_excluded_slugs'   => $this->sanitizeSitemapExcludedSlugs( $input ),
				'provider'                 => self::sanitizeProvider( (string) ( $input['provider'] ?? '' ), $this->provider() ),
				'cloud_token'              => $this->sanitizeCloudToken( $input ),
				'cloud_api_url'            => $this->sanitizeCloudApiUrlInput( $input ),
				'cloud_consent_at'         => $this->sanitizeCloudConsent( $input ),
			),
			'errors'   => $errors,
		);
	}

	/**
	 * Разбирает список слагов из textarea формы — по одному на строку.
	 *
	 * Слаг сравнивается с `post_name` (Sitemap::manuallyExcludedIds), а ядро
	 * получает `post_name` тем же `sanitize_title()`, поэтому и здесь каждая
	 * строка проходит через него: латиница и цифры остаются как есть, прочие
	 * символы кодируются так же, как в базе. Ожидается, что владелец сайта
	 * скопирует слаг из адресной строки (последний сегмент пути), а не впишет
	 * заголовок страницы. Пустые строки и повторы отбрасываются, число записей
	 * и длина слага ограничены (колонка `post_name` — varchar(200)).
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 * @return list<string>
	 */
	private function sanitizeSitemapExcludedSlugs( array $input ): array {
		$raw   = (string) ( $input['sitemap_excluded_slugs'] ?? '' );
		$lines = preg_split( '/[\r\n]+/', $raw );

		$slugs = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$slug = sanitize_title( trim( (string) $line, " \t\n\r\0\x0B/" ) );

			// Слаг длиннее колонки post_name не совпадёт ни с одной записью.
			if ( '' === $slug || strlen( $slug ) > self::SITEMAP_SLUG_MAX_LENGTH ) {
				continue;
			}

			$slugs[ $slug ] = $slug;

			if ( count( $slugs ) >= self::SITEMAP_SLUGS_MAX ) {
				break;
			}
		}

		return array_values( $slugs );
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
	 * Токен Cloud из формы: пустое поле — «оставить как есть», стереть можно галочкой.
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 */
	private function sanitizeCloudToken( array $input ): string {
		if ( ! empty( $input['cloud_token_clear'] ) ) {
			return '';
		}

		$submitted = isset( $input['cloud_token'] ) ? trim( (string) $input['cloud_token'] ) : '';

		if ( '' !== $submitted && 1 === preg_match( '/^[A-Za-z0-9_\-]{8,200}$/D', $submitted ) ) {
			return $submitted;
		}

		return $this->cloudToken();
	}

	/**
	 * Адрес API Cloud из формы: пустой или недопустимый — адрес по умолчанию (поле не в форме — без изменений).
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 */
	private function sanitizeCloudApiUrlInput( array $input ): string {
		if ( ! array_key_exists( 'cloud_api_url', $input ) ) {
			return $this->cloudApiUrl();
		}

		$url = self::sanitizeCloudApiUrl( (string) $input['cloud_api_url'] );

		return '' !== $url ? $url : self::DEFAULT_CLOUD_API_URL;
	}

	/**
	 * Согласие на отправку текстов в Cloud: галочка ставит время один раз, снятие обнуляет.
	 *
	 * Поле `cloud_consent` нет в форме — согласие не меняется.
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 */
	private function sanitizeCloudConsent( array $input ): int {
		$current = $this->cloudConsentAt();

		if ( ! array_key_exists( 'cloud_consent', $input ) ) {
			return $current;
		}

		if ( empty( $input['cloud_consent'] ) ) {
			return 0;
		}

		return $current > 0 ? $current : time();
	}

	/**
	 * Адрес API из формы, с проверкой на правдоподобность.
	 *
	 * @param array<string, mixed> $input Сырые данные из $_POST.
	 */
	private function sanitizeBaseUrl( array $input ): string {
		$url = isset( $input['openai_base_url'] ) ? trim( (string) $input['openai_base_url'] ) : '';

		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );

		return '' !== $sanitized ? untrailingslashit( $sanitized ) : '';
	}
}
