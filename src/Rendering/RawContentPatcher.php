<?php
/**
 * Точечная подстановка перевода в исходную строку — без разбора-сборки DOM.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Rendering;

/**
 * Клеит новую строку из кусков исходной, заменяя только явно найденные
 * диапазоны — в отличие от `HtmlDocument::parse()` → правка DOM →
 * `html()`, которая пересобирает КАЖДЫЙ тег заново и по пути нормализует
 * то, что не должна была трогать вообще: `<img ... />` превращается в
 * `<img ...>`, стиль кавычек атрибутов и порядок атрибутов не гарантированы.
 *
 * Здесь наоборот: то, что не найдено и не заменено, копируется из
 * исходника функцией `substr()`, посимвольно, — гарантия байт-в-байт даётся
 * не тем, что код «старается не менять лишнего», а тем, что менять
 * лишнее ему просто нечем.
 */
final class RawContentPatcher {

	/**
	 * Применяет патчи по порядку их появления в строке.
	 *
	 * Патчи обязаны идти в том же порядке, в каком их искомые подстроки
	 * встречаются в `$original` — курсор поиска только двигается вперёд
	 * (см. Extractor::extract(), которая с этой версии отдаёт сегменты
	 * в порядке документа именно ради этого). Так два одинаковых слова
	 * в разных местах текста не перепутаются местами: первое совпадение
	 * после курсора отдаётся первому патчу, следующее — второму, и так далее.
	 *
	 * @param string                                                   $original Исходная строка целиком.
	 * @param list<array{candidates: list<string>, replacement: string}> $patches  Патчи в порядке появления в тексте.
	 * @return string|null Готовая строка, либо null — если хотя бы один патч
	 *                      не нашёлся ни по одному из кандидатов начиная
	 *                      с текущей позиции. Никогда не подставляет
	 *                      «наугад» и не пропускает ненайденный патч молча.
	 */
	public static function apply( string $original, array $patches ): ?string {
		$cursor = 0;
		$result = '';

		foreach ( $patches as $patch ) {
			$found = self::locate( $original, $patch['candidates'], $cursor );

			if ( null === $found ) {
				return null;
			}

			list( $position, $length ) = $found;

			$result .= substr( $original, $cursor, $position - $cursor );
			$result .= $patch['replacement'];
			$cursor  = $position + $length;
		}

		return $result . substr( $original, $cursor );
	}

	/**
	 * Первое совпадение любого из кандидатов начиная с $cursor. Чистая функция.
	 *
	 * @param string       $haystack   Строка, в которой ищем.
	 * @param list<string> $candidates Варианты искомой подстроки, по приоритету.
	 * @param int          $cursor     Позиция, раньше которой искать нельзя.
	 * @return array{0: int, 1: int}|null Позиция и длина найденного варианта.
	 */
	private static function locate( string $haystack, array $candidates, int $cursor ): ?array {
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}

			$position = strpos( $haystack, $candidate, $cursor );

			if ( false !== $position ) {
				return array( $position, strlen( $candidate ) );
			}
		}

		return null;
	}
}
