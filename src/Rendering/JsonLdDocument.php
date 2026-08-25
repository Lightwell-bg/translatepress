<?php
/**
 * Разбор и обратная сборка блока структурированных данных.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Один тег `<script type="application/ld+json">`.
 *
 * Держит разобранный граф и узел, из которого он взят. При каждой записи
 * сразу пересобирает JSON обратно в узел: блоки микроразметки маленькие,
 * а взамен не нужно координировать «когда же сохранить» между Extractor,
 * Translator и Segment.
 */
final class JsonLdDocument {

	/**
	 * Разобранный граф.
	 *
	 * @var array<mixed>
	 */
	private array $data;

	/**
	 * @param object       $node Узел `<script>`.
	 * @param array<mixed> $data Разобранный граф.
	 */
	private function __construct( private readonly object $node, array $data ) {
		$this->data = $data;
	}

	/**
	 * Разбирает содержимое узла. Возвращает null, если это не JSON-объект.
	 *
	 * @param object $node Узел `<script type="application/ld+json">`.
	 */
	public static function fromNode( object $node ): ?self {
		$raw = trim( (string) $node->textContent );

		if ( '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		// Скаляр или сломанный JSON трогать нечего и незачем.
		if ( ! is_array( $data ) ) {
			return null;
		}

		return new self( $node, $data );
	}

	/**
	 * Собирает переводимые поля графа.
	 *
	 * @return list<JsonLdField>
	 */
	public function fields(): array {
		$fields = array();

		foreach ( $this->collect( array( JsonLdRules::class, 'isTranslatable' ) ) as $encodedPath => $value ) {
			$fields[] = new JsonLdField( $this, explode( "\x1F", $encodedPath ), $value );
		}

		return $fields;
	}

	/**
	 * Адреса, которые стоит локализовать: путь (закодированный) => значение.
	 *
	 * @return array<string, string>
	 */
	public function urls(): array {
		return $this->collect( array( JsonLdRules::class, 'isUrl' ) );
	}

	/**
	 * Все поля `@id` в графе: путь (закодированный) => текущее значение.
	 *
	 * Без разбора по типу и без оглядки на родителя намеренно: `@id` — это
	 * не только «определение» сущности (`{"@type":"Organization","@id":"..."}`),
	 * но и ссылка на неё же из другого места графа
	 * (`"publisher":{"@id":"..."}"`, `"author":{"@id":"..."}"`,
	 * `"mainEntityOfPage":{"@id":"..."}"` — у ссылки своего `@type` обычно
	 * нет вовсе, и определять её по родителю бессмысленно: `publisher` у
	 * `Article` не делает саму ссылку статьёй). Правильный способ решить,
	 * что делать со значением `@id`, — не структурная позиция в графе, а
	 * СОВПАДЕНИЕ этого значения с `@id` где-то ОПРЕДELённой сущности, см.
	 * definedEntityTypes() и SeoMeta::normalizeGraphIds().
	 *
	 * @return array<string, string>
	 */
	public function allIdFields(): array {
		return $this->collect( static fn( string $key, string $type ): bool => '@id' === $key );
	}

	/**
	 * Карта «`@id` → типы сущности» для каждого узла графа, где сущность
	 * ОПРЕДЕЛЕНА (несёт и `@id`, и `@type` на одном уровне), а не просто
	 * упомянута ссылкой.
	 *
	 * `@type` в схеме — валидно и строкой, и списком строк
	 * (`"@type":["Person","Organization"]`), поэтому типы всегда
	 * возвращаются списком, в нижнем регистре.
	 *
	 * @return array<string, list<string>>
	 */
	public function definedEntityTypes(): array {
		$definitions = array();

		$this->collectDefinitions( $this->data, $definitions );

		return $definitions;
	}

	/**
	 * Рекурсивно ищет узлы, определяющие сущность.
	 *
	 * @param mixed                        $node        Текущий узел графа.
	 * @param array<string, list<string>>  $definitions Накопитель результата.
	 */
	private function collectDefinitions( $node, array &$definitions ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['@id'], $node['@type'] ) && is_string( $node['@id'] ) && '' !== $node['@id'] ) {
			$types = self::typesOf( $node['@type'] );

			if ( array() !== $types ) {
				$definitions[ $node['@id'] ] = $types;
			}
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collectDefinitions( $value, $definitions );
			}
		}
	}

	/**
	 * Приводит значение `@type` к списку строк в нижнем регистре. Чистая функция.
	 *
	 * @param mixed $type Сырое значение `@type`: строка, список строк или мусор.
	 * @return list<string>
	 */
	private static function typesOf( $type ): array {
		$raw = is_array( $type ) ? $type : array( $type );

		return array_values(
			array_filter(
				array_map(
					static fn( $value ): ?string => is_string( $value ) && '' !== $value ? strtolower( $value ) : null,
					$raw
				)
			)
		);
	}

	/**
	 * Значения `@id`, на которые ссылается свойство `logo` где-либо в графе.
	 *
	 * `Organization.logo`/`Person.logo` — это сайтовый брендовый актив, тот же
	 * логотип на любой языковой версии: его `@id` обязан оставаться стабильным
	 * ровно как у самой Organization, даже когда тип сущности-логотипа —
	 * `ImageObject`, который в остальных случаях («изображение записи»,
	 * `Article.image`/`WebPage.primaryImageOfPage`) наоборот, страницезависим
	 * и получает языковой префикс (см. PAGE_SCOPED_ID_TYPES). Различить два
	 * этих случая по одному только `@type` нельзя — оба `ImageObject`, — а вот
	 * по ИМЕНИ ССЫЛАЮЩЕГОСЯ СВОЙСТВА можно: `logo` есть только у бренда.
	 * Работает и когда `logo` — голая ссылка (`{"@id":"..."}`), и когда это
	 * полное inline-определение сущности (`{"@type":"ImageObject","@id":"..."}`)
	 * — оба вида несут `@id` на своём уровне, только второй ещё и `@type`.
	 *
	 * @return list<string>
	 */
	public function logoReferenceIds(): array {
		$ids = array();

		$this->collectLogoReferenceIds( $this->data, $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Рекурсивно ищет свойства `logo` со значением `@id`.
	 *
	 * @param mixed        $node Текущий узел графа.
	 * @param list<string> $ids  Накопитель результата.
	 */
	private function collectLogoReferenceIds( $node, array &$ids ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( isset( $node['logo'] ) && is_array( $node['logo'] )
			&& isset( $node['logo']['@id'] ) && is_string( $node['logo']['@id'] ) && '' !== $node['logo']['@id']
		) {
			$ids[] = $node['logo']['@id'];
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$this->collectLogoReferenceIds( $value, $ids );
			}
		}
	}

	/**
	 * Поля `inLanguage`: путь (закодированный) => текущее значение.
	 *
	 * Не текст для перевода, а код языка для подмены — как `og:locale`,
	 * только внутри графа. Тип объекта тут не важен: `inLanguage` значит
	 * «язык содержимого» у любого узла, где встречается.
	 *
	 * @return array<string, string>
	 */
	public function inLanguageFields(): array {
		return $this->collect( static fn( string $key, string $type ): bool => 'inLanguage' === $key );
	}

	/**
	 * Обходит граф и собирает пары путь-значение, прошедшие предикат.
	 *
	 * Общий обход для `fields()`, `urls()` и `inLanguageFields()`: у всех
	 * одна и та же форма задачи — «дойти до каждой строки в графе, зная
	 * путь до неё и `@type` ближайшего объекта, решить по предикату, брать
	 * ли её». Три copy-paste обхода было источником рассинхронизации:
	 * `urls()` не знал о `@type` вообще, пока `fields()` уже умел.
	 *
	 * @param callable(string $key, string $parentType): bool $predicate Что искать.
	 * @return array<string, string> Путь, закодированный `\x1F`, => значение.
	 */
	private function collect( callable $predicate ): array {
		$found = array();

		$this->walk( $this->data, array(), '', $predicate, $found );

		return $found;
	}

	/**
	 * Рекурсивно обходит граф.
	 *
	 * @param mixed                                            $node       Текущий узел графа.
	 * @param list<string|int>                                 $path       Путь до узла.
	 * @param string                                            $parentType Значение `@type` ближайшего объекта.
	 * @param callable(string $key, string $parentType): bool  $predicate  Что искать.
	 * @param array<string, string>                            $found      Накопитель результата.
	 */
	private function walk( $node, array $path, string $parentType, callable $predicate, array &$found ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		// `@type` валиден и строкой, и списком строк (`["Person","Organization"]`):
		// первый распознанный элемент считается основным типом узла — так же,
		// как это делает definedEntityTypes() для более точной, полноценной
		// классификации по всему списку типов.
		$types = isset( $node['@type'] ) ? self::typesOf( $node['@type'] ) : array();
		$type  = array() !== $types ? $types[0] : $parentType;

		foreach ( $node as $key => $value ) {
			$childPath = array_merge( $path, array( $key ) );

			if ( is_array( $value ) ) {
				$this->walk( $value, $childPath, $type, $predicate, $found );

				continue;
			}

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			// Ключи массивов числовые — тип поля определяет имя выше по пути.
			$name = is_string( $key ) ? $key : (string) ( $path[ count( $path ) - 1 ] ?? '' );

			if ( $predicate( $name, $type ) ) {
				$found[ implode( "\x1F", $childPath ) ] = $value;
			}
		}
	}

	/**
	 * Записывает значение по пути и пересобирает JSON в узле.
	 *
	 * @param list<string|int> $path  Путь до значения.
	 * @param string           $value Новое значение.
	 */
	public function set( array $path, string $value ): void {
		$target = &$this->data;

		foreach ( $path as $key ) {
			if ( ! is_array( $target ) || ! array_key_exists( $key, $target ) ) {
				return;
			}

			$target = &$target[ $key ];
		}

		$target = $value;
		unset( $target );

		$this->flush();
	}

	/**
	 * Записывает значение по пути, закодированному в строку (см. urls()).
	 *
	 * @param string $encodedPath Путь, склеенный разделителем.
	 * @param string $value       Новое значение.
	 */
	public function setByEncodedPath( string $encodedPath, string $value ): void {
		$this->set( explode( "\x1F", $encodedPath ), $value );
	}

	/**
	 * Пересобирает JSON обратно в узел `<script>`.
	 *
	 * `JSON_HEX_TAG` обязателен: содержимое `<script>` сериализатор HTML
	 * отдаёт сырым, без экранирования, — так требует спецификация. Строка
	 * `</script>`, попавшая в любое поле графа, закрыла бы тег досрочно:
	 * разметка для поисковика оборвалась бы посреди JSON, а его хвост
	 * вывалился бы в видимый текст страницы. С флагом `<` и `>` уходят как
	 * `<`/`>` — по JSON это та же строка, но закрыть тег ею уже
	 * нельзя.
	 *
	 * Санитайзеры на путях записи перевода сегодня срезают такие теги и
	 * сами, но они лежат ВНЕ сериализатора: новый путь записи (импорт,
	 * WP-CLI, миграция) снял бы защиту молча. Здесь она не зависит от
	 * того, кто положил строку в граф.
	 *
	 * `JSON_UNESCAPED_SLASHES` сохранён: он касается только `/`, к
	 * закрытию тега отношения не имеет, а без него все адреса в разметке
	 * превращаются в `https:\/\/…`.
	 */
	private function flush(): void {
		$json = wp_json_encode( $this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );

		if ( is_string( $json ) ) {
			$this->node->textContent = $json;
		}
	}
}
