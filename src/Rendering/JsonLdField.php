<?php
/**
 * Одно переводимое поле структурированных данных.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Ссылка на строку внутри графа JSON-LD.
 *
 * Играет ту же роль, что узел DOM для обычной строки: знает, куда именно
 * положить перевод. Благодаря этому строки микроразметки проходят общий
 * конвейер и попадают в словарь наравне с текстом страницы.
 */
final class JsonLdField implements TranslatableTarget {

	/**
	 * @param JsonLdDocument   $document Блок, которому принадлежит поле.
	 * @param list<string|int> $path     Путь до значения внутри графа.
	 * @param string           $value    Исходное значение.
	 */
	public function __construct(
		private readonly JsonLdDocument $document,
		private readonly array $path,
		public readonly string $value
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function applyTranslation( string $translation ): void {
		$this->document->set( $this->path, $translation );
	}

	/**
	 * Имя самого поля — последний элемент пути (`headline`, `description`…).
	 *
	 * Нужно Extractor'у, чтобы отличить `headline` (дублирует заголовок
	 * записи, хешируется как обычный текст, см. Extractor::makeSegment())
	 * от остальных переводимых полей JSON-LD — не разбирать `$path` заново
	 * там, где для этого уже есть готовый объект.
	 */
	public function key(): string {
		return array() !== $this->path ? (string) $this->path[ count( $this->path ) - 1 ] : '';
	}
}
