<?php
/**
 * Обёртка шаблона страницы на дополнительном языке.
 *
 * Подключается вместо шаблона темы через `template_include`
 * ({@see \LingoCanvas\Rendering\OutputBuffer::wrapTemplate()}) и выполняется,
 * как и сам шаблон, на глобальном уровне: шаблоны, которые обращаются к
 * глобальным переменным без `global`, работают как раньше.
 *
 * Если штатный буфер WordPress 6.9+ запущен и переведёт страницу сам, шаблон
 * просто подключается. Иначе (WordPress 6.4–6.8 или другой плагин отключил
 * штатный буфер) свой буфер открывается и закрывается здесь же, вокруг
 * подключения шаблона. Если шаблон завершит скрипт через exit, PHP закроет
 * буфер сам и перевод всё равно выполнится.
 *
 * @package LingoCanvas
 */

defined( 'ABSPATH' ) || exit;

$lingocanvas_buffer = \LingoCanvas\Rendering\OutputBuffer::active();

if ( null !== $lingocanvas_buffer && '' !== $lingocanvas_buffer->template() ) {
	if ( $lingocanvas_buffer->coreBufferActive() ) {
		include $lingocanvas_buffer->template();
	} else {
		/*
		 * Буфер без флага FLUSHABLE и с неограниченным размером, как у ядра
		 * 6.9: обработчик получает весь документ разом, а не куски после
		 * ob_flush(), которые HTML-парсер разобрал бы неправильно.
		 */
		$lingocanvas_level = ob_start(
			array( $lingocanvas_buffer, 'handleBuffer' ),
			0,
			PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
		) ? ob_get_level() : 0;

		include $lingocanvas_buffer->template();

		/*
		 * Закрытие: сначала буферы, которые шаблон открыл поверх нашего и не
		 * закрыл, затем наш. Если на нашем уровне уже чужой буфер (наш
		 * закрыли раньше), ничего не трогаем.
		 */
		if ( $lingocanvas_level > 0 && ob_get_level() >= $lingocanvas_level ) {
			$lingocanvas_handlers = ob_list_handlers();

			if ( ( $lingocanvas_handlers[ $lingocanvas_level - 1 ] ?? '' ) === \LingoCanvas\Rendering\OutputBuffer::class . '::handleBuffer' ) {
				while ( ob_get_level() > $lingocanvas_level && ob_end_flush() ) {
					continue;
				}

				if ( ob_get_level() === $lingocanvas_level ) {
					ob_end_flush();
				}
			}
		}
	}
}

unset( $lingocanvas_buffer, $lingocanvas_level, $lingocanvas_handlers );
