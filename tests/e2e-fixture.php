<?php
if ( ! defined( 'ACCESS_LEDGER_TESTS' ) || ! ACCESS_LEDGER_TESTS ) { exit; }
require_once __DIR__ . '/helpers.php';
wp_set_current_user( 1 );
update_option( 'woocommerce_downloads_require_login', 'no' );
update_option( 'woocommerce_file_download_method', 'force' );
list( $o, $p, $id ) = al_fixture();
$upload = wp_upload_dir();
$dir = $upload['basedir'] . '/woocommerce_uploads';
wp_mkdir_p( $dir );
file_put_contents( $dir . '/access-ledger-fixture.txt', 'Access Ledger delivery fixture' );
$file = new WC_Product_Download();
$file->set_id( 'file-original' ); $file->set_name( 'Design systems field guide' );
$file->set_file( $upload['baseurl'] . '/woocommerce_uploads/access-ledger-fixture.txt' );
$p->set_downloads( array( $file ) ); $p->set_name( 'Design systems field guide' ); $p->save();
wp_update_user( array( 'ID' => $o->get_customer_id(), 'user_email' => 'alex-' . $o->get_id() . '@example.test' ) );
$o->set_billing_email( 'alex-' . $o->get_id() . '@example.test' ); $o->save();
al_change( $id, array( 'user_email' => 'old-address@example.test' ) );
$items = $o->get_items(); $item = reset( $items );
$data = array( 'id' => $id, 'order_id' => $o->get_id(), 'email' => $o->get_billing_email(), 'url' => $item->get_item_download_url( 'file-original' ) );
// Real orders put the original delivery fixture beyond the initial 50 saved findings.
global $wpdb;
for ( $i = 0; $i < 55; ++$i ) {
    $older_test = wc_create_order( array( 'customer_id' => $o->get_customer_id() ) );
    $older_test->set_billing_email( 'page-' . $o->get_id() . '-' . $i . '@example.test' );
    $older_test->add_product( $p );
    $older_test->calculate_totals();
    $older_test->set_date_paid( time() );
    $older_test->set_status( 'completed' );
    $older_test->save();
    wc_downloadable_product_permissions( $older_test->get_id() );
    $changed = $wpdb->update( $wpdb->prefix . 'woocommerce_downloadable_product_permissions', array( 'user_email' => 'stale@example.test' ), array( 'order_id' => $older_test->get_id() ) );
    if ( 1 !== $changed ) { throw new RuntimeException( 'Pagination fixture needs one mismatched permission per order.' ); }
}
update_option( '_al_e2e_fixture', $data );
echo wp_json_encode( $data );
