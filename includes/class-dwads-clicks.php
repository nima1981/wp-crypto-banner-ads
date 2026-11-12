<?php
/**
 * Cookie-free click tokens, de-duplication and charging.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Click accounting.
 */
final class DWADS_Clicks {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dwads_clicks';
	}

	/**
	 * Table name for impressions.
	 *
	 * @return string
	 */
	public static function impressions_table() {
		global $wpdb;
		return $wpdb->prefix . 'dwads_impressions';
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private static function b64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $text Encoded text.
	 * @return string
	 */
	private static function unb64url( $text ) {
		$text = strtr( (string) $text, '-_', '+/' );
		$pad  = strlen( $text ) % 4;
		if ( $pad ) {
			$text .= str_repeat( '=', 4 - $pad );
		}
		$raw = base64_decode( $text, true );
		return false === $raw ? '' : $raw;
	}

	/**
	 * Sign a payload string.
	 *
	 * @param string $body Payload.
	 * @return string
	 */
	private static function sign( $body ) {
		return self::b64url( hash_hmac( 'sha256', $body, DWADS_Banners::secret(), true ) );
	}

	/**
	 * Create an impression record and return a signed click token.
	 *
	 * @param array $banner  Banner row.
	 * @param int   $site_id Publisher site id.
	 * @param int   $page_id Page id.
	 * @return string Click token.
	 */
	public static function begin_impression( $banner, $site_id = 0, $page_id = 0, $visitor = '' ) {
		global $wpdb;

		$nonce = bin2hex( random_bytes( 8 ) );
		$ttl   = max( 5, (int) DWADS_Settings::get( 'impression_ttl_minutes', 30 ) );
		$now   = time();

		if ( DWADS_Settings::get( 'record_impressions', 1 ) ) {
			$wpdb->insert(
				self::impressions_table(),
				array(
					'nonce'      => $nonce,
					'banner_id'  => (int) $banner['id'],
					'site_id'    => (int) $site_id,
					'page_id'    => (int) $page_id,
					'visitor'    => (string) $visitor,
					'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
					'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl * MINUTE_IN_SECONDS ),
				),
				array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}

		$payload = array(
			'v' => 1,
			'b' => (int) $banner['id'],
			's' => (int) $site_id,
			'p' => (int) $page_id,
			'n' => $nonce,
			'e' => $now + $ttl * MINUTE_IN_SECONDS,
		);

		$body = self::b64url( wp_json_encode( $payload ) );
		return $body . '.' . self::sign( $body );
	}

	/**
	 * Verify and decode a click token.
	 *
	 * @param string $token Token.
	 * @return array|null Payload, or null when invalid or expired.
	 */
	public static function parse_token( $token ) {
		$token = (string) $token;
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		list( $body, $sig ) = $parts;
		if ( ! hash_equals( self::sign( $body ), $sig ) ) {
			return null;
		}
		$payload = json_decode( self::unb64url( $body ), true );
		if ( ! is_array( $payload ) || empty( $payload['b'] ) || empty( $payload['e'] ) ) {
			return null;
		}
		if ( (int) $payload['e'] < time() ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Best-effort client address. Deliberately only REMOTE_ADDR so a forged
	 * header cannot reset the daily de-duplication.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = array();

		// Behind Cloudflare every request arrives from a rotating edge address,
		// which would make the de-duplication useless: each click would look like
		// a brand new visitor. Cloudflare sets this header for us.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		// A forwarded chain is only trusted when the direct peer is a private or
		// reserved address, i.e. a proxy on this machine or network.
		if ( '' !== $remote && empty( filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts        = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$candidates[] = trim( (string) $parts[0] );
		}

		$candidates[] = $remote;

		foreach ( $candidates as $ip ) {
			$ip = trim( (string) $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Hashed IP address, keyed by the install secret.
	 *
	 * @param string|null $ip Address.
	 * @return string
	 */
	public static function ip_hash( $ip = null ) {
		$ip = null === $ip ? self::client_ip() : (string) $ip;
		return hash_hmac( 'sha256', 'dwads-ip|' . $ip, DWADS_Banners::secret() );
	}

	/**
	 * Daily-rotating visitor fingerprint. No cookie, no readable address.
	 *
	 * @param int    $banner_id Banner id.
	 * @param string $ip        Address.
	 * @param string $ua        User agent.
	 * @return string
	 */
	public static function visitor_hash( $banner_id, $ip, $ua ) {
		// The day component makes the value useless for long-term tracking and
		// resets the window without storing any salt.
		$day = gmdate( 'Y-m-d' );
		return hash_hmac( 'sha256', 'dwads-visitor|' . (int) $banner_id . '|' . $ip . '|' . $ua . '|' . $day, DWADS_Banners::secret() );
	}

	/**
	 * Whether this visitor already paid for this banner inside the window.
	 *
	 * @param int    $banner_id    Banner id.
	 * @param string $visitor_hash Visitor hash.
	 * @return bool
	 */
	public static function recent_click_exists( $banner_id, $visitor_hash ) {
		global $wpdb;
		$hours = max( 1, (int) DWADS_Settings::get( 'dedupe_hours', 24 ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE banner_id = %d AND visitor_hash = %s AND charged = 1 AND created_at >= %s LIMIT 1',
				(int) $banner_id,
				$visitor_hash,
				$since
			)
		);
		return null !== $found;
	}

	/**
	 * Whether this visitor has already been counted for this banner lately.
	 *
	 * Keeps the impression counter close to "people who saw it" rather than
	 * "requests we answered", so a reload or a crawler cannot inflate it.
	 *
	 * @param int    $banner_id Banner id.
	 * @param string $visitor   Visitor hash.
	 * @param int    $hours     Window in hours.
	 * @return bool
	 */
	public static function recent_impression_exists( $banner_id, $visitor, $hours ) {
		global $wpdb;

		$visitor = (string) $visitor;
		if ( '' === $visitor ) {
			return false;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $hours ) * HOUR_IN_SECONDS );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::impressions_table() . ' WHERE banner_id = %d AND visitor = %s AND created_at >= %s LIMIT 1',
				(int) $banner_id,
				$visitor,
				$since
			)
		);

		return null !== $found;
	}

	/**
	 * Clicks seen from one hashed address in the last hour.
	 *
	 * @param string $ip_hash Hashed address.
	 * @return int
	 */
	public static function clicks_from_ip_last_hour( $ip_hash ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ip_hash = %s AND created_at >= %s', $ip_hash, $since )
		);
	}

	/**
	 * Handle a click on /go/{token}. Charges, records, then redirects.
	 *
	 * @param string $token Token from the URL.
	 * @return void
	 */
	public static function handle_click( $token ) {
		$payload = self::parse_token( $token );
		$banner  = $payload ? DWADS_Banners::get( (int) $payload['b'] ) : null;

		if ( ! $banner || 'active' !== $banner['status'] || empty( $banner['target_url'] ) ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}

		$target = $banner['target_url'];
		$reason = '';
		$now    = gmdate( 'Y-m-d H:i:s' );
		$ip     = self::client_ip();
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 400 ) : '';

		// One click per served impression.
		if ( DWADS_Settings::get( 'record_impressions', 1 ) ) {
			global $wpdb;
			$used = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::impressions_table() . ' SET used_at = %s WHERE nonce = %s AND used_at IS NULL AND expires_at >= %s',
					$now,
					(string) $payload['n'],
					$now
				)
			);
			if ( ! $used ) {
				$reason = 'replay';
			}
		}

		if ( '' === $reason && 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
			$reason = 'method';
		}

		$bot_pattern = (string) DWADS_Settings::get( 'bot_uas', '' );
		if ( '' === $reason && ( '' === trim( $ua ) || ( '' !== $bot_pattern && @preg_match( '#' . str_replace( '#', '\#', $bot_pattern ) . '#i', $ua ) ) ) ) {
			$reason = 'bot';
		}

		$visitor_hash = self::visitor_hash( $banner['id'], $ip, $ua );
		$ip_hash      = self::ip_hash( $ip );
		$ua_hash      = hash_hmac( 'sha256', 'dwads-ua|' . $ua, DWADS_Banners::secret() );

		if ( '' === $reason && self::recent_click_exists( $banner['id'], $visitor_hash ) ) {
			$reason = 'duplicate';
		}

		$max_per_hour = max( 1, (int) DWADS_Settings::get( 'max_clicks_per_ip_hour', 40 ) );
		if ( '' === $reason && self::clicks_from_ip_last_hour( $ip_hash ) >= $max_per_hour ) {
			$reason = 'rate_limited';
		}

		$price_usd = DWADS_Settings::click_price_for( $banner );
		$sats      = DWADS_Price::usd_to_sats( $price_usd );
		$charged   = 0;

		if ( '' === $reason ) {
			global $wpdb;
			// Single statement: the balance can never be pushed below zero, even
			// with simultaneous clicks.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . DWADS_Banners::table() . " SET sats_spent = sats_spent + %d, clicks_charged = clicks_charged + 1, last_activity_at = %s WHERE id = %d AND status = 'active' AND ( sats_received - sats_spent ) >= %d",
					(int) $sats,
					$now,
					(int) $banner['id'],
					(int) $sats
				)
			);
			if ( $affected ) {
				$charged = 1;
			} else {
				$reason = 'insufficient_balance';
			}
		}

		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'banner_id'    => (int) $banner['id'],
				'site_id'      => (int) $payload['s'],
				'page_id'      => (int) $payload['p'],
				'created_at'   => $now,
				'visitor_hash' => $visitor_hash,
				'ip_hash'      => $ip_hash,
				'ua_hash'      => $ua_hash,
				'price_usd'    => number_format( (float) $price_usd, 6, '.', '' ),
				'sats_charged' => $charged ? (int) $sats : 0,
				'charged'      => $charged,
				'reason'       => $reason,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		wp_redirect( esc_url_raw( $target ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}
}
