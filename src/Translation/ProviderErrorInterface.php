<?php
/**
 * Диагностика последней ошибки провайдера перевода.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Провайдер, который умеет объяснить, почему последний вызов не дал перевода.
 *
 * Отдельный интерфейс, а не часть `ProviderInterface`: контроллеры REST читают
 * причину ошибки у любого провайдера одинаково, не проверяя конкретный класс.
 */
interface ProviderErrorInterface {

	/**
	 * Причина, по которой последний вызов `translateBatch()` не вернул перевод (или не весь).
	 */
	public function lastError(): ?string;

	/**
	 * Та же причина, безопасная для отправки в браузер: без ключей и токенов.
	 */
	public function maskedLastError(): ?string;

	/**
	 * Машинный код последней ошибки (для Cloud — `error.code` API), если он есть.
	 */
	public function lastErrorCode(): ?string;
}
