<?php
/**
 * Описание одного языка сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Settings;

use WpMlp\Support\Locale;

/**
 * Неизменяемое значение: код, URL-слаг, название, статус и флаг языка.
 */
final class Language {

	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DRAFT     = 'draft';

	/**
	 * Сколько символов флага-emoji хранить максимум.
	 *
	 * Флаг стран вроде 🇷🇺 — это два кодовых пункта Unicode (regional
	 * indicator), но некоторые флаги (например Шотландии) — составные
	 * emoji-последовательности подлиннее. Ограничение защищает поле от
	 * случайно вставленного постороннего текста, а не от настоящих флагов.
	 */
	private const MAX_FLAG_LENGTH = 16;

	/**
	 * @param string $locale    Нормализованный код языка, например `en`.
	 * @param string $slug      Сегмент URL, например `en`.
	 * @param string $label     Название для переключателя языков.
	 * @param string $status    STATUS_PUBLISHED или STATUS_DRAFT.
	 * @param bool   $isDefault Язык по умолчанию (отдаётся без префикса).
	 * @param string $flag      Emoji-флаг для переключателя, вводится вручную.
	 */
	public function __construct(
		public readonly string $locale,
		public readonly string $slug,
		public readonly string $label,
		public readonly string $status,
		public readonly bool $isDefault,
		public readonly string $flag = ''
	) {
	}

	/**
	 * Собирает объект из массива настроек.
	 *
	 * @param array<string, mixed> $data      Сырые данные из wp_options.
	 * @param bool                 $isDefault Является ли язык языком по умолчанию.
	 */
	public static function fromArray( array $data, bool $isDefault ): self {
		$locale = Locale::normalize( (string) ( $data['locale'] ?? '' ) );
		$slug   = Locale::normalizeSlug( (string) ( $data['slug'] ?? $locale ) );
		$label  = trim( (string) ( $data['label'] ?? '' ) );
		$status = ( $data['status'] ?? self::STATUS_PUBLISHED ) === self::STATUS_DRAFT
			? self::STATUS_DRAFT
			: self::STATUS_PUBLISHED;

		return new self(
			$locale,
			'' !== $slug ? $slug : $locale,
			'' !== $label ? $label : $locale,
			// Язык по умолчанию всегда доступен: черновиком он быть не может.
			$isDefault ? self::STATUS_PUBLISHED : $status,
			$isDefault,
			self::sanitizeFlag( (string) ( $data['flag'] ?? '' ) )
		);
	}

	/**
	 * Приводит значение флага к безопасному виду. Чистая функция.
	 *
	 * Не проверяет, что это именно emoji-флаг (символ ×256 не отличить от
	 * буквы средствами PHP без библиотеки Unicode-свойств) — только режет
	 * длину и убирает теги, чтобы поле нельзя было превратить в HTML-инъекцию
	 * в шаблонах, где флаг выводится без дополнительного экранирования.
	 *
	 * @param string $flag Сырое значение поля.
	 */
	public static function sanitizeFlag( string $flag ): string {
		$flag = trim( wp_strip_all_tags( $flag ) );

		return mb_substr( $flag, 0, self::MAX_FLAG_LENGTH );
	}

	/**
	 * Опубликован ли язык (есть ли у него доступный URL и hreflang).
	 */
	public function isPublished(): bool {
		return self::STATUS_PUBLISHED === $this->status;
	}

	/**
	 * Значение для атрибутов `lang` и `hreflang`.
	 */
	public function bcp47(): string {
		return Locale::toBcp47( $this->locale );
	}

	/**
	 * Название с флагом впереди, если он задан — готовая подпись для
	 * переключателя, без пляски с проверкой пустой строки в шаблонах.
	 */
	public function labelWithFlag(): string {
		return '' !== $this->flag ? $this->flag . ' ' . $this->label : $this->label;
	}

	/**
	 * Представление для сохранения в wp_options.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return array(
			'locale' => $this->locale,
			'slug'   => $this->slug,
			'label'  => $this->label,
			'status' => $this->status,
			'flag'   => $this->flag,
		);
	}
}
