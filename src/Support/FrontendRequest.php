<?php
/**
 * Признак «это обычная публичная отрисовка страницы».
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Support;

/**
 * Один ответ на вопрос «переводить ли этот запрос вообще» — для всех, кто
 * его задаёт.
 *
 * Вынесено в общее место не ради красоты, а потому что расхождение здесь
 * даёт противоречивую страницу. Подмена локали ({@see \WpMlp\I18n\LocaleSwitcher})
 * и перехват HTML ({@see \WpMlp\Rendering\OutputBuffer}) переводят РАЗНЫЕ
 * половины одной и той же страницы: первая — строки темы и плагинов через
 * gettext, второй — весь остальной текст через DOM. Если они разойдутся в
 * условиях, посетитель получит полупереведённую страницу: кнопка
 * «Reply» на английском рядом с русским текстом статьи (или наоборот) —
 * и понять по такой странице, что именно сломалось, почти невозможно.
 *
 * Отсюда же следует ограничение на GET/HEAD. Дело не только в том, что
 * POST-ответы это обычно редиректы и обработчики форм: именно на POST
 * WordPress отправляет транзакционные письма (уведомление о комментарии,
 * например). Оставляя POST на исходной локали, мы не даём подмене языка
 * протечь в письма (ТЗ 11, хук `wp_mail`) — их язык в этой версии плагина
 * не меняется вовсе.
 */
final class FrontendRequest {

	/**
	 * Обычная публичная отрисовка страницы, а не служебный запрос.
	 *
	 * Язык здесь намеренно не проверяется: он у вызывающих разный —
	 * OutputBuffer смотрит на язык текущего запроса, LocaleSwitcher на
	 * него же, но им это нужно в разные моменты. Здесь только тип запроса.
	 */
	public static function isPublicRender(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		return self::isReadMethod();
	}

	/**
	 * Запрос только читает: GET или HEAD.
	 */
	private static function isReadMethod(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значение сравнивается со списком.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) )
			: 'GET';

		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}
}
