<?php
/**
 * Контракт пост-обработчика DOM.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

use WpMlp\Settings\Language;

/**
 * Изменение документа после подстановки переводов.
 *
 * Позволяет доработать готовый DOM (SEO-теги, ссылки, медиа), не расширяя
 * Translator: он лишь по очереди вызывает зарегистрированные фильтры.
 */
interface DocumentFilter {

	/**
	 * Дорабатывает документ.
	 *
	 * @param HtmlDocument $document Разобранный документ.
	 * @param Language     $target   Язык, на котором отдаётся страница.
	 */
	public function apply( HtmlDocument $document, Language $target ): void;
}
