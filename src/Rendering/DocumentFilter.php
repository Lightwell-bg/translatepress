<?php
/**
 * Контракт пост-обработчика DOM.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Rendering;

use LingoCanvas\Settings\Language;

defined( 'ABSPATH' ) || exit;

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
