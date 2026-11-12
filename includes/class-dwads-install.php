<?php
/**
 * Schema install and upgrade.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database installation.
 */
final class DWADS_Install {

	const SCHEMA = '1.3.0';

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		self::run_schema();

		if ( ! get_option( 'dwads_secret' ) ) {
			update_option( 'dwads_secret', bin2hex( random_bytes( 32 ) ), 'no' );
		}
		if ( false === get_option( 'dwads_next_index' ) ) {
			add_option( 'dwads_next_index', 0, '', 'no' );
		}
		if ( false === get_option( DWADS_Settings::OPTION ) ) {
			add_option( DWADS_Settings::OPTION, DWADS_Settings::defaults() );
		}

		self::protect_upload_dir();
		DWADS_Rewrite::add_rules();
		DWADS_Payments::schedule();
		DWADS_Maintenance::schedule();

		flush_rewrite_rules();
		update_option( 'dwads_schema_version', self::SCHEMA );
	}

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'dwads_cron_poll' );
		wp_clear_scheduled_hook( 'dwads_cron_maintenance' );
		flush_rewrite_rules();
	}

	/**
	 * Run the schema check on every load (cheap: one option read).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'dwads_schema_version' ) !== self::SCHEMA ) {
			self::run_schema();
			self::protect_upload_dir();
			update_option( 'dwads_schema_version', self::SCHEMA );
			flush_rewrite_rules( false );
		}
		if ( ! wp_next_scheduled( 'dwads_cron_poll' ) ) {
			DWADS_Payments::schedule();
		}
		if ( ! wp_next_scheduled( 'dwads_cron_maintenance' ) ) {
			DWADS_Maintenance::schedule();
		}
	}

	/**
	 * Create or update all tables.
	 *
	 * @return void
	 */
	public static function run_schema() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$banners = $wpdb->prefix . 'dwads_banners';
		$payments = $wpdb->prefix . 'dwads_payments';
		$clicks  = $wpdb->prefix . 'dwads_clicks';
		$impressions = $wpdb->prefix . 'dwads_impressions';

		$sql = array();

		$sql[] = "CREATE TABLE {$banners} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL DEFAULT '',
			token_enc text NULL,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			advertiser_label varchar(120) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			image_file varchar(255) NOT NULL DEFAULT '',
			image_w int(10) unsigned NOT NULL DEFAULT 0,
			image_h int(10) unsigned NOT NULL DEFAULT 0,
			image_mime varchar(40) NOT NULL DEFAULT '',
			image_bytes int(10) unsigned NOT NULL DEFAULT 0,
			source_w int(10) unsigned NOT NULL DEFAULT 0,
			source_h int(10) unsigned NOT NULL DEFAULT 0,
			target_url text NOT NULL,
			alt_text varchar(255) NOT NULL DEFAULT '',
			keywords varchar(255) NOT NULL DEFAULT '',
			click_price_usd decimal(12,6) DEFAULT NULL,
			address varchar(90) NOT NULL DEFAULT '',
			derivation_index bigint(20) unsigned DEFAULT NULL,
			sats_received bigint(20) NOT NULL DEFAULT 0,
			sats_spent bigint(20) NOT NULL DEFAULT 0,
			clicks_charged bigint(20) unsigned NOT NULL DEFAULT 0,
			impressions bigint(20) unsigned NOT NULL DEFAULT 0,
			last_served_at datetime DEFAULT NULL,
			last_manage_view_at datetime DEFAULT NULL,
			last_poll_at datetime DEFAULT NULL,
			last_activity_at datetime DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			notes text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			UNIQUE KEY address (address),
			UNIQUE KEY derivation_index (derivation_index),
			KEY slot_status (status,image_w,image_h),
			KEY site_status (site_id,status),
			KEY pollable (status,last_poll_at),
			KEY activity (last_activity_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$payments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			banner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			txid char(64) NOT NULL DEFAULT '',
			vout int(10) unsigned NOT NULL DEFAULT 0,
			sats bigint(20) NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'mempool',
			credited tinyint(1) NOT NULL DEFAULT 0,
			missing_polls smallint(5) unsigned NOT NULL DEFAULT 0,
			block_height int(10) unsigned DEFAULT NULL,
			first_seen_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_seen_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			confirmed_at datetime DEFAULT NULL,
			dropped_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY txout (txid,vout),
			KEY banner_status (banner_id,status),
			KEY credited (banner_id,credited)
		) {$charset};";

		$sql[] = "CREATE TABLE {$clicks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			banner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			visitor_hash char(64) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			ua_hash char(64) NOT NULL DEFAULT '',
			price_usd decimal(12,6) NOT NULL DEFAULT 0.000000,
			sats_charged bigint(20) NOT NULL DEFAULT 0,
			charged tinyint(1) NOT NULL DEFAULT 0,
			reason varchar(32) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY banner_time (banner_id,created_at),
			KEY visitor (banner_id,visitor_hash,created_at),
			KEY ip_time (ip_hash,created_at),
			KEY charged (charged,created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$impressions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nonce char(32) NOT NULL DEFAULT '',
			banner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			visitor char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			used_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY nonce (nonce),
			KEY banner_time (banner_id,created_at),
			KEY visitor_time (banner_id,visitor,created_at),
			KEY expiry (expires_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Make sure the banner upload folder exists and cannot execute PHP.
	 *
	 * @return void
	 */
	public static function protect_upload_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'dwads';
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Crypto Banner Ads: never execute uploaded files.\n"
				. "<IfModule mod_authz_core.c>\n"
				. "\t<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n"
				. "\t\tRequire all denied\n"
				. "\t</FilesMatch>\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "\t<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n"
				. "\t\tOrder allow,deny\n"
				. "\t\tDeny from all\n"
				. "\t</FilesMatch>\n"
				. "</IfModule>\n"
				. "Options -Indexes\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
