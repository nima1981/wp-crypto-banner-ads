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
 * Public routes: click redirects and management pages.
 *
 * @package Crypto_Banner_Ads
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite rules and dispatch.
 */
final class DWADS_Rewrite {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
	}

	/**
	 * Register the two public patterns.
	 *
	 * @return void
	 */
	public static function add_rules() {
		list( $click_base, $manage_base ) = DWADS_Settings::route_bases();
		$click_base  = preg_quote( $click_base, '#' );
		$manage_base = preg_quote( $manage_base, '#' );

		add_rewrite_rule( '^' . $click_base . '/([A-Za-z0-9_\-\.]+)/?$', 'index.php?dwads_click=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $manage_base . '/(\d+)/([A-Za-z0-9]+)/?$', 'index.php?dwads_manage_id=$matches[1]&dwads_manage_token=$matches[2]', 'top' );
	}

	/**
	 * Allow our query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'dwads_click';
		$vars[] = 'dwads_manage_id';
		$vars[] = 'dwads_manage_token';
		return $vars;
	}

	/**
	 * Handle a matched route.
	 *
	 * @return void
	 */
	public static function dispatch() {
		$click = get_query_var( 'dwads_click' );
		if ( is_string( $click ) && '' !== $click ) {
			nocache_headers();
			DWADS_Clicks::handle_click( $click );
			exit;
		}

		$id = (int) get_query_var( 'dwads_manage_id' );
		if ( $id > 0 ) {
			nocache_headers();
			DWADS_Public_Pages::render_manage( $id, (string) get_query_var( 'dwads_manage_token' ) );
			exit;
		}
	}
}
