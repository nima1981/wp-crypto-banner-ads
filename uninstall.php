<?php
/**
 * Uninstall routine.
 *
 * Data is preserved unless the site owner explicitly opted in from the settings
 * screen, because deleting the tables would destroy paid advertiser balances.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dwads_settings = get_option( 'dwads_settings', array() );
if ( ! is_array( $dwads_settings ) || empty( $dwads_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$dwads_tables = array(
	$wpdb->prefix . 'dwads_banners',
	$wpdb->prefix . 'dwads_payments',
	$wpdb->prefix . 'dwads_clicks',
	$wpdb->prefix . 'dwads_impressions',
);
foreach ( $dwads_tables as $dwads_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $dwads_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$dwads_options = array(
	'dwads_settings',
	'dwads_secret',
	'dwads_next_index',
	'dwads_schema_version',
	'dwads_log',
	'dwads_last_poll',
	'dwads_poll_pause_until',
	'dwads_btc_price',
);
foreach ( $dwads_options as $dwads_option ) {
	delete_option( $dwads_option );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dwads\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dwads\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove uploaded banner images.
$dwads_uploads = wp_upload_dir();
$dwads_dir     = trailingslashit( $dwads_uploads['basedir'] ) . 'dwads';
if ( is_dir( $dwads_dir ) ) {
	$dwads_files = glob( $dwads_dir . '/*' );
	foreach ( (array) $dwads_files as $dwads_file ) {
		if ( is_file( $dwads_file ) ) {
			@unlink( $dwads_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	@rmdir( $dwads_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
