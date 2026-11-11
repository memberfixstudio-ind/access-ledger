<?php
if ( ! defined( 'ACCESS_LEDGER_TESTS' ) || ! ACCESS_LEDGER_TESTS ) { exit; }
use AccessLedger\Engine;
require_once __DIR__ . '/helpers.php';
wp_set_current_user( 1 );
$mode = getenv( 'AL_TEST_MODE' );
if ( 'setup' === $mode ) {
    list( $order, $product, $pid ) = al_fixture();
    al_change( $pid, array( 'user_email' => 'old@example.test' ) );
    update_option( '_al_concurrency', array( 'id' => $pid, 'email' => $order->get_billing_email(), 'token' => Engine::preview( $pid )['token'] ) );
} else {
    $fixture = get_option( '_al_concurrency' );
    if ( 'repair' === $mode ) {
        add_filter( 'query', function( $query ) {
            if ( 'COMMIT' === $query ) { file_put_contents( getenv( 'AL_TEST_BARRIER' ), 'locked' ); usleep( 2000000 ); }
            return $query;
        } );
        $r = Engine::repair( $fixture['id'], $fixture['token'] );
        al_check( ! is_wp_error( $r ), 'Concurrent repair completes' );
    } elseif ( 'consume' === $mode ) {
        ( new WC_Customer_Download( $fixture['id'] ) )->track_download();
    } elseif ( 'verify' === $mode ) {
        $row = al_row( $fixture['id'] );
        al_check( $row['user_email'] === $fixture['email'] && (int) $row['downloads_remaining'] === 4 && (int) $row['download_count'] === 1, 'Two-process correction/download preserves email and allowance' );
        delete_option( '_al_concurrency' );
    }
}
