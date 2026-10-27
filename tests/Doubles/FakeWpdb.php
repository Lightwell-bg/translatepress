<?php
/**
 * Подделка $wpdb для тестов, которым нужны prepare() и выборки.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Tests\Doubles;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Подставляет параметры в prepare() и запоминает каждый запрос. Результаты
 * выборок задаёт тест: `$cols`, `$rows`, `$var`.
 */
final class FakeWpdb {

	public string $prefix = 'wp_';

	public string $posts = 'wp_posts';

	public string $postmeta = 'wp_postmeta';

	public string $comments = 'wp_comments';

	/**
	 * Очередь ответов get_col(): каждый вызов забирает первый элемент; когда очередь пуста, отдаётся `$cols`.
	 *
	 * @var list<list<mixed>>
	 */
	public array $colsQueue = array();

	/** @var list<string> */
	public array $queries = array();

	/** @var list<mixed> */
	public array $cols = array();

	/** @var list<array<string, mixed>> */
	public array $rows = array();

	/** @var mixed */
	public $var = null;

	/** @var array<string, mixed>|null Ответ get_row(). */
	public ?array $row = null;

	public string $options = 'wp_options';

	/**
	 * Колбэк, который вызывается перед обработкой каждого query(): им тест вклинивается между шагами
	 * (имитация гонки). Получает SQL.
	 *
	 * @var callable|null
	 */
	public $beforeQuery = null;

	/**
	 * Выполняет условные UPDATE/DELETE опций (замок опроса Cloud) над хранилищем опций тестов.
	 *
	 * Понимает ровно два шаблона, которые использует плагин:
	 * `UPDATE <t> SET option_value = 'new' WHERE option_name = 'n' AND option_value = 'old'` и
	 * `DELETE FROM <t> WHERE option_name = 'n' AND option_value = 'old'`.
	 *
	 * @return int|false Число затронутых строк.
	 */
	public function query( string $sql ) {
		if ( null !== $this->beforeQuery ) {
			( $this->beforeQuery )( $sql );
		}

		$this->queries[] = $sql;

		$options = lingocanvas_test_options();

		if ( 1 === preg_match( "/^UPDATE \\S+ SET option_value = '(.*)' WHERE option_name = '(.*)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
			if ( array_key_exists( $m[2], $options ) && (string) $options[ $m[2] ] === $m[3] ) {
				$options[ $m[2] ] = $m[1];
				lingocanvas_test_options( $options, true );

				return 1;
			}

			return 0;
		}

		if ( 1 === preg_match( "/^DELETE FROM \\S+ WHERE option_name = '(.*)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $options ) && (string) $options[ $m[1] ] === $m[2] ) {
				unset( $options[ $m[1] ] );
				lingocanvas_test_options( $options, true );

				return 1;
			}

			return 0;
		}

		return 0;
	}

	/**
	 * @param string $sql  Запрос с %s/%d.
	 * @param mixed  ...$args Параметры (или один массив).
	 */
	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdi]/',
			static function ( array $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';

				if ( '%i' === $m[0] ) {
					// Идентификатор (WP 6.2+): в тестах подставляется как есть, без обратных кавычек,
					// чтобы проверки вида `FROM wp_lingocanvas_sources` не зависели от экранирования.
					return (string) $value;
				}

				return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
			},
			$sql
		);
	}

	/** @return list<mixed> */
	public function get_col( string $sql ): array {
		$this->queries[] = $sql;

		if ( array() !== $this->colsQueue ) {
			return array_shift( $this->colsQueue );
		}

		return $this->cols;
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $sql, $output = null ): array {
		unset( $output );
		$this->queries[] = $sql;

		return $this->rows;
	}

	/** @return array<string, mixed>|null */
	public function get_row( string $sql, $output = null ): ?array {
		unset( $output );
		$this->queries[] = $sql;

		return $this->row;
	}

	/** @return mixed */
	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		return $this->var;
	}
}
