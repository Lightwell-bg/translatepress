<?php
/**
 * Минимальный DI-контейнер (ТЗ 4.1: «небольшой dependency container»).
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp;

use RuntimeException;

/**
 * Реестр сервисов с ленивой инициализацией.
 *
 * Сознательно примитивен: без автоварайринга и рефлексии — зависимости
 * описываются явно в Plugin::defineServices(). Это дешевле по времени
 * выполнения на каждом HTTP-запросе и проще читается.
 */
final class Container {

	/**
	 * Фабрики сервисов.
	 *
	 * @var array<string, callable(self): object>
	 */
	private array $factories = array();

	/**
	 * Уже созданные сервисы.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Регистрирует фабрику сервиса.
	 *
	 * @param string                  $id      Идентификатор (обычно FQCN).
	 * @param callable(self): object  $factory Фабрика.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Возвращает сервис, создавая его при первом обращении.
	 *
	 * @template T of object
	 * @param class-string<T>|string $id Идентификатор сервиса.
	 * @return T|object
	 *
	 * @throws RuntimeException Если сервис не зарегистрирован.
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException( sprintf( 'Сервис "%s" не зарегистрирован в контейнере.', $id ) );
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}

	/**
	 * Зарегистрирован ли сервис.
	 *
	 * @param string $id Идентификатор сервиса.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
