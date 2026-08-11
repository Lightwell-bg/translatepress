<?php
/**
 * Валидация и нормализация языковых кодов и URL-слагов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Единственное место, где решается, что считать допустимым кодом языка.
 *
 * Коды языка попадают в имена ключей кэша, в SQL и в URL, поэтому они
 * проверяются по allowlist-регулярке (раздел 13 ТЗ), а не экранированием.
 */
final class Locale {

	/**
	 * Максимальная длина кода: столько же, сколько в колонках БД (varchar(20)).
	 */
	public const MAX_LENGTH = 20;

	/**
	 * Максимальная длина URL-слага языка.
	 */
	public const MAX_SLUG_LENGTH = 20;

	/**
	 * Приводит код к каноническому виду: нижний регистр, `_` → `-`.
	 *
	 * @param string $locale Исходный код, например ` pt_BR `.
	 */
	public static function normalize( string $locale ): string {
		return strtolower( str_replace( '_', '-', trim( $locale ) ) );
	}

	/**
	 * Допустим ли код языка.
	 *
	 * Формат: 2–8 латинских букв, далее сколько угодно субтегов из 2–8
	 * букв/цифр через дефис. Примеры: `ru`, `en`, `pt-br`, `zh-hans-cn`.
	 *
	 * @param string $locale Нормализованный или сырой код.
	 */
	public static function isValid( string $locale ): bool {
		$locale = self::normalize( $locale );

		if ( '' === $locale || strlen( $locale ) > self::MAX_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z]{2,8}(-[a-z0-9]{2,8})*$/', $locale );
	}

	/**
	 * Допустим ли URL-слаг языка.
	 *
	 * Слаг попадает в rewrite-правила и в путь, поэтому разрешены только
	 * строчные буквы, цифры и дефис между ними.
	 *
	 * @param string $slug Слаг из настроек.
	 */
	public static function isValidSlug( string $slug ): bool {
		$slug = strtolower( trim( $slug ) );

		if ( '' === $slug || strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Приводит слаг к каноническому виду.
	 *
	 * @param string $slug Сырой слаг.
	 */
	public static function normalizeSlug( string $slug ): string {
		return strtolower( trim( $slug ) );
	}

	/**
	 * Регион по умолчанию для языков, где он не совпадает с удвоенным кодом
	 * языка. Facebook требует полный вид `xx_XX` (документированный список
	 * допустимых значений og:locale) — большинство однобуквенных совпадений
	 * («de» → «DE», «ru» → «RU») угадываются простым удвоением, но не все:
	 * английский, китайский, японский и ещё несколько языков называют регион
	 * иначе, чем сам язык.
	 *
	 * @var array<string, string>
	 */
	private const OG_LOCALE_REGIONS = array(
		'en' => 'US',
		'zh' => 'CN',
		'ja' => 'JP',
		'ko' => 'KR',
		'sv' => 'SE',
		'da' => 'DK',
		'nb' => 'NO',
		'no' => 'NO',
		'el' => 'GR',
		'he' => 'IL',
		'hi' => 'IN',
		'vi' => 'VN',
		'cs' => 'CZ',
		'uk' => 'UA',
	);

	/**
	 * Код в форме `xx_XX` для og:locale (Open Graph требует полный, а не
	 * двухбуквенный код).
	 *
	 * Если в исходном коде уже есть регион (`pt-br` → `pt_BR`), используется
	 * он. Иначе — таблица известных исключений выше, а для всего, что в неё
	 * не попало, — язык, удвоенный как регион (`ru` → `ru_RU`, `de` → `de_DE`):
	 * для большинства языков Евросоюза это и есть настоящий код страны.
	 *
	 * @param string $locale Нормализованный или сырой код.
	 */
	public static function toOgLocale( string $locale ): string {
		$parts    = explode( '-', self::normalize( $locale ) );
		$language = $parts[0];

		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			return $language . '_' . strtoupper( $parts[1] );
		}

		$region = self::OG_LOCALE_REGIONS[ $language ] ?? strtoupper( $language );

		return $language . '_' . $region;
	}

	/**
	 * Максимальная длина локали WordPress: `de_DE_formal` — 12 символов,
	 * запас взят с большим избытком, ограничение защищает от мусора в поле.
	 */
	public const MAX_WP_LOCALE_LENGTH = 35;

	/**
	 * Предполагаемая локаль WordPress по короткому коду языка.
	 *
	 * ТОЛЬКО предзаполнение поля в админке, не источник истины: настоящая
	 * локаль хранится в настройках языка и правится вручную. Угадать её
	 * нельзя в принципе — `en` это и `en_US`, и `en_GB`, а у части языков
	 * (каталанский `ca`, баскский `eu`) локаль WordPress вообще без региона,
	 * и удвоение кода дало бы несуществующий `ca_CA`.
	 *
	 * Таблица регионов общая с {@see toOgLocale()} — сегодня оба формата
	 * совпадают (`xx_XX`), но это разные величины: og:locale обязан быть из
	 * документированного списка Facebook, а локаль WordPress — из списка
	 * переводов WordPress.org. Метод отдельный именно поэтому: когда списки
	 * разойдутся, чинить нужно будет ровно один из них.
	 *
	 * @param string $locale Короткий код языка из настроек.
	 */
	public static function toWpLocale( string $locale ): string {
		$parts    = explode( '-', self::normalize( $locale ) );
		$language = $parts[0];

		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			return $language . '_' . strtoupper( $parts[1] );
		}

		$region = self::OG_LOCALE_REGIONS[ $language ] ?? strtoupper( $language );

		return $language . '_' . $region;
	}

