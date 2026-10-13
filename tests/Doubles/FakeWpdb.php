<?php
/**
 * Подделка $wpdb для тестов, которым нужны prepare() и выборки.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Tests\Doubles;

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
			'/%[sd]/',
			static function ( array $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ] ?? '';

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
