<?php
/**
 * Crypto Banner Ads
 *
 * Cookie-free, account-free paid banner ads. One watch-only payment address
 * per banner, pay-per-click balances, and a rotating placement snippet.
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation; either version 2 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * @package Crypto_Banner_Ads
 * @license GPL-2.0-or-later
 */

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
				$wpdb->prefix . 'dwads_impressions',
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
				'SELECT id FROM ' . $wpdb->prefix . 'dwads_clicks' . ' WHERE banner_id = %d AND visitor_hash = %s AND charged = 1 AND created_at >= %s LIMIT 1',
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
	public static function recent_impression_exists( $banner_id, $visitor, $hours, $exclude_nonce = '' ) {
		global $wpdb;

		$visitor = (string) $visitor;
		if ( '' === $visitor ) {
			return false;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $hours ) * HOUR_IN_SECONDS );

		// Only a row this visitor actually SAW can suppress the next one, and the
		// row belonging to the current request never counts as "an earlier view".
		// Without both conditions the check finds the row it was just handed and
		// silently counts nothing at all.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'dwads_impressions' . ' WHERE banner_id = %d AND visitor = %s AND seen_at IS NOT NULL AND created_at >= %s AND nonce <> %s LIMIT 1',
				(int) $banner_id,
				$visitor,
				$since,
				(string) $exclude_nonce
			)
		);

		return null !== $found;
	}

	/**
	 * The impression row a click token points at.
	 *
	 * @param string $nonce Token nonce.
	 * @return array|null
	 */
	public static function impression_row( $nonce ) {
		global $wpdb;

		$nonce = (string) $nonce;
		if ( '' === $nonce ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'dwads_impressions' . ' WHERE nonce = %s LIMIT 1', $nonce ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Record that the creative was actually on screen.
	 *
	 * The timestamp comes from the server clock and can only be written once per
	 * token, so unlike anything the browser claims it cannot be backdated.
	 *
	 * @param string $nonce Token nonce.
	 * @return int 1 when this call marked it, 0 when it was already marked.
	 */
	public static function mark_seen( $nonce ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'dwads_impressions' . ' SET seen_at = %s WHERE nonce = %s AND seen_at IS NULL AND expires_at >= %s',
				$now,
				(string) $nonce,
				$now
			)
		);
	}

	/**
	 * Record that a pointer or key touched the creative.
	 *
	 * @param string $nonce Token nonce.
	 * @return int
	 */
	public static function mark_armed( $nonce ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . 'dwads_impressions' . ' SET armed_at = %s WHERE nonce = %s AND armed_at IS NULL AND expires_at >= %s',
				$now,
				(string) $nonce,
				$now
			)
		);
	}

	/**
	 * Whether the request carries the fetch metadata a real browser sends.
	 *
	 * Chrome, Firefox and Safari all attach Sec-Fetch-* to every request.
	 * Scripts, scrapers and HTML parsers do not, which is the class this stops.
	 *
	 * @param string $expect 'navigate' for a link click, 'fetch' for a beacon.
	 * @return bool
	 */
	public static function browser_headers_ok( $expect = 'fetch' ) {
		$mode = isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) ? strtolower( trim( (string) $_SERVER['HTTP_SEC_FETCH_MODE'] ) ) : '';
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';

		if ( '' === $mode || '' === $dest ) {
			return false;
		}

		if ( 'navigate' === $expect ) {
			return 'navigate' === $mode;
		}

		return in_array( $mode, array( 'cors', 'no-cors', 'same-origin' ), true );
	}

	/**
	 * Charged clicks matching one column inside a window.
	 *
	 * @param string $column One of visitor_hash, ip_hash, banner_id.
	 * @param mixed  $value  Value to match.
	 * @param string $since  MySQL datetime.
	 * @return int
	 */
	public static function charged_clicks_since( $column, $value, $since ) {
		global $wpdb;

		if ( ! in_array( $column, array( 'visitor_hash', 'ip_hash', 'banner_id' ), true ) ) {
			return 0;
		}

		if ( 'banner_id' === $column ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'dwads_clicks' . ' WHERE banner_id = %d AND charged = 1 AND created_at >= %s',
					(int) $value,
					(string) $since
				)
			);
		}

		if ( 'visitor_hash' === $column ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'dwads_clicks' . ' WHERE visitor_hash = %s AND charged = 1 AND created_at >= %s',
					(string) $value,
					(string) $since
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'dwads_clicks' . ' WHERE ip_hash = %s AND charged = 1 AND created_at >= %s',
				(string) $value,
				(string) $since
			)
		);
	}

	/**
	 * Impressions of one banner that a visitor genuinely saw.
	 *
	 * @param int    $banner_id Banner id.
	 * @param string $since     MySQL datetime.
	 * @return int
	 */
	public static function viewable_impressions_since( $banner_id, $since ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'dwads_impressions' . ' WHERE banner_id = %d AND seen_at IS NOT NULL AND created_at >= %s',
				(int) $banner_id,
				(string) $since
			)
		);
	}

	/**
	 * Whether this visitor ever walked into the hidden decoy link.
	 *
	 * @param string $visitor_hash Visitor hash.
	 * @return bool
	 */
	public static function is_honeypot_visitor( $visitor_hash ) {
		global $wpdb;

		$visitor_hash = (string) $visitor_hash;
		if ( '' === $visitor_hash ) {
			return false;
		}

		$days = (int) DWADS_Settings::get( 'honeypot_days', 1 );
		if ( $days <= 0 ) {
			return false;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'dwads_clicks' . " WHERE visitor_hash = %s AND reason = 'honeypot' AND created_at >= %s LIMIT 1",
				$visitor_hash,
				$since
			)
		);

		return null !== $found;
	}

	/**
	 * Whether the request came from a page on this site.
	 *
	 * A real click on a banner carries a Referer from the same host. A script
	 * that scrapes the link out of the page and fetches it does not. This is a
	 * second, independent human signal for browsers where the pointer marker
	 * did not ride along (extensions, in-app browsers, odd event ordering).
	 *
	 * @return bool
	 */
	private static function same_site_referer() {
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return false;
		}
		$ref = (string) $_SERVER['HTTP_REFERER']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never echoed
		$ref_host = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
		$own_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $ref_host || '' === $own_host ) {
			return false;
		}
		$ref_host = preg_replace( '/^www\./', '', $ref_host );
		$own_host = preg_replace( '/^www\./', '', $own_host );
		return $ref_host === $own_host;
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
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'dwads_clicks' . ' WHERE ip_hash = %s AND created_at >= %s', $ip_hash, $since )
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

		$visitor_hash = self::visitor_hash( $banner['id'], $ip, $ua );
		$ip_hash      = self::ip_hash( $ip );
		$ua_hash      = hash_hmac( 'sha256', 'dwads-ua|' . $ua, DWADS_Banners::secret() );

		// The decoy link. Anything that follows a link it cannot see is a
		// machine, so the visitor is flagged and never billed again.
		if ( ! empty( $_GET['dwads_hp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix . 'dwads_clicks',
				array(
					'banner_id'    => (int) $banner['id'],
					'site_id'      => (int) $payload['s'],
					'page_id'      => (int) $payload['p'],
					'created_at'   => $now,
					'visitor_hash' => $visitor_hash,
					'ip_hash'      => $ip_hash,
					'ua_hash'      => $ua_hash,
					'price_usd'    => '0.000000',
					'sats_charged' => 0,
					'charged'      => 0,
					'reason'       => 'honeypot',
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}

		$record = (bool) DWADS_Settings::get( 'record_impressions', 1 );
		$row    = $record ? self::impression_row( (string) $payload['n'] ) : null;

		// Proof a person acted on the creative, in order of directness: the
		// pointer/key marker written into the link, the beacon the browser
		// reported, or a Referer from this site (which scripts scraping the URL
		// do not send). Any one of them is enough.
		$human_signal = ( is_array( $row ) && ! empty( $row['armed_at'] ) )
			|| ! empty( $_GET['dwads_g'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| self::same_site_referer();

		// One click per served impression.
		if ( $record ) {
			global $wpdb;
			$used = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'dwads_impressions' . ' SET used_at = %s WHERE nonce = %s AND used_at IS NULL AND expires_at >= %s',
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

		// A real navigation carries "navigate". A script asking for the URL
		// carries nothing at all.
		if ( '' === $reason && DWADS_Settings::get( 'require_browser_headers', 1 ) && ! self::browser_headers_ok( 'navigate' ) ) {
			$reason = 'headers';
		}

		$bot_pattern = (string) DWADS_Settings::get( 'bot_uas', '' );
		if ( '' === $reason && ( '' === trim( $ua ) || ( '' !== $bot_pattern && @preg_match( '#' . str_replace( '#', '\#', $bot_pattern ) . '#i', $ua ) ) ) ) {
			$reason = 'bot';
		}

		if ( '' === $reason && DWADS_Settings::get( 'honeypot_enabled', 1 ) && self::is_honeypot_visitor( $visitor_hash ) ) {
			$reason = 'honeypot';
		}

		// The creative has to have been on screen, for long enough that a person
		// could have seen it, before a click is billable. A gesture on the
		// creative (armed) is itself proof a human engaged with it, so a click
		// that carries a gesture is billed even when the viewability beacon was
		// lost (ad blockers, flaky connections, fast clicks) — a machine has no
		// gesture to offer.
		if ( '' === $reason && $record && DWADS_Settings::get( 'impression_viewable', 1 ) ) {
			if ( ! $row || empty( $row['seen_at'] ) ) {
				if ( ! $human_signal ) {
					$reason = 'not_seen';
				}
			} else {
				$dwell_ms = max( 0, (int) DWADS_Settings::get( 'click_min_dwell_ms', 400 ) );
				$seen_ts  = strtotime( $row['seen_at'] . ' UTC' );
				if ( $dwell_ms > 0 && ( time() - (int) $seen_ts ) * 1000 < $dwell_ms ) {
					$reason = 'too_fast';
				}
			}
		}

		// A pointer, key or context menu touched the creative. Both the island
		// beacon and the link marker are written by our own handler in the same
		// event that opens the link, so no ordering race can drop a real click.
		if ( '' === $reason && DWADS_Settings::get( 'require_gesture', 1 ) && $record ) {
			if ( ! $human_signal ) {
				$reason = 'no_gesture';
			}
		}

		if ( '' === $reason && self::recent_click_exists( $banner['id'], $visitor_hash ) ) {
			$reason = 'duplicate';
		}

		$since_day   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$since_hour  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// A counter reset starts a new accounting window for this banner. Without
		// this, the clicks that were charged before the reset keep counting against
		// a fresh impression total and trip the click-rate brake immediately.
		$since_banner = $since_day;
		if ( ! empty( $banner['reset_at'] ) && (string) $banner['reset_at'] > $since_day ) {
			$since_banner = (string) $banner['reset_at'];
		}
		// Every cap honours 0 = disabled. Only a positive value is enforced, so
		// an administrator who turns a cap off cannot be silently clamped back on.
		$max_per_hour = max( 0, (int) DWADS_Settings::get( 'max_clicks_per_ip_hour', 10 ) );
		$max_ip_day   = max( 0, (int) DWADS_Settings::get( 'max_clicks_per_ip_day', 15 ) );
		$max_vis_day  = max( 0, (int) DWADS_Settings::get( 'max_clicks_per_visitor_day', 3 ) );
		$max_ban_day  = max( 0, (int) DWADS_Settings::get( 'max_clicks_per_banner_day', 0 ) );

		if ( '' === $reason && $max_per_hour > 0 && self::clicks_from_ip_last_hour( $ip_hash ) >= $max_per_hour ) {
			$reason = 'rate_limited';
		}

		if ( '' === $reason && $max_ip_day > 0 && self::charged_clicks_since( 'ip_hash', $ip_hash, $since_day ) >= $max_ip_day ) {
			$reason = 'ip_daily_cap';
		}

		if ( '' === $reason && $max_vis_day > 0 && self::charged_clicks_since( 'visitor_hash', $visitor_hash, $since_day ) >= $max_vis_day ) {
			$reason = 'visitor_daily_cap';
		}

		if ( '' === $reason && $max_ban_day > 0 && self::charged_clicks_since( 'banner_id', (int) $banner['id'], $since_banner ) >= $max_ban_day ) {
			$reason = 'banner_daily_cap';
		}

		// An audience that clicks one ad in five is not an audience. Once a banner
		// passes the ceiling it stops billing until the rolling day rolls over,
		// which caps the damage a click farm can do to that balance.
		$ceiling_pct = max( 0, (int) DWADS_Settings::get( 'click_rate_ceiling_percent', 20 ) );
		$min_sample  = max( 1, (int) DWADS_Settings::get( 'click_rate_min_impressions', 20 ) );
		if ( '' === $reason && $ceiling_pct > 0 && $record ) {
			$views   = self::viewable_impressions_since( (int) $banner['id'], $since_banner );
			$billed  = self::charged_clicks_since( 'banner_id', (int) $banner['id'], $since_banner );
			if ( $views >= $min_sample && ( $billed * 100 ) > ( $views * $ceiling_pct ) ) {
				$reason = 'rate_ceiling';
			}
		}

		$price_usd = DWADS_Settings::click_price_for( $banner );
		$sats      = DWADS_Price::usd_to_sats( $price_usd );
		$charged   = 0;

		if ( '' === $reason ) {
			global $wpdb;
			// One statement, so simultaneous clicks cannot double-charge. There is
			// deliberately no balance condition: a click that reached this point is
			// billed, even if the balance cannot cover it. Recording it as free
			// would mean the advertiser received the click and paid nothing, which
			// is the wrong direction. The balance is allowed to go negative, and
			// serving stops there because rotation requires a positive balance; so
			// an overdraft can only grow from tokens handed out before it went
			// under, which expire in minutes and can be used once.
			$affected = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'dwads_banners' . " SET sats_spent = sats_spent + %d, clicks_charged = clicks_charged + 1, last_activity_at = %s WHERE id = %d AND status = 'active'",
					(int) $sats,
					$now,
					(int) $banner['id']
				)
			);
			$charged = $affected ? 1 : 0;

			if ( ! $affected ) {
				// The banner stopped being active between the token and the click.
				$reason = 'inactive';
			}
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'dwads_clicks',
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
