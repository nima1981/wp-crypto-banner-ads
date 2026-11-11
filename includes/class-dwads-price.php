<?php
/**
 * BTC/USD price lookup with caching.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Price helper. Independent of any other plugin.
 */
final class DWADS_Price {

	const TRANSIENT = 'dwads_btc_usd';

	/** @var float|null */
	private static $memo = null;

	/**
	 * BTC price in USD.
	 *
	 * @param bool $force Skip the cache.
	 * @return float 0.0 when no price is available.
	 */
	public static function btc_usd( $force = false ) {
		if ( ! $force && null !== self::$memo ) {
			return self::$memo;
		}

		$cached = get_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) && ! empty( $cached['usd'] ) ) {
			self::$memo = (float) $cached['usd'];
			return self::$memo;
		}

		$url      = (string) DWADS_Settings::get( 'price_api', 'https://mempool.space/api/v1/prices' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => 'Crypto-Banner-Ads/' . DWADS_VERSION . '; ' . home_url( '/' ),
					'Accept'     => 'application/json',
				),
			)
		);

		$usd = 0.0;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['USD'] ) ) {
				$usd = (float) $body['USD'];
			}
		}

		$ttl = 60;
		if ( $usd <= 0 ) {
			$fallback = (float) DWADS_Settings::get( 'fallback_btc_price_usd', 0 );
			if ( $fallback > 0 ) {
				$usd = $fallback;
				DWADS_Log::add( 'Price API unavailable, using the manual fallback price.' );
			}
		} else {
			$ttl = max( 1, (int) DWADS_Settings::get( 'price_cache_minutes', 15 ) ) * MINUTE_IN_SECONDS;
		}

		if ( $usd > 0 ) {
			set_transient( self::TRANSIENT, array( 'usd' => $usd, 'at' => time() ), $ttl );
			self::$memo = $usd;
		}

		return $usd;
	}

	/**
	 * When the cached price was stored.
	 *
	 * @return int Unix timestamp or 0.
	 */
	public static function cached_at() {
		$cached = get_transient( self::TRANSIENT );
		return ( is_array( $cached ) && ! empty( $cached['at'] ) ) ? (int) $cached['at'] : 0;
	}

	/**
	 * Convert a dollar amount into satoshis, never returning less than one.
	 *
	 * @param float $usd Dollars.
	 * @return int
	 */
	public static function usd_to_sats( $usd ) {
		$usd = max( 0.0, (float) $usd );
		$btc = self::btc_usd();
		if ( $btc <= 0 ) {
			// Without a price the safest behaviour is to charge the minimum
			// rather than nothing at all.
			return 1;
		}
		return max( 1, (int) ceil( $usd / $btc * 100000000 ) );
	}

	/**
	 * Convert satoshis to a dollar value.
	 *
	 * @param int $sats Satoshis.
	 * @return float
	 */
	public static function sats_to_usd( $sats ) {
		$btc = self::btc_usd();
		if ( $btc <= 0 ) {
			return 0.0;
		}
		return ( (int) $sats / 100000000 ) * $btc;
	}

	/**
	 * Format satoshis as BTC.
	 *
	 * @param int $sats Satoshis.
	 * @return string
	 */
	public static function format_btc( $sats ) {
		return rtrim( rtrim( number_format( (int) $sats / 100000000, 8, '.', '' ), '0' ), '.' ) . ' BTC';
	}
}
