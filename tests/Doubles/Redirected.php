<?php
/**
 * Исключение-замена `exit` после wp_safe_redirect() в тестах.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Doubles;

/**
 * Бросается заглушкой `wp_safe_redirect()`: прерывает обработчик так же, как `exit`,
 * и сохраняет итоговый адрес и список разрешённых узлов.
 */
final class Redirected extends \RuntimeException {

	/**
	 * @param string       $requested     Адрес, переданный в wp_safe_redirect().
	 * @param string       $location      Адрес, на который WordPress реально ушёл бы (чужой узел заменяется на админку).
	 * @param list<string> $allowedHosts  Узлы из фильтра allowed_redirect_hosts.
	 */
	public function __construct(
		public readonly string $requested,
		public readonly string $location,
		public readonly array $allowedHosts
	) {
		parent::__construct( 'redirect:' . $location );
	}
}
