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
 * Payment detection.
 *
 * Policy: a payment counts as soon as it is visible in the mempool. If it never
 * confirms and disappears from the network, its credit is removed again on a
 * later poll.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * mempool.space polling.
 */
final class DWADS_Payments {

	const API_BASE = 'https://mempool.space/api';

	/** @var string Option holding the "stop polling until" timestamp. */
	const PAUSE_OPTION = 'dwads_poll_pause_until';

	/**
	 * Register schedules and hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'dwads_cron_poll', array( __CLASS__, 'poll_due' ) );
	}

	/**
	 * Custom cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['dwads_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (Crypto Banner Ads)', 'dwads' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the polling event.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'dwads_cron_poll' ) ) {
			wp_schedule_event( time() + 180, 'dwads_five_minutes', 'dwads_cron_poll' );
		}
	}

	/**
	 * Whether polling is paused after a rate limit.
	 *
	 * @return int Seconds remaining, 0 when not paused.
	 */
	public static function paused_for() {
		$until = (int) get_option( self::PAUSE_OPTION, 0 );
		return max( 0, $until - time() );
	}

	/**
	 * Poll every banner that deserves it.
	 *
	 * @param int  $limit Maximum banners.
	 * @param bool $force Ignore the recent-activity filter (admin "check now").
	 * @return array Summary.
	 */
	public static function poll_due( $limit = 25, $force = false ) {
		global $wpdb;

		$summary = array(
			'checked'  => 0,
			'credited' => 0,
			'errors'   => array(),
			'paused'   => self::paused_for(),
		);

		if ( $summary['paused'] > 0 && ! $force ) {
			return $summary;
		}

		if ( $force ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . DWADS_Banners::table() . " WHERE status IN ('pending','active','paused') AND address <> '' ORDER BY last_poll_at ASC LIMIT %d",
					(int) $limit
				),
				ARRAY_A
			);
		} else {
			$rows = DWADS_Banners::poll_candidates( $limit );
		}

		foreach ( (array) $rows as $banner ) {
			$before = (int) $banner['sats_received'];
			$result = self::poll_banner( $banner );
			$summary['checked']++;
			if ( is_wp_error( $result ) ) {
				$summary['errors'][] = '#' . $banner['id'] . ': ' . $result->get_error_message();
				if ( self::paused_for() > 0 ) {
					$summary['paused'] = self::paused_for();
					break;
				}
			} else {
				$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT sats_received FROM ' . DWADS_Banners::table() . ' WHERE id = %d', (int) $banner['id'] ) );
				if ( $after !== $before ) {
					$summary['credited']++;
				}
			}
			usleep( 300000 );
		}

		$summary['at'] = time();
		update_option( 'dwads_last_poll', $summary, 'no' );
		return $summary;
	}

	/**
	 * One API GET.
	 *
	 * @param string $path Path after /api.
	 * @return array|WP_Error
	 */
	private static function api( $path ) {
		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout' => 20,
				'headers' => array(
					'User-Agent' => 'Crypto-Banner-Ads/' . DWADS_VERSION . '; ' . home_url( '/' ),
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'dwads_api', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || $code >= 500 ) {
			update_option( self::PAUSE_OPTION, time() + 10 * MINUTE_IN_SECONDS, 'no' );
			DWADS_Log::add( sprintf( 'mempool.space returned %d; polling paused for 10 minutes.', $code ) );
			return new WP_Error( 'dwads_api_busy', sprintf( 'mempool.space returned HTTP %d.', $code ) );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'dwads_api_status', sprintf( 'mempool.space returned HTTP %d.', $code ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'dwads_api_body', 'mempool.space returned an unexpected body.' );
		}
		return $body;
	}

	/**
	 * Public summary of an address, used by the diagnostics screen.
	 *
	 * @param string $address Bitcoin address.
	 * @return array|WP_Error
	 */
	public static function address_summary( $address ) {
		$address = trim( (string) $address );
		if ( '' === $address ) {
			return new WP_Error( 'dwads_no_address', __( 'No address to check.', 'dwads' ) );
		}
		$data = self::api( '/address/' . rawurlencode( $address ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$chain = isset( $data['chain_stats'] ) && is_array( $data['chain_stats'] ) ? $data['chain_stats'] : array();
		$pool  = isset( $data['mempool_stats'] ) && is_array( $data['mempool_stats'] ) ? $data['mempool_stats'] : array();

		return array(
			'address'      => $address,
			'tx_count'     => (int) ( $chain['tx_count'] ?? 0 ) + (int) ( $pool['tx_count'] ?? 0 ),
			'funded_sats'  => (int) ( $chain['funded_txo_sum'] ?? 0 ) + (int) ( $pool['funded_txo_sum'] ?? 0 ),
		);
	}

	/**
	 * Poll a single banner address.
	 *
	 * @param array $banner Banner row.
	 * @return true|WP_Error
	 */
	public static function poll_banner( $banner ) {
		$address = (string) $banner['address'];
		if ( '' === $address ) {
			return new WP_Error( 'dwads_no_address', 'Banner has no address.' );
		}

		$mempool = self::api( '/address/' . rawurlencode( $address ) . '/txs/mempool' );
		if ( is_wp_error( $mempool ) ) {
			return $mempool;
		}

		$confirmed = array();
		$next      = '';
		for ( $page = 0; $page < 6; $page++ ) {
			$path  = '/address/' . rawurlencode( $address ) . '/txs/chain';
			if ( '' !== $next ) {
				$path .= '/' . rawurlencode( $next );
			}
			$batch = self::api( $path );
			if ( is_wp_error( $batch ) ) {
				break; // A partial read is still useful; the next poll fills gaps.
			}
			if ( ! $batch ) {
				break;
			}
			$confirmed = array_merge( $confirmed, $batch );
			if ( count( $batch ) < 25 ) {
				break;
			}
			$last = end( $batch );
			$next = isset( $last['txid'] ) ? (string) $last['txid'] : '';
			if ( '' === $next ) {
				break;
			}
			usleep( 250000 );
		}

		$outputs = array_merge(
			self::extract_outputs( $mempool, $address, false ),
			self::extract_outputs( $confirmed, $address, true )
		);

		self::record_outputs( $banner, $outputs );
		return true;
	}

	/**
	 * Pull every output paying the address out of a transaction list.
	 *
	 * @param array  $txs       Transactions.
	 * @param string $address   Address.
	 * @param bool   $confirmed Whether these are confirmed.
	 * @return array
	 */
	private static function extract_outputs( $txs, $address, $confirmed ) {
		$out = array();
		foreach ( (array) $txs as $tx ) {
			if ( empty( $tx['txid'] ) || empty( $tx['vout'] ) ) {
				continue;
			}
			$status       = isset( $tx['status'] ) && is_array( $tx['status'] ) ? $tx['status'] : array();
			$is_confirmed = $confirmed || ! empty( $status['confirmed'] );
			$block_height = isset( $status['block_height'] ) ? (int) $status['block_height'] : null;

			foreach ( (array) $tx['vout'] as $index => $vout ) {
				if ( ! isset( $vout['scriptpubkey_address'] ) || $vout['scriptpubkey_address'] !== $address ) {
					continue;
				}
				$out[] = array(
					'txid'         => (string) $tx['txid'],
					'vout'         => isset( $vout['n'] ) ? (int) $vout['n'] : (int) $index,
					'sats'         => isset( $vout['value'] ) ? (int) $vout['value'] : 0,
					'confirmed'    => $is_confirmed,
					'block_height' => $block_height,
				);
			}
		}
		return $out;
	}

	/**
	 * Apply observed outputs to the ledger.
	 *
	 * @param array $banner  Banner row.
	 * @param array $outputs Outputs.
	 * @return void
	 */
	private static function record_outputs( $banner, $outputs ) {
		global $wpdb;

		$table          = $wpdb->prefix . 'dwads_payments';
		$now            = gmdate( 'Y-m-d H:i:s' );
		$credit_mempool = (bool) DWADS_Settings::get( 'credit_mempool', 1 );
		$seen           = array();

		foreach ( $outputs as $out ) {
			if ( $out['sats'] <= 0 ) {
				continue;
			}
			$key          = $out['txid'] . ':' . $out['vout'];
			$seen[ $key ] = true;

			$status         = $out['confirmed'] ? 'confirmed' : 'mempool';
			$should_credit  = $out['confirmed'] || $credit_mempool;
			$row            = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE txid = %s AND vout = %d", $out['txid'], $out['vout'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			if ( ! $row ) {
				$wpdb->insert(
					$table,
					array(
						'banner_id'     => (int) $banner['id'],
						'txid'          => $out['txid'],
						'vout'          => $out['vout'],
						'sats'          => (int) $out['sats'],
						'status'        => $status,
						'credited'      => $should_credit ? 1 : 0,
						'missing_polls' => 0,
						'block_height'  => $out['block_height'],
						'first_seen_at' => $now,
						'last_seen_at'  => $now,
						'confirmed_at'  => $out['confirmed'] ? $now : null,
					),
					array( '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
				);
				if ( $should_credit ) {
					self::credit( (int) $banner['id'], (int) $out['sats'] );
					DWADS_Log::add( sprintf( 'Payment %s:%d (%d sats, %s) credited to banner #%d.', $out['txid'], $out['vout'], $out['sats'], $status, $banner['id'] ) );
				}
				continue;
			}

			$was_credited = (int) $row['credited'];
			$new_credited = $should_credit ? 1 : 0;

			$update  = array(
				'last_seen_at'  => $now,
				'missing_polls' => 0,
				'status'        => $status,
			);
			$formats = array( '%s', '%d', '%s' );

			if ( $out['confirmed'] && empty( $row['confirmed_at'] ) ) {
				$update['confirmed_at'] = $now;
				$formats[]              = '%s';
			}
			if ( null !== $out['block_height'] ) {
				$update['block_height'] = $out['block_height'];
				$formats[]              = '%d';
			}
			if ( 'dropped' === $row['status'] && $out['confirmed'] ) {
				$update['dropped_at'] = null;
				$formats[]            = '%s';
			}
			if ( $new_credited !== $was_credited ) {
				$update['credited'] = $new_credited;
				$formats[]          = '%d';
				self::credit( (int) $banner['id'], $new_credited ? (int) $row['sats'] : -1 * (int) $row['sats'] );
			}

			$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ), $formats, array( '%d' ) );
		}

		// Mempool payments that vanish twice in a row never confirmed: stop
		// counting them, and remove the credit if it was already applied.
		$open = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE banner_id = %d AND status = 'mempool'", (int) $banner['id'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		foreach ( (array) $open as $row ) {
			$key = $row['txid'] . ':' . $row['vout'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$missing = (int) $row['missing_polls'] + 1;
			if ( $missing >= 2 ) {
				$wpdb->update(
					$table,
					array(
						'status'        => 'dropped',
						'credited'      => 0,
						'dropped_at'    => $now,
						'missing_polls' => $missing,
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%d', '%s', '%d' ),
					array( '%d' )
				);
				if ( (int) $row['credited'] ) {
					self::credit( (int) $banner['id'], -1 * (int) $row['sats'] );
				}
				DWADS_Log::add( sprintf( 'Payment %s:%d on banner #%d disappeared before confirming; credit removed.', $row['txid'], $row['vout'], $banner['id'] ) );
			} else {
				$wpdb->update( $table, array( 'missing_polls' => $missing ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
			}
		}

		$wpdb->update(
			DWADS_Banners::table(),
			array( 'last_poll_at' => $now ),
			array( 'id' => (int) $banner['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Adjust the received total.
	 *
	 * @param int $banner_id Banner id.
	 * @param int $delta     Satoshis, may be negative.
	 * @return void
	 */
	private static function credit( $banner_id, $delta ) {
		global $wpdb;
		if ( 0 === (int) $delta ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . DWADS_Banners::table() . ' SET sats_received = sats_received + %d, last_activity_at = %s WHERE id = %d',
				(int) $delta,
				gmdate( 'Y-m-d H:i:s' ),
				(int) $banner_id
			)
		);
	}

	/**
	 * Rebuild denormalised totals from the ledger tables.
	 *
	 * @param int|null $banner_id One banner, or null for all.
	 * @return int Number of banners reconciled.
	 */
	public static function reconcile( $banner_id = null ) {
		global $wpdb;

		$banners = DWADS_Banners::table();
		$payments = $wpdb->prefix . 'dwads_payments';
		$clicks  = $wpdb->prefix . 'dwads_clicks';

		if ( null === $banner_id ) {
			$ids = $wpdb->get_col( 'SELECT id FROM ' . $banners ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$ids = array( (int) $banner_id );
		}

		foreach ( (array) $ids as $id ) {
			$received = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM(sats), 0 ) FROM {$payments} WHERE banner_id = %d AND credited = 1", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$spent    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM(sats_charged), 0 ) FROM {$clicks} WHERE banner_id = %d AND charged = 1", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$clicks} WHERE banner_id = %d AND charged = 1", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update(
				$banners,
				array(
					'sats_received'  => $received,
					'sats_spent'     => $spent,
					'clicks_charged' => $count,
				),
				array( 'id' => (int) $id ),
				array( '%d', '%d', '%d' ),
				array( '%d' )
			);
		}

		return count( (array) $ids );
	}
}
