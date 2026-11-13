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
 * Housekeeping cron.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maintenance tasks.
 */
final class DWADS_Maintenance {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'dwads_cron_maintenance', array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule the hourly job.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'dwads_cron_maintenance' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'dwads_cron_maintenance' );
		}
	}

	/**
	 * Run.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$table  = $wpdb->prefix . 'dwads_impressions';
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		DWADS_Payments::reconcile();

		if ( $deleted ) {
			DWADS_Log::add( sprintf( 'Maintenance removed %d expired impression rows.', (int) $deleted ) );
		}
	}
}
