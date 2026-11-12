<?php
/**
 * Plugin bootstrap.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the modules together.
 */
final class DWADS_Plugin {

	/** @var bool */
	private static $booted = false;

	/**
	 * Boot.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		DWADS_Install::maybe_upgrade();

		DWADS_Rewrite::init();
		DWADS_Shortcodes::init();
		DWADS_Serve::init();
		DWADS_Public_Pages::init();
		DWADS_Payments::init();
		DWADS_Maintenance::init();

		if ( is_admin() ) {
			DWADS_Admin::init();
		}

		add_action( 'admin_notices', array( __CLASS__, 'environment_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DWADS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=dwads-settings' ) ) . '">' . esc_html__( 'Settings', 'dwads' ) . '</a>';
		return $links;
	}

	/**
	 * Warn when the server cannot derive addresses.
	 *
	 * @return void
	 */
	public static function environment_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! DWADS_BigInt::available() ) {
			echo '<div class="notice notice-error"><p><strong>Crypto Banner Ads:</strong> ' . esc_html__( 'this server has neither the GMP nor the BCMath PHP extension, so payment addresses cannot be derived. Ask your host to enable one of them.', 'dwads' ) . '</p></div>';
			return;
		}
		if ( ! in_array( 'ripemd160', hash_algos(), true ) ) {
			echo '<div class="notice notice-error"><p><strong>Crypto Banner Ads:</strong> ' . esc_html__( 'RIPEMD160 is missing from this PHP build, so addresses cannot be derived. Ask your host to enable the hash extension with ripemd160 support.', 'dwads' ) . '</p></div>';
		}
	}

	/**
	 * Debug logger.
	 *
	 * @param mixed $message Message.
	 * @return void
	 */
	public static function log( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( is_array( $message ) || is_object( $message ) ) {
			$message = wp_json_encode( $message );
		}
		error_log( '[dwads] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
