<?php
/**
 * Объектный кэш переводов.
 *
 * @package WpMlp
 */

declare(strict_types=1);

namespace WpMlp\Storage;

/**
 * Кэш «строка → перевод» поверх wp_cache (ТЗ 12.1).
 *
 * Ключ собирается из языка и хеша строки. Инвалидация точечная не нужна и
 * ненадёжна: вместо неё в ключ входит номер версии, который увеличивается
 * при любом сохранении перевода — все старые ключи разом перестают
 * использоваться.
 *
 * Без постоянного объектного кэша (Redis, Memcached) кэш живёт в пределах
 * одного запроса и всё равно полезен: одинаковые строки на странице
 * запрашиваются один раз.
 */
final class TranslationCache {

	public const GROUP = 'mlp_translations';

	public const OPTION_VERSION = 'mlp_cache_version';

	/**
	 * Отсутствие перевода тоже кэшируется — иначе каждая непереведённая
	 * строка ходила бы в БД на каждый показ страницы.
	 */
	private const MISS = '__mlp_miss__';

	/**
	 * Время жизни записи.
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Текущая версия кэша.
	 */
	private ?int $version = null;

	/**
	 * Читает пачку записей.
	 *
	 * @param list<string> $hashes Hex-хеши строк.
	 * @param string       $locale Целевой язык.
	 * @return array{hits: array<string, array{id: int, text: ?string, status: ?string}|null>, misses: list<string>}
	 */
	public function getMany( array $hashes, string $locale ): array {
		if ( array() === $hashes ) {
			return array(
				'hits'   => array(),
				'misses' => array(),
			);
		}

		$keys = array();

		foreach ( $hashes as $hash ) {
			$keys[ $this->key( $hash, $locale ) ] = $hash;
		}

		$cached = wp_cache_get_multiple( array_keys( $keys ), self::GROUP );

		$hits   = array();
		$misses = array();

		foreach ( $keys as $key => $hash ) {
			$value = $cached[ $key ] ?? false;

			if ( false === $value ) {
				$misses[] = $hash;

				continue;
			}

			$hits[ $hash ] = self::MISS === $value ? null : $value;
		}

		return array(
			'hits'   => $hits,
			'misses' => $misses,
		);
	}

	/**
	 * Записывает результаты выборки, включая отрицательные.
	 *
	 * @param array<string, array{id: int, text: ?string, status: ?string}> $found     Найденное в БД.
	 * @param list<string>                                                  $requested Все запрошенные хеши.
	 * @param string                                                        $locale    Целевой язык.
	 */
	public function setMany( array $found, array $requested, string $locale ): void {
		foreach ( $requested as $hash ) {
			wp_cache_set(
				$this->key( $hash, $locale ),
				$found[ $hash ] ?? self::MISS,
				self::GROUP,
				self::TTL
			);
		}
	}

	/**
	 * Обесценивает весь кэш переводов.
	 */
	public function flush(): void {
		$version = $this->currentVersion() + 1;

		update_option( self::OPTION_VERSION, $version, true );
		$this->version = $version;
	}

	/**
	 * Ключ записи.
	 *
	 * @param string $hash   Hex-хеш строки.
	 * @param string $locale Целевой язык.
	 */
	private function key( string $hash, string $locale ): string {
		return $this->currentVersion() . ':' . $locale . ':' . $hash;
	}

	/**
	 * Номер версии кэша.
	 */
	private function currentVersion(): int {
		if ( null === $this->version ) {
			$this->version = max( 1, (int) get_option( self::OPTION_VERSION, 1 ) );
		}

		return $this->version;
	}
}
