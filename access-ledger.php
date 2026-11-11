<?php
/**
 * Plugin Name: Access Ledger for WooCommerce
 * Description: Explain digital-download access problems and preview history-preserving email corrections.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Rohan Salave
 * Author URI: https://www.linkedin.com/in/rohan-salave-362475189/
 * License: GPL-2.0-or-later
 * Text Domain: access-ledger
 */
defined( 'ABSPATH' ) || exit;
define( 'ACCESS_LEDGER_VERSION', '1.0.0' );
define( 'ACCESS_LEDGER_FILE', __FILE__ );
foreach ( array( 'store', 'engine', 'api', 'admin' ) as $class ) {
    require_once __DIR__ . '/includes/class-' . $class . '.php';
}
register_activation_hook( __FILE__, array( 'AccessLedger\\Store', 'install' ) );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'access_ledger_scan_step' ); wp_clear_scheduled_hook( 'access_ledger_cleanup' ); } );
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Access Ledger needs WooCommerce to inspect download access.', 'access-ledger' ) . '</p></div>';
        } );
        return;
    }
    if ( get_option( 'access_ledger_version' ) !== ACCESS_LEDGER_VERSION ) {
        $installed = \AccessLedger\Store::install();
        if ( is_wp_error( $installed ) ) { return; }
    }
    \AccessLedger\Admin::register();
    \AccessLedger\API::register();
    \AccessLedger\Store::register();
} );
