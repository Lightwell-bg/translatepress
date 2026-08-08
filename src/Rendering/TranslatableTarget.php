<?php
/**
 * Цель подстановки перевода, не являющаяся узлом DOM.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Куда записать перевод, если это не текстовый узел и не атрибут.
 *
 * Нужен строкам внутри JSON-LD: они лежат в структуре данных внутри тега
 * `<script>`, а не в DOM. Через этот интерфейс они проходят весь тот же
 * конвейер, что и обычные строки — попадают в БД, в список «Перевод строк»,
 * в визуальный редактор и в перевод с ИИ, — не заставляя Segment знать
 * о JSON.
 */
interface TranslatableTarget {

	/**
	 * Записывает перевод на своё место.
	 *
	 * @param string $translation Готовый перевод.
	 */
	public function applyTranslation( string $translation ): void;
}
