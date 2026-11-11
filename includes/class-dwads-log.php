<?php
/**
 * Small ring buffer of diagnostic messages.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics log.
 */
final class DWADS_Log {

	const OPTION = 'dwads_log';

	const MAX = 80;

	/**
	 * Append a line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function add( $message ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = gmdate( 'Y-m-d H:i:s' ) . ' ' . $message;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, 'no' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[dwads] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Read the log newest first.
	 *
	 * @return array
	 */
	public static function read() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/**
	 * Clear the log.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::OPTION, array(), 'no' );
	}
}
