<?php
/**
 * Что именно можно переводить в структурированных данных.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Allowlist полей JSON-LD (ТЗ 8.4: «только разрешённые текстовые поля и URL,
 * без слепого перевода ключей и идентификаторов»).
 *
 * Слепой обход «переводим все строки» здесь недопустим: в графе лежат
 * идентификаторы, типы, даты, адреса картинок и названия организаций.
 * Перевод любого из них ломает микроразметку молча — поисковик просто
 * перестанет её понимать, а в интерфейсе сайта ничего не изменится.
 */
final class JsonLdRules {

	/**
	 * Поля, которые переводятся всегда.
	 *
	 * Это заголовки и описания — то, что человек видит в выдаче.
	 */
	private const ALWAYS = array(
		'headline',
		'alternativeHeadline',
		'description',
		'caption',
		'articleSection',
		'abstract',
		'disambiguatingDescription',
	);

	/**
	 * Типы, у которых `name` — это имя собственное, а не заголовок.
	 *
	 * Название компании, имя автора и марка не переводятся: «CenterAI»
	 * должно остаться «CenterAI» на любом языке.
	 */
	private const NAME_IS_PROPER_NOUN = array(
		'person',
		'organization',
		'corporation',
		'brand',
		'localbusiness',
		'imageobject',
		'sitenavigationelement',
	);

	/**
	 * Поля с адресами: их не переводят, но у них меняется языковой префикс.
	 */
	private const URLS = array( 'url' );

	/**
	 * Переводится ли значение поля.
	 *
	 * @param string $key        Имя поля.
	 * @param string $parentType Значение `@type` ближайшего объекта, в нижнем регистре.
	 */
	public static function isTranslatable( string $key, string $parentType ): bool {
		if ( in_array( $key, self::ALWAYS, true ) ) {
			return true;
		}

		if ( 'name' !== $key ) {
			return false;
		}

		return ! in_array( strtolower( $parentType ), self::NAME_IS_PROPER_NOUN, true );
	}

	/**
	 * Является ли поле адресом, который нужно локализовать.
	 *
	 * @param string $key Имя поля.
	 */
	public static function isUrl( string $key ): bool {
		return in_array( $key, self::URLS, true );
	}
}
