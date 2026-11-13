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
