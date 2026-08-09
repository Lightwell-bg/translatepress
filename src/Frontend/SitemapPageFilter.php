<?php
/**
 * Отсев служебных страниц из карты сайта.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Frontend;

/**
 * Убирает из списка записей саму служебную страницу и всё, что вложено под
 * ней (по цепочке родителей).
 *
 * Вынесено из {@see Sitemap} отдельно и не знает о WordPress: цепочки
 * предков (`get_post_ancestors()`) считает вызывающий код, сюда приходят уже
 * готовые данные — так правило проверяется юнит-тестом без реального сайта.
 */
final class SitemapPageFilter {

	/**
	 * Отфильтровывает id из списка исключённых и их потомков. Чистая функция.
	 *
	 * @param list<int>              $ids       Проверяемые идентификаторы записей.
	 * @param list<int>              $excluded  Идентификаторы служебных страниц — исключаются сами и с потомками.
	 * @param array<int, list<int>>  $ancestors Идентификатор записи => список id её предков (от родителя к корню).
	 * @return list<int>
	 */
	public static function excluding( array $ids, array $excluded, array $ancestors ): array {
		if ( array() === $excluded ) {
			return $ids;
		}

		return array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $excluded, $ancestors ): bool {
					if ( in_array( $id, $excluded, true ) ) {
						return false;
					}

					foreach ( $ancestors[ $id ] ?? array() as $ancestorId ) {
						if ( in_array( $ancestorId, $excluded, true ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}
}
