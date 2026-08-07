<?php
/**
 * Контракт сервиса, который сам вешает свои хуки WordPress.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

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
