<?php
/**
 * Языковые rewrite-правила.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Routing;

use WpMlp\Settings\Settings;
use WpMlp\Support\Hookable;

/**
 * Дублирует все правила WordPress с языковым префиксом.
 *
 * Приём проверенный: для каждого опубликованного дополнительного языка каждое
 * существующее правило повторяется с префиксом `en/`. Слаг подставляется
 * литералом, а не группой захвата, поэтому нумерация `$matches[N]` в правой
 * части правил не сдвигается и переписывать её не нужно.
 *
 * Правила с префиксом идут первыми: иначе общий шаблон страниц `(.?.+?)/?$`
 * перехватил бы `/en/about/` и попытался найти страницу с путём `en/about`.
 */
final class Rewrites implements Hookable {

	/**
	 * @param Settings $settings Настройки плагина.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'rewrite_rules_array', array( $this, 'filterRules' ) );
	}

	/**
	 * Добавляет языковые правила к правилам WordPress.
	 *
	 * @param array<string, string> $rules Правила ядра и других плагинов.
	 * @return array<string, string>
	 */
	public function filterRules( $rules ): array {
		if ( ! is_array( $rules ) ) {
			return $rules;
		}

		$slugs = array();

		foreach ( $this->settings->published() as $language ) {
			if ( ! $language->isDefault ) {
				$slugs[] = $language->slug;
			}
		}

		return self::buildRules( $rules, $slugs );
	}

	/**
	 * Собирает итоговый набор правил. Чистая функция — её проверяют тесты.
	 *
	 * @param array<string, string> $rules Исходные правила.
	 * @param list<string>          $slugs Слаги дополнительных языков.
	 * @return array<string, string>
	 */
	public static function buildRules( array $rules, array $slugs ): array {
		if ( array() === $slugs ) {
			return $rules;
		}

		$languageRules = array();

		foreach ( $slugs as $slug ) {
			// Главная страница языка: /en/ и /en.
			$languageRules[ $slug . '/?$' ] = 'index.php';

			foreach ( $rules as $pattern => $query ) {
				$languageRules[ $slug . '/' . $pattern ] = $query;
			}
		}

		// Языковые правила должны проверяться раньше исходных.
		return $languageRules + $rules;
	}
}
