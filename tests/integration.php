<?php
/** Run only on a disposable WordPress + WooCommerce database: wp eval-file tests/integration.php */
if ( ! defined( 'ACCESS_LEDGER_TESTS' ) || ! ACCESS_LEDGER_TESTS ) { throw new RuntimeException( 'Set ACCESS_LEDGER_TESTS=true on a disposable site.' ); }
use AccessLedger\Engine;
use AccessLedger\Store;
global $wpdb, $passed;
wp_set_current_user( 1 );
Store::install();
require_once __DIR__ . '/helpers.php';
list( $order, $product, $pid ) = al_fixture();
$original = al_row( $pid );
al_check( ! Engine::inspect( $order->get_id() )['issues'], 'Healthy order has no discrepancies' );
al_check( $original === al_row( $pid ), 'Inspection never changes a permission' );
al_change( $pid, array( 'user_email' => 'stale@example.test' ) );
$read_failure = function ( $sql ) {
    return strpos( $sql, 'woocommerce_downloadable_product_permissions WHERE order_id =' ) !== false ? 'SELECT * FROM access_ledger_deliberately_missing_table' : $sql;
};
$suppressed = $wpdb->suppress_errors( true );
add_filter( 'query', $read_failure, PHP_INT_MAX );
try {
    $failed_inspection = Engine::inspect( $order->get_id() );
    al_check( is_wp_error( $failed_inspection ) && $failed_inspection->get_error_data()['status'] === 500, 'Database read failure never becomes a missing-permission finding' );
    al_check( is_wp_error( Engine::preview( $pid ) ), 'Database read failure prevents correction preview' );
} finally {
    remove_filter( 'query', $read_failure, PHP_INT_MAX );
    $wpdb->suppress_errors( $suppressed );
}
$preview = Engine::preview( $pid );
al_check( ! is_wp_error( $preview ), 'Same-user email drift offers preview' );
al_check( strpos( wp_json_encode( Engine::inspect( $order->get_id() ) ), $order->get_order_key() ) === false, 'Report excludes order key' );
al_check( strpos( wp_json_encode( $preview ), $order->get_order_key() ) === false, 'Preview excludes order key' );
$download = new WC_Customer_Download( $pid ); $download->track_download( $order->get_customer_id(), '127.0.0.1' );
$consumed = al_row( $pid );
$result = Engine::repair( $pid, $preview['token'] );
al_check( ! is_wp_error( $result ), 'Correction succeeds after concurrent download consumption' );
$fixed = al_row( $pid );
$expected = $consumed; $expected['user_email'] = $order->get_billing_email();
al_check( $fixed === $expected, 'Only email changed, all counters dates identifiers retained' );
al_check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_download_log WHERE permission_id=%d", $pid ) ) === 1, 'Download log retained' );
al_check( is_wp_error( Engine::repair( $pid, $preview['token'] ) ), 'Preview cannot be replayed' );
al_check( ! is_wp_error( Engine::undo( $result['audit_id'] ) ), 'Guarded undo succeeds' );
al_check( al_row( $pid ) === $consumed, 'Undo preserves consumed allowance' );
al_check( is_wp_error( Engine::undo( $result['audit_id'] ) ), 'Repeated undo refused' );
al_check( is_wp_error( Engine::repair( $pid, $preview['token'] ) ), 'Old preview still rejected after undo' );
$p = Engine::preview( $pid );
al_change( $pid, array( 'user_email' => 'someone-else@example.test' ) );
al_check( is_wp_error( Engine::repair( $pid, $p['token'] ) ), 'Changed permission rejects stale preview' );
$p = Engine::preview( $pid );
$order->set_billing_email( 'changed-order@example.test' ); $order->save();
al_check( is_wp_error( Engine::repair( $pid, $p['token'] ) ), 'Changed order rejects stale preview' );
$order->set_billing_email( get_userdata( $order->get_customer_id() )->user_email ); $order->save();
al_change( $pid, array( 'downloads_remaining' => 0, 'user_email' => 'stale@example.test' ) );
al_check( in_array( 'exhausted', al_codes( $order->get_id() ), true ) && is_wp_error( Engine::preview( $pid ) ), 'Exhausted allowance explained, repair blocked' );
al_change( $pid, array( 'downloads_remaining' => 4, 'access_expires' => '2020-01-01 00:00:00' ) );
al_check( in_array( 'expired', al_codes( $order->get_id() ), true ) && is_wp_error( Engine::preview( $pid ) ), 'Expiry explained, repair blocked' );
al_change( $pid, array( 'access_expires' => gmdate( 'Y-m-d 00:00:00' ) ) );
al_check( ! in_array( 'expired', al_codes( $order->get_id() ), true ) && ! is_wp_error( Engine::preview( $pid ) ), 'Expiry today remains valid as in WooCommerce' );
al_change( $pid, array( 'access_expires' => null, 'order_key' => 'wrong-key' ) );
al_check( is_wp_error( Engine::preview( $pid ) ), 'Order-key mismatch is review-only' );
al_change( $pid, array( 'order_key' => $order->get_order_key(), 'user_id' => 0 ) );
al_check( is_wp_error( Engine::preview( $pid ) ), 'Account transfer refused' );
al_change( $pid, array( 'user_id' => $order->get_customer_id() ) );
$order->set_status( 'processing' ); $order->save(); update_option( 'woocommerce_downloads_grant_access_after_payment', 'no' );
al_check( in_array( 'order_blocked', al_codes( $order->get_id() ), true ) && is_wp_error( Engine::preview( $pid ) ), 'Processing download setting respected' );
update_option( 'woocommerce_downloads_grant_access_after_payment', 'yes' ); $order->set_status( 'completed' ); $order->save();
$p = Engine::preview( $pid );
$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 1, 'reason' => 'test' ) );
al_check( ! is_wp_error( $refund ) && is_wp_error( Engine::repair( $pid, $p['token'] ) ), 'Refund between preview and apply blocks correction' );
list( $order2, $product2, $pid2 ) = al_fixture();
al_change( $pid2, array( 'user_email' => 'stale@example.test' ) );
$copy = new WC_Customer_Download(); $copy->set_props( ( new WC_Customer_Download( $pid2 ) )->get_data() ); $copy->set_id( 0 ); $copy->save();
al_check( in_array( 'duplicate_permission', al_codes( $order2->get_id() ), true ) && is_wp_error( Engine::preview( $pid2 ) ), 'Duplicate grants require review' );
list( $guest, $gp, $gid ) = al_fixture( true ); al_change( $gid, array( 'user_email' => 'old@example.test' ) );
al_check( is_wp_error( Engine::preview( $gid ) ), 'Guest correction refused' );
list( $o3, $p3, $id3 ) = al_fixture(); al_change( $id3, array( 'user_email' => 'old@example.test' ) );
$p = Engine::preview( $id3 );
$wpdb->delete( $wpdb->prefix . 'woocommerce_downloadable_product_permissions', array( 'permission_id' => $id3 ) );
al_check( in_array( 'missing_permission', al_codes( $o3->get_id() ), true ), 'Missing grant explicitly flagged as uncertain' );
al_check( is_wp_error( Engine::repair( $id3, $p['token'] ) ) && ! al_row( $id3 ), 'Revoked permission never recreated' );
list( $o4, $p4, $id4 ) = al_fixture(); al_change( $id4, array( 'user_email' => 'old@example.test' ) ); $p = Engine::preview( $id4 );
$p4->set_downloads( array() ); $p4->save();
al_check( in_array( 'removed_file', al_codes( $o4->get_id() ), true ) && is_wp_error( Engine::repair( $id4, $p['token'] ) ), 'Removed product file blocks correction' );
wp_set_current_user( $order->get_customer_id() );
al_check( is_wp_error( Engine::preview( $pid ) ) && is_wp_error( Engine::undo( $result['audit_id'] ) ), 'Customer cannot preview or undo repairs' );
foreach ( array( '/dashboard', '/orders/' . $order->get_id(), '/export' ) as $route ) {
    $response = rest_do_request( new WP_REST_Request( 'GET', '/access-ledger/v1' . $route ) );
    al_check( $response->get_status() === 403, 'Customer forbidden: ' . $route );
}
wp_set_current_user( 0 );
al_check( rest_do_request( new WP_REST_Request( 'GET', '/access-ledger/v1/dashboard' ) )->get_status() >= 400, 'Anonymous dashboard denied' );
wp_set_current_user( 1 );
$scan = Store::scan_start( 30 );
al_check( ! is_wp_error( $scan ), 'Resumable scan starts' );
for ( $i = 0; $i < 20; $i++ ) { Store::scan_step(); if ( ( Store::dashboard()['scan']['status'] ?? '' ) !== 'running' ) { break; } }
$dash = Store::dashboard();
al_check( ( $dash['scan']['processed'] ?? 0 ) >= 5, 'Scan processes real WooCommerce orders' );
al_check( count( $dash['reports'] ) > 0, 'Scan persists reports' );
require __DIR__ . '/adversarial.php';
require __DIR__ . '/reporting.php';
echo "ACCESS_LEDGER_TEST_RESULT: {$passed} passed; WP " . get_bloginfo( 'version' ) . '; WC ' . WC_VERSION . '; PHP ' . PHP_VERSION . "\n";
