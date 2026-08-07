<?php
/**
 * Контракт провайдера машинного перевода.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Translation;

/**
 * Провайдер перевода строк (ТЗ 9.1).
 *
 * Сигнатура задана заранее, чтобы адаптеры OpenAI, DeepL и Google
 * подключились следующими сессиями без правок вызывающего кода.
 *
 * Перевод всегда идёт пачкой: провайдеры тарифицируют запросы, а одинаковые
 * строки на сайте повторяются десятками. Ключом результата служит хеш
 * исходной строки, а не её текст, — так ответ провайдера сопоставляется
 * с записями БД без повторной нормализации.
 */
interface ProviderInterface {

	/**
	 * Умеет ли провайдер переводить эту языковую пару.
	 *
	 * @param string $sourceLocale Исходный язык.
	 * @param string $targetLocale Целевой язык.
	 */
	public function supports( string $sourceLocale, string $targetLocale ): bool;

	/**
	 * Переводит пачку строк.
	 *
	 * @param array<string, string> $items        Хеш исходной строки => текст.
	 * @param string                $sourceLocale Исходный язык.
	 * @param string                $targetLocale Целевой язык.
	 * @param TranslationContext    $context      Контекст строк.
	 * @return array<string, string> Хеш исходной строки => перевод. Строки,
	 *                               которые перевести не удалось, в результат
	 *                               не попадают.
	 */
	public function translateBatch(
		array $items,
		string $sourceLocale,
		string $targetLocale,
		TranslationContext $context
	): array;
}
