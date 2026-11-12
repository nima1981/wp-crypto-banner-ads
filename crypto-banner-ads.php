<?php
/**
 * Plugin Name:       Crypto Banner Ads
 * Plugin URI:        https://reza.consulting
 * Description:       Cookie-free, account-free banner advertising: one Bitcoin address per banner, pay-per-click balances, admin approval and a rotating placement snippet that renders nothing when no banner is funded.
 * Version:           1.2.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Reza Consulting Inc.
 * Author URI:        https://reza.consulting
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dwads
 */

defined( 'ABSPATH' ) || exit;

define( 'DWADS_VERSION', '1.2.9' );
define( 'DWADS_FILE', __FILE__ );
define( 'DWADS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DWADS_URL', plugin_dir_url( __FILE__ ) );

$dwads_includes = array(
	'includes/class-dwads-bigint.php',
	'includes/class-dwads-base58.php',
	'includes/class-dwads-bech32.php',
	'includes/class-dwads-secp256k1.php',
	'includes/class-dwads-address.php',
	'includes/class-dwads-xpub.php',
	'includes/class-dwads-settings.php',
	'includes/class-dwads-install.php',
	'includes/class-dwads-log.php',
	'includes/class-dwads-price.php',
	'includes/class-dwads-banners.php',
	'includes/class-dwads-payments.php',
	'includes/class-dwads-clicks.php',
	'includes/class-dwads-rewrite.php',
	'includes/class-dwads-serve.php',
	'includes/class-dwads-shortcodes.php',
	'includes/class-dwads-public-pages.php',
	'includes/class-dwads-admin.php',
	'includes/class-dwads-maintenance.php',
	'includes/class-dwads-plugin.php',
);

foreach ( $dwads_includes as $dwads_include ) {
	require_once DWADS_DIR . $dwads_include;
}
unset( $dwads_includes, $dwads_include );

// Registered at load time so activation can schedule with the custom interval.
add_filter( 'cron_schedules', array( 'DWADS_Payments', 'schedules' ) );

register_activation_hook( __FILE__, array( 'DWADS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DWADS_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'DWADS_Plugin', 'boot' ) );
