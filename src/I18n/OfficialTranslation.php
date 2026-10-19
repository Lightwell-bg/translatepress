<?php
/**
 * Официальный перевод чужой строки из загруженного файла переводов.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Ищет перевод строки темы, плагина или ядра прямо в объекте переводов домена.
 *
 * Вкладка «Интерфейс» показывает строки, которые плагин собрал с чужих
 * страниц: msgid, контекст и text domain здесь — данные из базы, а не
 * литералы нашего кода. Функции `translate()`/`translate_with_gettext_context()`
 * для этого не годятся по двум причинам:
 * - сканер WordPress.org требует литералы в аргументах gettext-функций
 *   (иначе строку не извлечь для перевода) — к поиску чужих переводов это
 *   требование неприменимо, но вызов всё равно отклоняется;
 * - они пропускают результат через фильтры `gettext*`, в том числе через наш
 *   собственный (LocaleSwitcher/GettextRegistry подставляет ручные
 *   переопределения). Тогда колонка «официальный перевод» показала бы
 *   переопределение владельца сайта, а не текст языкового пакета.
 *
 * Объект из `get_translations_for_domain()` — это сами загруженные файлы
 * (`WP_Translations` с WordPress 6.5, `Translations`/`MO` раньше,
 * `NOOP_Translations`, если пакета нет) без фильтров. У всех трёх есть
 * `translate()` и `translate_plural()` с одинаковой сигнатурой.
 *
 * Класс не обращается к WordPress сам: объект переводов передаёт вызывающий
 * код, поэтому правила проверяются тестом с поддельным объектом.
 */
final class OfficialTranslation {

	/**
	 * До какого числа искать пример для формы множественного числа.
	 *
	 * Во всех языках WordPress каждая форма встречается среди первых двухсот
	 * чисел (самые сложные правила — арабский, валлийский — укладываются в 0–111).
	 */
	private const PLURAL_PROBE_LIMIT = 200;

	/**
	 * Перевод строки или null, если в файлах переводов её нет.
	 *
	 * Перевод, совпадающий с оригиналом, тоже считается отсутствующим: так
	 * WordPress сообщает «строки нет» (возвращает исходный текст), и отличить
	 * это от настоящего перевода «OK» → «OK» невозможно. Прежний код вёл себя
	 * так же.
	 *
	 * @param object   $translations Объект из `get_translations_for_domain()`.
	 * @param string   $msgid        Оригинал (для `_n()` — форма единственного числа).
	 * @param string   $context      Контекст `_x()`/`_nx()` или пустая строка.
	 * @param int|null $pluralKey    Номер формы множественного числа или null.
	 */
	public static function lookup( object $translations, string $msgid, string $context, ?int $pluralKey ): ?string {
		if ( '' === $msgid ) {
			return null;
		}

		if ( null === $pluralKey ) {
			if ( ! method_exists( $translations, 'translate' ) ) {
				return null;
			}

			return self::found( $translations->translate( $msgid, $context ), $msgid );
		}

		if ( ! method_exists( $translations, 'translate_plural' ) ) {
			return null;
		}

		$number = self::sampleNumber( $translations, $pluralKey );

		if ( null === $number ) {
			return null;
		}

		/*
		 * Исходная форма множественного числа в базе не хранится (её не нужно
		 * было знать для подмены), поэтому вторым аргументом идёт тот же msgid.
		 * Это безопасно: и `Translations`, и `WP_Translation_Controller` ищут
		 * запись по единственному числу (контроллер сначала пробует пару
		 * «единственное\0множественное», затем — одно единственное). Если записи
		 * нет, возвращается один из аргументов, то есть msgid, — и `found()`
		 * отвечает «перевода нет».
		 */
		return self::found( $translations->translate_plural( $msgid, $msgid, $number, $context ), $msgid );
	}

	/**
	 * Число, для которого объект переводов выбирает форму `$pluralKey`.
	 *
	 * Номер формы сохраняется в базу по тому же правилу (см.
	 * `GettextRegistry::pluralKey()`): `select_plural_form()` объекта, а если
	 * метода нет (`WP_Translations` в WordPress 6.5+) — английское правило
	 * «1 → 0, остальное → 1». Пример числа подбирается по нему же, иначе
	 * показанный официальный перевод не совпал бы с формой, которую
	 * переопределяет пользователь.
	 *
	 * @param object $translations Объект переводов домена.
	 * @param int    $pluralKey    Номер формы.
	 */
	public static function sampleNumber( object $translations, int $pluralKey ): ?int {
		if ( $pluralKey < 0 ) {
			return null;
		}

		if ( ! method_exists( $translations, 'select_plural_form' ) ) {
			if ( $pluralKey > 1 ) {
				return null;
			}

			return 0 === $pluralKey ? 1 : 2;
		}

		for ( $number = 0; $number <= self::PLURAL_PROBE_LIMIT; $number++ ) {
			if ( (int) $translations->select_plural_form( $number ) === $pluralKey ) {
				return $number;
			}
		}

		return null;
	}

	/**
	 * Нормализует ответ объекта переводов.
	 *
	 * @param mixed  $translated Что вернул объект.
	 * @param string $msgid      Оригинал.
	 */
	private static function found( $translated, string $msgid ): ?string {
		if ( ! is_string( $translated ) || '' === $translated || $msgid === $translated ) {
			return null;
		}

		return $translated;
	}
}
