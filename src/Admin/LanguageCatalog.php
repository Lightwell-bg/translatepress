<?php
/**
 * Каталог языков для выбора в таблице «Языки сайта».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Admin;

/**
 * Список языков, которые можно добавить на сайт одним выбором.
 *
 * Источник — `wp_get_available_translations()`: WordPress сам кеширует ответ
 * api.wordpress.org. Без сети (или при ошибке API) берётся короткий
 * встроенный список самых ходовых локалей; вписать любую другую локаль
 * вручную можно всегда, поэтому список — удобство, а не ограничение.
 *
 * `en_US` в ответе API нет (это встроенная локаль ядра), поэтому он
 * добавляется отсюда всегда.
 */
final class LanguageCatalog {

	/**
	 * Встроенный запасной список: локаль => [родное название, английское, ISO 639-1].
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	public const FALLBACK = array(
		'en_US' => array( 'English (US)', 'English (United States)', 'en' ),
		'en_GB' => array( 'English (UK)', 'English (UK)', 'en' ),
		'ru_RU' => array( 'Русский', 'Russian', 'ru' ),
		'uk'    => array( 'Українська', 'Ukrainian', 'uk' ),
		'bg_BG' => array( 'Български', 'Bulgarian', 'bg' ),
		'de_DE' => array( 'Deutsch', 'German', 'de' ),
		'fr_FR' => array( 'Français', 'French', 'fr' ),
		'es_ES' => array( 'Español', 'Spanish (Spain)', 'es' ),
		'it_IT' => array( 'Italiano', 'Italian', 'it' ),
		'pt_PT' => array( 'Português', 'Portuguese (Portugal)', 'pt' ),
		'pt_BR' => array( 'Português do Brasil', 'Portuguese (Brazil)', 'pt' ),
		'nl_NL' => array( 'Nederlands', 'Dutch', 'nl' ),
		'pl_PL' => array( 'Polski', 'Polish', 'pl' ),
		'cs_CZ' => array( 'Čeština', 'Czech', 'cs' ),
		'sk_SK' => array( 'Slovenčina', 'Slovak', 'sk' ),
		'ro_RO' => array( 'Română', 'Romanian', 'ro' ),
		'el'    => array( 'Ελληνικά', 'Greek', 'el' ),
		'tr_TR' => array( 'Türkçe', 'Turkish', 'tr' ),
		'sv_SE' => array( 'Svenska', 'Swedish', 'sv' ),
		'da_DK' => array( 'Dansk', 'Danish', 'da' ),
		'nb_NO' => array( 'Norsk bokmål', 'Norwegian (Bokmål)', 'nb' ),
		'fi'    => array( 'Suomi', 'Finnish', 'fi' ),
		'hu_HU' => array( 'Magyar', 'Hungarian', 'hu' ),
		'sr_RS' => array( 'Српски језик', 'Serbian', 'sr' ),
		'hr'    => array( 'Hrvatski', 'Croatian', 'hr' ),
		'lt_LT' => array( 'Lietuvių kalba', 'Lithuanian', 'lt' ),
		'lv'    => array( 'Latviešu valoda', 'Latvian', 'lv' ),
		'et'    => array( 'Eesti', 'Estonian', 'et' ),
		'he_IL' => array( 'עברית', 'Hebrew', 'he' ),
		'ar'    => array( 'العربية', 'Arabic', 'ar' ),
		'hi_IN' => array( 'हिन्दी', 'Hindi', 'hi' ),
		'ja'    => array( '日本語', 'Japanese', 'ja' ),
		'ko_KR' => array( '한국어', 'Korean', 'ko' ),
		'zh_CN' => array( '简体中文', 'Chinese (China)', 'zh' ),
		'zh_TW' => array( '繁體中文', 'Chinese (Taiwan)', 'zh' ),
	);

	/**
	 * Язык без региона => страна для флага.
	 *
	 * @var array<string, string>
	 */
	public const LANGUAGE_COUNTRY = array(
		'ja' => 'JP',
		'el' => 'GR',
		'en' => 'GB',
		'uk' => 'UA',
		'cs' => 'CZ',
		'da' => 'DK',
		'sv' => 'SE',
		'he' => 'IL',
		'ko' => 'KR',
		'zh' => 'CN',
		'hi' => 'IN',
		'vi' => 'VN',
		'ka' => 'GE',
		'hy' => 'AM',
		'et' => 'EE',
		'sl' => 'SI',
		'sq' => 'AL',
		'kk' => 'KZ',
		'be' => 'BY',
		'fa' => 'IR',
		'ar' => 'SA',
	);

