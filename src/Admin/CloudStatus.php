<?php
/**
 * Состояние аккаунта LingoCanvas Cloud для показа на странице настроек.
 *
 * @package LingoCanvas
 */

declare(strict_types=1);

namespace LingoCanvas\Admin;

use LingoCanvas\Translation\CloudException;
use LingoCanvas\Translation\ProviderFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Читает `GET /v1/account`, кладёт в transient на 60 секунд и превращает ответ
 * в готовые к показу строки. В результате нет токена и вообще ничего секретного:
 * только подписи для владельца сайта.
 */
final class CloudStatus {

	/** Сколько секунд хранится ответ аккаунта. */
	public const CACHE_TTL = 60;

	/** Статусы подписки Stripe, при которых подписка считается действующей. */
	private const LIVE_STATUSES = array( 'active', 'trialing' );

	/**
	 * @param ProviderFactory $providers Токен и адрес API.
	 */
	public function __construct( private readonly ProviderFactory $providers ) {
	}

	/**
	 * Имя transient: на сайт и на токен (хеш токена, не сам токен).
	 */
	public function cacheKey(): string {
		return 'lingocanvas_cloud_status_' . substr( hash( 'sha256', home_url() . '|' . $this->providers->cloudToken() ), 0, 32 );
	}

	/**
	 * Сбрасывает кэш состояния.
	 */
	public function forget(): void {
		delete_transient( $this->cacheKey() );
	}

	/**
	 * Состояние для показа.
	 *
	 * @param bool $refresh Пропустить кэш.
	 * @return array<string, mixed>
	 * @throws CloudException При ошибке API, сети или если сайт не подключён.
	 */
	public function get( bool $refresh = false ): array {
		if ( '' === $this->providers->cloudToken() ) {
			throw new CloudException( 'invalid_token', 401 );
		}

		// Без согласия владельца к Cloud не обращаемся, даже если токен задан константой.
		if ( ! $this->providers->isCloudConnected() ) {
			throw new CloudException( 'consent_required', 0 );
		}

		$key    = $this->cacheKey();
		$cached = $refresh ? false : get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$display = self::format( $this->providers->cloudClient()->account(), time() );

		set_transient( $key, $display, self::CACHE_TTL );

		return $display;
	}

	/**
	 * Превращает ответ `GET /v1/account` в подписи для интерфейса. Токена в ответе нет.
	 *
	 * @param array<string, mixed> $data Ответ API.
	 * @param int                  $now  Текущее время (Unix), для проверки срока подписки.
	 * @return array{plan: string, subscription: string, renewal: string, monthly_left: string, packs_left: string, packs_note: string, sites: string, live: bool}
	 */
	public static function format( array $data, int $now ): array {
		$account = is_array( $data['account'] ?? null ) ? $data['account'] : array();
		$quota   = is_array( $data['quota'] ?? null ) ? $data['quota'] : array();
		$sites   = is_array( $data['sites'] ?? null ) ? $data['sites'] : array();
		$sub     = is_array( $data['subscription'] ?? null ) ? $data['subscription'] : null;

		$status    = null === $sub ? '' : strtolower( (string) ( $sub['status'] ?? '' ) );
		$periodEnd = null === $sub ? 0 : (int) strtotime( (string) ( $sub['current_period_end'] ?? '' ) );
		$cancel    = null !== $sub && ! empty( $sub['cancel_at_period_end'] );
		$live      = in_array( $status, self::LIVE_STATUSES, true ) && ( $periodEnd <= 0 || $periodEnd > $now );

		$packs  = max( 0, (int) ( $quota['packs_remaining'] ?? 0 ) );
		$usable = ! array_key_exists( 'packs_usable', $quota ) || ! empty( $quota['packs_usable'] );

		return array(
			'plan'         => self::planLabel( $account['plan'] ?? null ),
			'subscription' => self::statusLabel( $status ),
			'renewal'      => self::renewalLabel( $periodEnd, $status, $cancel ),
			'monthly_left' => number_format_i18n( max( 0, (int) ( $quota['monthly_remaining'] ?? 0 ) ) ),
			'packs_left'   => number_format_i18n( $packs ),
			'packs_note'   => ( ! $usable && $packs > 0 )
				? __( 'Extra packs can be used only while the subscription is active.', 'lingocanvas' )
				: '',
			'sites'        => self::sitesLabel( (int) ( $sites['used'] ?? 0 ), isset( $sites['max'] ) ? (int) $sites['max'] : null ),
			'live'         => $live,
		);
	}

	/**
	 * Название тарифа.
	 *
	 * @param mixed $plan Код тарифа или null.
	 */
	private static function planLabel( mixed $plan ): string {
		if ( ! is_string( $plan ) || '' === $plan ) {
			return __( 'No plan', 'lingocanvas' );
		}

		if ( 'starter' === strtolower( $plan ) ) {
			return __( 'Starter', 'lingocanvas' );
		}

		return ucfirst( sanitize_text_field( $plan ) );
	}

	/**
	 * Человеческое название статуса подписки Stripe.
	 *
	 * @param string $status Статус или пустая строка, если подписки нет.
	 */
	private static function statusLabel( string $status ): string {
		switch ( $status ) {
			case '':
				return __( 'No subscription', 'lingocanvas' );

			case 'active':
				return __( 'Active', 'lingocanvas' );

			case 'trialing':
				return __( 'Trial period', 'lingocanvas' );

			case 'past_due':
				return __( 'Payment overdue', 'lingocanvas' );

			case 'unpaid':
				return __( 'Unpaid', 'lingocanvas' );

			case 'canceled':
				return __( 'Canceled', 'lingocanvas' );

			case 'incomplete':
			case 'incomplete_expired':
				return __( 'Not completed', 'lingocanvas' );

			case 'paused':
				return __( 'Paused', 'lingocanvas' );

			default:
				return __( 'Unknown', 'lingocanvas' );
		}
	}

	/**
	 * Дата продления или окончания в часовом поясе сайта.
	 *
	 * @param int    $periodEnd Конец оплаченного периода (Unix) или 0.
	 * @param string $status    Статус подписки.
	 * @param bool   $cancel    Подписка отменена и закончится в конце периода.
	 */
	private static function renewalLabel( int $periodEnd, string $status, bool $cancel ): string {
		if ( $periodEnd <= 0 ) {
			return '';
		}

		$format = (string) get_option( 'date_format', '' );
		$format = '' !== $format ? $format : 'F j, Y';
		$date   = wp_date( $format, $periodEnd );
		$date   = is_string( $date ) ? $date : gmdate( $format, $periodEnd );

		if ( 'canceled' === $status ) {
			/* translators: %s: date. */
			return sprintf( __( 'Ended on %s', 'lingocanvas' ), $date );
		}

		if ( $cancel ) {
			/* translators: %s: date. */
			return sprintf( __( 'Ends on %s', 'lingocanvas' ), $date );
		}

		/* translators: %s: date. */
		return sprintf( __( 'Renews on %s', 'lingocanvas' ), $date );
	}

	/**
	 * Сколько сайтов подключено и сколько разрешено.
	 *
	 * @param int      $used Подключено.
	 * @param int|null $max  Максимум или null, если тарифа нет.
	 */
	private static function sitesLabel( int $used, ?int $max ): string {
		if ( null === $max ) {
			return number_format_i18n( $used );
		}

		return sprintf(
			/* translators: 1: number of connected sites, 2: maximum number of sites. */
			__( '%1$s of %2$s', 'lingocanvas' ),
			number_format_i18n( $used ),
			number_format_i18n( $max )
		);
	}
}
