<?php
/**
 * Провайдер «перевод вручную».
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Заглушка на время ручного перевода.
 *
 * Ничего никуда не отправляет и ничего не возвращает: на Этапе 1 переводы
 * вводит человек в админке. Класс существует, чтобы остальная архитектура
 * уже сейчас работала через ProviderInterface, и подключение OpenAI свелось
 * к добавлению одного класса и его регистрации в контейнере.
 */
final class ManualProvider implements ProviderInterface {

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $sourceLocale, string $targetLocale ): bool {
		unset( $sourceLocale, $targetLocale );

		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function translateBatch(
		array $items,
		string $sourceLocale,
		string $targetLocale,
		TranslationContext $context
	): array {
		unset( $items, $sourceLocale, $targetLocale, $context );

		return array();
	}
}
