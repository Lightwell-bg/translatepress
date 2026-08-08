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

		$this->walk( $this->data, array(), '', $fields );

		return $fields;
	}

	/**
	 * Рекурсивно обходит граф.
	 *
	 * @param mixed             $node       Текущий узел графа.
	 * @param list<string|int>  $path       Путь до узла.
	 * @param string            $parentType Значение `@type` ближайшего объекта.
	 * @param list<JsonLdField> $fields     Накопитель результата.
	 */
	private function walk( $node, array $path, string $parentType, array &$fields ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		$type = isset( $node['@type'] ) && is_string( $node['@type'] ) ? $node['@type'] : $parentType;

		foreach ( $node as $key => $value ) {
			$childPath = array_merge( $path, array( $key ) );

			if ( is_array( $value ) ) {
				$this->walk( $value, $childPath, $type, $fields );

				continue;
			}

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			// Ключи массивов числовые — тип поля определяет имя выше по пути.
			$name = is_string( $key ) ? $key : (string) ( $path[ count( $path ) - 1 ] ?? '' );

			if ( JsonLdRules::isTranslatable( $name, $type ) ) {
				$fields[] = new JsonLdField( $this, $childPath, $value );
			}
		}
	}

	/**
	 * Адреса, которые стоит локализовать: путь до значения => значение.
	 *
	 * @return array<string, string> Ключ — путь, закодированный для set().
	 */
	public function urls(): array {
		$urls = array();

		$this->collectUrls( $this->data, array(), $urls );

		return $urls;
	}

	/**
	 * Рекурсивно собирает адреса.
	 *
	 * @param mixed                 $node Текущий узел графа.
	 * @param list<string|int>      $path Путь до узла.
	 * @param array<string, string> $urls Накопитель результата.
	 */
	private function collectUrls( $node, array $path, array &$urls ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		foreach ( $node as $key => $value ) {
			$childPath = array_merge( $path, array( $key ) );

			if ( is_array( $value ) ) {
				$this->collectUrls( $value, $childPath, $urls );

				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$name = is_string( $key ) ? $key : (string) ( $path[ count( $path ) - 1 ] ?? '' );

			if ( JsonLdRules::isUrl( $name ) ) {
				$urls[ implode( "\x1F", $childPath ) ] = $value;
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
	 */
	private function flush(): void {
		$json = wp_json_encode( $this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( is_string( $json ) ) {
			$this->node->textContent = $json;
		}
	}
}
