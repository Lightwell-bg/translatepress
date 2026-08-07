<?php
/**
 * Дневной бюджет символов на машинный перевод.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Считает символы, отправленные в переводчик за сегодня (ТЗ 9.3: «лимит
 * символов/слов в день»).
 *
 * Один счётчик на день в `wp_options`, а не отдельная таблица `wp_mlp_usage`
 * из раздела 6.5 ТЗ: при ручном запуске перевода (кнопкой, а не очередью)
 * объём данных не оправдывает отдельную таблицу. Если появится очередь —
 * это первое, что стоит заменить.
 */
final class UsageTracker {

	private const OPTION_PREFIX = 'mlp_usage_';

	/**
	 * Сколько символов уже потрачено сегодня.
	 */
	public function usedToday(): int {
		return (int) get_option( self::optionKey( self::today() ), 0 );
	}

	/**
	 * Сколько символов ещё можно отправить сегодня.
	 *
	 * @param int $dailyLimit Дневной лимит из настроек.
	 */
	public function remainingToday( int $dailyLimit ): int {
		return max( 0, $dailyLimit - $this->usedToday() );
	}

	/**
	 * Добавляет к счётчику сегодняшнего дня.
	 *
	 * @param int $chars Сколько символов ушло в API.
	 */
	public function record( int $chars ): void {
		if ( $chars <= 0 ) {
			return;
		}

		$key = self::optionKey( self::today() );

		update_option( $key, $this->usedToday() + $chars, false );

		// Опции за позавчера и раньше больше не нужны.
		self::cleanupYesterday();
	}

	/**
	 * Ключ опции для даты. Чистая функция.
	 *
	 * @param string $date Дата в формате `Y-m-d`.
	 */
	public static function optionKey( string $date ): string {
		return self::OPTION_PREFIX . $date;
	}

	/**
	 * Сегодняшняя дата по настройкам сайта, а не сервера: разные хостинги
	 * стоят в разных часовых поясах, а лимит должен сбрасываться в полночь
	 * по времени владельца сайта.
	 */
	private static function today(): string {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Удаляет счётчик вчерашнего дня, чтобы опции не копились бесконечно.
	 */
	private static function cleanupYesterday(): void {
		$yesterday = wp_date( 'Y-m-d', strtotime( self::today() . ' -1 day' ) );

		if ( is_string( $yesterday ) ) {
			delete_option( self::optionKey( $yesterday ) );
		}
	}
}