	/**
	 * Приводит локаль WordPress к безопасному виду.
	 *
	 * Значение попадает в имена файлов переводов (`wp-content/languages/
	 * {locale}.mo`) и в вызовы ядра, поэтому чистится тем же способом, что
	 * и в самом WordPress (`sanitize_locale_name()`): всё, кроме латиницы,
	 * цифр и подчёркивания, вырезается — так в путь не проберётся ни `..`,
	 * ни слеш.
	 *
	 * @param string $locale Сырое значение поля.
	 */
	public static function sanitizeWpLocale( string $locale ): string {
		$locale = (string) preg_replace( '/[^A-Za-z0-9_]/', '', trim( $locale ) );

		return substr( $locale, 0, self::MAX_WP_LOCALE_LENGTH );
	}

	/**
	 * Похоже ли значение на локаль WordPress.
	 *
	 * Проверка намеренно широкая: список локалей WordPress живёт на
	 * translate.wordpress.org и пополняется, зашивать его в плагин нельзя.
	 * Требуется только то, что делает значение безопасным и осмысленным —
	 * начинается с буквы, состоит из латиницы, цифр и подчёркиваний.
	 *
	 * @param string $locale Значение после sanitizeWpLocale().
	 */
	public static function isValidWpLocale( string $locale ): bool {
		if ( strlen( $locale ) < 2 || strlen( $locale ) > self::MAX_WP_LOCALE_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', $locale );
	}

	/**
	 * Код в форме BCP-47 для атрибутов `lang` и `hreflang`.
	 *
	 * `pt-br` → `pt-BR`, `zh-hans-cn` → `zh-Hans-CN`. Регистр не влияет на
	 * трактовку поисковиками, но так значение выглядит канонично.
	 *
	 * @param string $locale Нормализованный код.
	 */
	public static function toBcp47( string $locale ): string {
		$parts = explode( '-', self::normalize( $locale ) );

		foreach ( $parts as $index => $part ) {
			if ( 0 === $index ) {
				continue;
			}

			if ( 4 === strlen( $part ) ) {
				// Субтег письменности: Hans, Cyrl.
				$parts[ $index ] = ucfirst( $part );
			} elseif ( strlen( $part ) <= 3 ) {
				// Субтег региона: BR, 419.
				$parts[ $index ] = strtoupper( $part );
			}
		}

		return implode( '-', $parts );
	}
}
