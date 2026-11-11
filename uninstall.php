<?php
/** Explicit opt-in preserves operational evidence when reinstalling. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
if ( ! defined( 'ACCESS_LEDGER_DELETE_DATA' ) || ! ACCESS_LEDGER_DELETE_DATA ) { return; }
global $wpdb;
foreach ( array( 'access_ledger_reports', 'access_ledger_audits' ) as $suffix ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$suffix}" );
}
delete_option( 'access_ledger_version' );
delete_option( 'access_ledger_scan' );
wp_clear_scheduled_hook( 'access_ledger_scan_step' );
delete_option( 'access_ledger_scan_lock' );
wp_clear_scheduled_hook( 'access_ledger_cleanup' );
