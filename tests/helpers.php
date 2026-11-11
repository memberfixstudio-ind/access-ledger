<?php
if ( ! defined( 'ACCESS_LEDGER_TESTS' ) || ! ACCESS_LEDGER_TESTS ) { exit; }
global $passed;
$passed = 0;
function al_check( $condition, $message ) {
    global $passed;
    if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
    $passed++;
    echo 'PASS: ' . $message . "\n";
}
function al_codes( $id ) { return array_column( \AccessLedger\Engine::inspect( $id )['issues'], 'code' ); }
function al_row( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE permission_id=%d", $id ), ARRAY_A ); }
function al_fixture( $guest = false ) {
    $uid = $guest ? 0 : wp_insert_user( array( 'user_login' => 'al_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_password( 12, false ) . '@example.test', 'role' => 'customer' ) );
    $product = new WC_Product_Simple();
    $product->set_name( 'Studio resource pack' );
    $product->set_regular_price( '49' );
    $product->set_virtual( true );
    $product->set_downloadable( true );
    $product->set_download_limit( 5 );
    $file = new WC_Product_Download();
    $file->set_id( 'file-original' ); $file->set_name( 'Resource pack' ); $file->set_file( 'https://example.org/resource.zip' );
    $product->set_downloads( array( $file ) ); $product->save();
    $order = wc_create_order( array( 'customer_id' => $uid ) );
    $order->set_billing_email( $guest ? 'guest@example.test' : get_userdata( $uid )->user_email );
    $order->add_product( $product ); $order->calculate_totals(); $order->set_date_paid( time() ); $order->set_status( 'completed' ); $order->save();
    wc_downloadable_product_permissions( $order->get_id() );
    global $wpdb;
    $pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT permission_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id=%d LIMIT 1", $order->get_id() ) );
    if ( ! $pid ) { throw new RuntimeException( 'WC did not create a permission' ); }
    return array( $order, $product, $pid );
}
function al_change( $id, $data ) { global $wpdb; $wpdb->update( $wpdb->prefix . 'woocommerce_downloadable_product_permissions', $data, array( 'permission_id' => $id ) ); }