	/**
	 * Код страны: регион локали (de_DE => DE), а для локалей без региона —
	 * страна по языку; иначе пустая строка.
	 *
	 * @param string $locale Локаль WordPress.
	 */
	public static function countryFor( string $locale ): string {
		$parts = explode( '_', $locale );

		if ( isset( $parts[1] ) && 1 === preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ) {
			return strtoupper( $parts[1] );
		}

		return self::LANGUAGE_COUNTRY[ strtolower( $parts[0] ) ] ?? '';
	}

	/**
	 * Emoji-флаг для поля «Флаг»: две региональные буквы Unicode
	 * (U+1F1E6 + номер буквы). Это 2 символа — в пределах лимита поля.
	 *
	 * @param string $locale Локаль WordPress.
	 */
	public static function flagFor( string $locale ): string {
		$country = self::countryFor( $locale );

		if ( 2 !== strlen( $country ) ) {
			return '';
		}

		$flag = '';

		foreach ( str_split( $country ) as $letter ) {
			$flag .= self::utf8Char( 0x1F1E6 + ord( $letter ) - 65 );
		}

		return $flag;
	}

	/**
	 * Символ Unicode в UTF-8 по номеру кодовой позиции.
	 *
	 * Функцию mbstring «символ по коду» не используем: расширение mbstring
	 * необязательно, а её полифил появился в WordPress только в 7.1 — на 6.4–7.0 без mbstring
	 * вызов привёл бы к фатальной ошибке. Суррогаты и числа вне Unicode дают
	 * пустую строку.
	 *
	 * @param int $codePoint Кодовая позиция.
	 */
	public static function utf8Char( int $codePoint ): string {
		if ( $codePoint < 0 || $codePoint > 0x10FFFF || ( $codePoint >= 0xD800 && $codePoint <= 0xDFFF ) ) {
			return '';
		}

		if ( $codePoint < 0x80 ) {
			return chr( $codePoint );
		}

		if ( $codePoint < 0x800 ) {
			return chr( 0xC0 | ( $codePoint >> 6 ) ) . chr( 0x80 | ( $codePoint & 0x3F ) );
		}

		if ( $codePoint < 0x10000 ) {
			return chr( 0xE0 | ( $codePoint >> 12 ) ) . chr( 0x80 | ( ( $codePoint >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $codePoint & 0x3F ) );
		}

		return chr( 0xF0 | ( $codePoint >> 18 ) )
			. chr( 0x80 | ( ( $codePoint >> 12 ) & 0x3F ) )
			. chr( 0x80 | ( ( $codePoint >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $codePoint & 0x3F ) );
	}

	/**
	 * Все известные языки: локаль => запись.
	 *
	 * @param callable|null $fetch Источник переводов WordPress (для тестов); по умолчанию — ядро.
	 * @return array<string, array{locale: string, code: string, native: string, english: string}>
	 */
	public static function all( ?callable $fetch = null ): array {
		$fetch  = $fetch ?? array( self::class, 'fetchFromWordPress' );
		$source = $fetch();
		$result = array();

		if ( is_array( $source ) ) {
			foreach ( $source as $locale => $info ) {
				if ( ! is_array( $info ) ) {
					continue;
				}

				$locale = (string) ( $info['language'] ?? $locale );
				$iso    = $info['iso'] ?? array();
				$iso1   = is_array( $iso ) && isset( $iso[1] ) ? (string) $iso[1] : '';

				$entry = self::entry(
					$locale,
					(string) ( $info['native_name'] ?? '' ),
					(string) ( $info['english_name'] ?? '' ),
					$iso1
				);

				if ( null !== $entry ) {
					$result[ $entry['locale'] ] = $entry;
				}
			}
		}

		// Пустой ответ или ошибка API: берём встроенный список.
		if ( array() === $result ) {
			foreach ( self::FALLBACK as $locale => $row ) {
				$entry = self::entry( $locale, $row[0], $row[1], $row[2] );

				if ( null !== $entry ) {
					$result[ $entry['locale'] ] = $entry;
				}
			}
		}

		// en_US в ответе API нет: это встроенная локаль ядра.
		if ( ! isset( $result['en_US'] ) ) {
			$result['en_US'] = array(
				'locale'  => 'en_US',
				'code'    => 'en',
				'native'  => 'English (US)',
				'english' => 'English (United States)',
			);
		}

		return $result;
	}

	/**
	 * Языки для выпадающего списка без тех, что уже есть в таблице.
	 *
	 * @param list<string>  $existingWpLocales Локали WordPress уже добавленных языков.
	 * @param callable|null $fetch             Источник переводов (для тестов).
	 * @return list<array{locale: string, code: string, native: string, english: string, label: string, flag: string}>
	 */
	public static function options( array $existingWpLocales, ?callable $fetch = null ): array {
		$existing = array_map( 'strval', $existingWpLocales );
		$options  = array();

		foreach ( self::all( $fetch ) as $entry ) {
			if ( in_array( $entry['locale'], $existing, true ) ) {
				continue;
			}

			$entry['label'] = self::label( $entry );
			$entry['flag']  = self::flagFor( $entry['locale'] );
			$options[]      = $entry;
		}

		usort(
			$options,
			static fn( array $a, array $b ): int => strcasecmp( $a['english'], $b['english'] )
		);

		return $options;
	}

	/**
	 * Подпись пункта: «Родное название (английское) — локаль».
	 *
	 * @param array{locale: string, native: string, english: string} $entry Запись языка.
	 */
	public static function label( array $entry ): string {
		$name = $entry['native'];

		if ( '' !== $entry['english'] && $entry['english'] !== $entry['native'] ) {
			$name .= ' (' . $entry['english'] . ')';
		}

		return $name . ' — ' . $entry['locale'];
	}

	/**
	 * Спрашивает у WordPress список переводов; при любой неудаче — пустой массив.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fetchFromWordPress() {
		if ( ! function_exists( 'wp_get_available_translations' ) && defined( 'ABSPATH' ) ) {
			$file = ABSPATH . 'wp-admin/includes/translation-install.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			return array();
		}

		return wp_get_available_translations();
	}

	/**
	 * Собирает запись; null — если локаль не годится.
	 *
	 * @param string $locale  Локаль WordPress.
	 * @param string $native  Родное название.
	 * @param string $english Английское название.
	 * @param string $iso1    ISO 639-1 (может быть пустым).
	 * @return array{locale: string, code: string, native: string, english: string}|null
	 */
	private static function entry( string $locale, string $native, string $english, string $iso1 ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]+)*$/', $locale ) ) {
			return null;
		}

		$code = strtolower( $iso1 );

		if ( 1 !== preg_match( '/^[a-z]{2}$/', $code ) ) {
			$code = strtolower( (string) strtok( $locale, '_' ) );
		}

		$native  = '' !== $native ? $native : $english;
		$english = '' !== $english ? $english : $native;

		return array(
			'locale'  => $locale,
			'code'    => $code,
			'native'  => '' !== $native ? $native : $locale,
			'english' => '' !== $english ? $english : $locale,
		);
	}
}
