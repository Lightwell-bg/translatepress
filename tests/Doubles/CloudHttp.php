<?php
/**
 * Двойник HTTP для тестов клиента и провайдера LingoCanvas Cloud.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Doubles;

/**
 * Записывает запросы и отдаёт заранее заготовленные ответы по очереди.
 * Настоящая сеть не используется: `wp_remote_request()` в stubs.php вызывает `handle()`.
 */
final class CloudHttp {

	/**
	 * Записанные запросы.
	 *
	 * @var list<array{url: string, args: array<string, mixed>, body: mixed}>
	 */
	public static array $requests = array();

	/**
	 * Очередь ответов: массив ответа WordPress, WP_Error или колбэк.
	 *
	 * @var list<mixed>
	 */
	public static array $queue = array();

	/**
	 * Сбрасывает состояние перед тестом.
	 */
	public static function reset(): void {
		self::$requests = array();
		self::$queue    = array();
	}

	/**
	 * Ответ API с JSON-телом.
	 *
	 * @param int                  $status  HTTP-статус.
	 * @param array<string, mixed> $body    Тело.
	 * @param array<string, string> $headers Заголовки.
	 * @return array<string, mixed>
	 */
	public static function json( int $status, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( $body ),
			'headers'  => $headers,
		);
	}

	/**
	 * Ответ-ошибка API в формате `{"error":{"code"}}`.
	 *
	 * @param int                  $status  HTTP-статус.
	 * @param string               $code    Код ошибки.
	 * @param array<string, string> $headers Заголовки.
	 * @return array<string, mixed>
	 */
	public static function error( int $status, string $code, array $headers = array() ): array {
		return self::json( $status, array( 'error' => array( 'code' => $code, 'message' => 'x' ) ), $headers );
	}

	/**
	 * Обработчик одного запроса.
	 *
	 * @param string               $url  Адрес.
	 * @param array<string, mixed> $args Параметры.
	 * @return mixed
	 */
	public static function handle( string $url, array $args ) {
		$body = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;

		self::$requests[] = array(
			'url'  => $url,
			'args' => $args,
			'body' => $body,
		);

		if ( array() === self::$queue ) {
			throw new \LogicException( 'CloudHttp: очередь ответов пуста, лишний запрос ' . $url );
		}

		$next = array_shift( self::$queue );

		return is_callable( $next ) ? $next( $url, $args, $body ) : $next;
	}
}
