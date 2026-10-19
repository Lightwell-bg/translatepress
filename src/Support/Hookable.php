<?php
/**
 * Контракт сервиса, который сам вешает свои хуки WordPress.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Сервис, регистрирующий собственные action/filter.
 *
 * Plugin не знает, какие именно хуки нужны сервису, — он только вызывает
 * register() у каждого. Это позволяет добавлять модули, не трогая bootstrap.
 */
interface Hookable {

	/**
	 * Регистрирует хуки сервиса.
	 */
	public function register(): void;
}
