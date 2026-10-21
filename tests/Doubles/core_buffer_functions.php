<?php
/**
 * Функции штатного буфера WordPress 6.9+ для тестов OutputBuffer.
 *
 * Подключается только в отдельном процессе PHPUnit: определить функцию
 * условно, по тесту, иначе нельзя.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_start_template_enhancement_output_buffer' ) ) {
	/**
	 * Заглушка запуска штатного буфера.
	 */
	function wp_start_template_enhancement_output_buffer(): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
	/**
	 * Решение ядра, открывать ли штатный буфер; по умолчанию да.
	 * Тест задаёт `lingocanvas_test_wp()['core_should_buffer']` = false, как фильтр другого плагина.
	 */
	function wp_should_output_buffer_template_for_enhancement(): bool {
		return (bool) ( lingocanvas_test_wp()['core_should_buffer'] ?? true );
	}
}

if ( ! function_exists( 'wp_finalize_template_enhancement_output_buffer' ) ) {
	/**
	 * Обработчик штатного буфера: здесь возвращает вывод как есть.
	 *
	 * @param string $buffer Вывод.
	 */
	function wp_finalize_template_enhancement_output_buffer( string $buffer ): string {
		return $buffer;
	}
}
