<?php
/**
 * Что переключатель языков показывает посетителю.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Settings;

/**
 * Режимы подписи в переключателе языков и разбор их значения.
 *
 * Раньше выбора не было вовсе: выводилось название языка, а перед ним —
 * emoji-флаг, если владелец сайта его вписал. Название на своём же языке
 * («Русский», «Български») занимает много места и в тесной шапке или
 * подвале зачастую не нужно — коду `RU` или флага достаточно.
 */
final class SwitcherDisplay {

	public const LABEL     = 'label';
	public const CODE      = 'code';
	public const FLAG      = 'flag';
	public const FLAG_CODE = 'flag_code';

	/**
	 * Все режимы.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::LABEL, self::CODE, self::FLAG, self::FLAG_CODE );
	}

	/**
	 * Приводит значение из формы к известному режиму. Чистая функция.
	 *
	 * Незнакомое схлопывается в `label` — то, как переключатель выглядел
	 * до появления этой настройки. Так обновление плагина не меняет вид
	 * сайта само по себе, и так же ведёт себя сайт, где настройку ещё ни
	 * разу не сохраняли.
	 *
	 * @param string $mode Значение из формы настроек.
	 */
	public static function sanitize( string $mode ): string {
		return in_array( $mode, self::all(), true ) ? $mode : self::LABEL;
	}

	/**
	 * Показывать ли в этом режиме флаг. Чистая функция.
	 *
	 * `label` флаг ПОКАЗЫВАЕТ — это тот самый вид, что был до появления
	 * настройки: флаг впереди, за ним название, и всё это только если флаг
	 * вообще задан. Убрать его отсюда значило бы у всех, кто когда-то
	 * вписал emoji, молча стереть его при обновлении плагина.
	 *
	 * Без флага остаётся единственный режим — `code`: там и просят голый
	 * код языка.
	 *
	 * @param string $mode Режим.
	 */
	public static function showsFlag( string $mode ): bool {
		return self::CODE !== self::sanitize( $mode );
	}

	/**
	 * Текстовая часть подписи. Чистая функция.
	 *
	 * У режима «только флаг» её нет: подпись собирается из одной картинки,
	 * а название языка уезжает в атрибут `title` (см. LanguageSwitcher) —
	 * иначе переключатель нельзя было бы понять без разглядывания флажков.
	 *
	 * @param Language $language Язык.
	 * @param string   $mode     Режим.
	 */
	public static function text( Language $language, string $mode ): string {
		return match ( self::sanitize( $mode ) ) {
			self::CODE, self::FLAG_CODE => $language->switcherCode(),
			self::FLAG                  => '',
			default                     => $language->label,
		};
	}

	/**
	 * Чем заменить флаг, когда картинки для языка нет. Чистая функция.
	 *
	 * Сначала вписанный вручную emoji. Если нет и его — код языка, но
	 * ТОЛЬКО когда без него подпись останется пустой, то есть в режиме
	 * «только флаг». В остальных режимах текст уже есть, и приписывать к
	 * названию ещё и код («RU Русский») незачем — а в `label` это вдобавок
	 * поменяло бы вид сайтам, которые ничего не настраивали.
	 *
	 * @param Language $language Язык.
	 * @param string   $mode     Режим показа.
	 */
	public static function fallbackFlag( Language $language, string $mode ): string {
		if ( '' !== $language->flag ) {
			return $language->flag;
		}

		return '' === self::text( $language, $mode ) ? $language->switcherCode() : '';
	}
}
