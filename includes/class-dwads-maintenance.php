<?php
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
