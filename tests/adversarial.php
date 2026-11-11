<?php
/** Include after the real WP/Woo fixture helpers on a disposable InnoDB database. */
use AccessLedger\Engine;
use AccessLedger\Store;

if ( ! defined( 'ACCESS_LEDGER_TESTS' ) || ! ACCESS_LEDGER_TESTS ) {
    throw new RuntimeException( 'Set ACCESS_LEDGER_TESTS=true on a disposable site.' );
}
require_once __DIR__ . '/helpers.php';

( function () {
    global $wpdb, $passed;
    $initial_passed = $passed;
    $actor = get_current_user_id();
    al_check( current_user_can( 'manage_woocommerce' ), 'Adversarial suite starts as an authorized operator' );
    $audit_table = $wpdb->prefix . 'access_ledger_audits';
    $permission_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
    foreach ( array( $permission_table, $audit_table ) as $table ) {
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
        al_check( $status && 'InnoDB' === $status['Engine'], 'Adversarial rollback tests require real InnoDB: ' . $table );
    }

    $audit_count = function ( $id ) use ( $wpdb, $audit_table ) {
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE permission_id = %d", $id ) );
    };
    $fixture = function () {
        list( $order, $product, $id ) = al_fixture();
        al_change( $id, array( 'user_email' => 'stale@example.test' ) );
        $preview = Engine::preview( $id );
        al_check( ! is_wp_error( $preview ) && isset( $preview['token'] ), 'Real WooCommerce fixture produces an eligible preview' );
        return array( $order, $product, $id, $preview );
    };
    $rejected = function ( $result, $id, $before, $label, $status = 409 ) {
        al_check( is_wp_error( $result ) && ( $result->get_error_data()['status'] ?? null ) === $status, $label . ': expected error status' );
        al_check( al_row( $id ) === $before, $label . ': permission unchanged' );
    };

    // Exercise actual SQL failures, not mocked engine/store return values.
    $database_failure = function ( $pattern, $replacement, $operation ) use ( $wpdb ) {
        $hits = 0;
        $filter = function ( $sql ) use ( $pattern, $replacement, &$hits ) {
            if ( preg_match( $pattern, $sql ) ) {
                ++$hits;
                return $replacement;
            }
            return $sql;
        };
        $suppressed = $wpdb->suppress_errors( true );
        add_filter( 'query', $filter, PHP_INT_MAX );
        try {
            $result = $operation();
        } finally {
            remove_filter( 'query', $filter, PHP_INT_MAX );
            $wpdb->suppress_errors( $suppressed );
        }
        al_check( 1 === $hits, 'Fault injection reached exactly one intended database statement' );
        return $result;
    };

    // Fixtures use reserved test addresses; do not dispatch account-change emails.
    $block_mail = function () { return true; };
    add_filter( 'pre_wp_mail', $block_mail, PHP_INT_MAX );
    try {
        $manager = wp_insert_user( array(
            'user_login' => 'al_adv_' . wp_generate_password( 12, false ),
            'user_email' => wp_generate_password( 12, false ) . '@example.test',
            'user_pass' => wp_generate_password(), 'role' => 'shop_manager',
        ) );
        al_check( ! is_wp_error( $manager ), 'Second real store-manager account created' );

        list( $order, $product, $id, $preview ) = $fixture();
        $before = al_row( $id );
        foreach ( array( '', 'not-a-preview', str_repeat( 'a', 47 ), str_repeat( 'a', 49 ) ) as $token ) {
            $rejected( Engine::repair( $id, $token ), $id, $before, 'Malformed token rejected' );
        }
        $tampered = $preview['token'];
        $tampered[0] = 'a' === $tampered[0] ? 'b' : 'a';
        $rejected( Engine::repair( $id, $tampered ), $id, $before, 'One-byte token tampering rejected' );
        list( $other_order, $other_product, $other_id ) = al_fixture();
        $other_before = al_row( $other_id );
        $rejected( Engine::repair( $other_id, $preview['token'] ), $other_id, $other_before, 'Preview cannot target another permission' );
        wp_set_current_user( $manager );
        al_check( current_user_can( 'manage_woocommerce' ), 'Second operator has the repair capability' );
        $rejected( Engine::repair( $id, $preview['token'] ), $id, $before, 'Another authorized operator cannot use the preview' );
        wp_set_current_user( $actor );
        al_check( 0 === $audit_count( $id ), 'Rejected token attempts create no repair audit' );
        al_check( false !== get_transient( 'al_preview_' . $preview['token'] ), 'Rejected attempts do not consume the owner preview' );

        $expired = Engine::preview( $id );
        al_check( ! is_wp_error( $expired ), 'Expiry test has a valid preview' );
        $transient = 'al_preview_' . $expired['token'];
        $payload = get_transient( $transient );
        al_check( is_array( $payload ), 'Expiry test has a real stored transient' );
        set_transient( $transient, $payload, 1 );
        sleep( 2 );
        al_check( false === get_transient( $transient ), 'WordPress actually expired the transient' );
        $rejected( Engine::repair( $id, $expired['token'] ), $id, $before, 'Expired preview rejected' );

        list( $o, $p, $pid, $token ) = $fixture();
        $changed = wp_update_user( array( 'ID' => $o->get_customer_id(), 'user_email' => wp_generate_password( 12, false ) . '@example.test' ) );
        al_check( ! is_wp_error( $changed ), 'Customer account email changed through WordPress' );
        $unchanged = al_row( $pid );
        $rejected( Engine::repair( $pid, $token['token'] ), $pid, $unchanged, 'Changed account email invalidates preview' );

        foreach ( array( 'billing_email', 'status', 'customer_id', 'order_key' ) as $field ) {
            list( $o, $p, $pid, $token ) = $fixture();
            if ( 'billing_email' === $field ) { $o->set_billing_email( 'changed-order@example.test' ); }
            if ( 'status' === $field ) { $o->set_status( 'on-hold' ); }
            if ( 'customer_id' === $field ) { $o->set_customer_id( $other_order->get_customer_id() ); }
            if ( 'order_key' === $field ) { $o->set_order_key( 'wc_order_' . wp_generate_password( 16, false ) ); }
            $o->save();
            $unchanged = al_row( $pid );
            $rejected( Engine::repair( $pid, $token['token'] ), $pid, $unchanged, 'Changed order ' . $field . ' invalidates preview' );
            al_check( 0 === $audit_count( $pid ), 'Stale order creates no repair audit' );
        }

        foreach ( array(
            array( 'user_email' => 'externally-edited@example.test' ),
            array( 'user_id' => $other_order->get_customer_id() ),
            array( 'order_id' => $other_order->get_id() ),
            array( 'download_id' => 'file-replaced' ),
            array( 'order_key' => 'changed-permission-key' ),
            array( 'downloads_remaining' => 0 ),
            array( 'access_expires' => gmdate( 'Y-m-d 00:00:00', time() - 2 * DAY_IN_SECONDS ) ),
        ) as $change ) {
            list( $o, $p, $pid, $token ) = $fixture();
            al_change( $pid, $change );
            $unchanged = al_row( $pid );
            $rejected( Engine::repair( $pid, $token['token'] ), $pid, $unchanged, 'Changed permission ' . key( $change ) . ' invalidates preview' );
            al_check( 0 === $audit_count( $pid ), 'Stale permission creates no repair audit' );
        }

        list( $o, $p, $pid, $token ) = $fixture();
        $copy = new WC_Customer_Download();
        $copy->set_props( ( new WC_Customer_Download( $pid ) )->get_data() );
        $copy->set_id( 0 );
        $copy->save();
        al_check( $copy->get_id() > 0, 'Real duplicate permission inserted after preview' );
        $unchanged = al_row( $pid );
        $rejected( Engine::repair( $pid, $token['token'] ), $pid, $unchanged, 'New duplicate permission invalidates preview' );

        list( $o, $p, $pid, $token ) = $fixture();
        $download = new WC_Customer_Download( $pid );
        $download->track_download( $o->get_customer_id(), '127.0.0.1' );
        $before = al_row( $pid );
        $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_download_log WHERE permission_id = %d", $pid ), ARRAY_A );
        al_check( 1 === count( $logs ), 'Rollback fixture has a real WooCommerce consumption log' );
        $result = $database_failure(
            '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $audit_table, '/' ) . '`?\s/i',
            "INSERT INTO `{$audit_table}` (access_ledger_nonexistent_test_column) VALUES (1)",
            function () use ( $pid, $token ) { return Engine::repair( $pid, $token['token'] ); }
        );
        $rejected( $result, $pid, $before, 'Failed audit INSERT rolls back the permission UPDATE', 500 );
        al_check( 0 === $audit_count( $pid ), 'Failed repair leaves no audit row' );
        al_check( $logs === $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wc_download_log WHERE permission_id = %d", $pid ), ARRAY_A ), 'Failed repair preserves all download logs' );
        al_check( false !== get_transient( 'al_preview_' . $token['token'] ), 'Failed audit insert leaves the preview available' );
        $result = Engine::repair( $pid, $token['token'] );
        al_check( ! is_wp_error( $result ) && 1 === $audit_count( $pid ), 'Real repair succeeds after removing the SQL failure' );
        $fixed = al_row( $pid );
        $expected = $before;
        $expected['user_email'] = $o->get_billing_email();
        al_check( $fixed === $expected, 'Retry updates email only, preserving the consumed allowance' );
        $audit = Store::audit( $result['audit_id'] );
        al_check( false === strpos( $audit['before_state'] . $audit['after_state'], $o->get_order_key() ), 'Persisted audit contains no raw order key' );

        wp_set_current_user( $manager );
        $rejected( Engine::undo( $result['audit_id'] ), $pid, $fixed, 'Other authorized operator cannot undo' );
        $response = rest_do_request( new WP_REST_Request( 'POST', '/access-ledger/v1/audits/' . $result['audit_id'] . '/undo' ) );
        al_check( 403 === $response->get_status(), 'REST also rejects another operator undoing the audit' );
        wp_set_current_user( $actor );

        $undo = $database_failure(
            '/^\s*UPDATE\s+`?' . preg_quote( $audit_table, '/' ) . '`?\s+SET\s+undone_at\b/i',
            "UPDATE `{$audit_table}` SET access_ledger_nonexistent_test_column = 1",
            function () use ( $result ) { return Engine::undo( $result['audit_id'] ); }
        );
        $rejected( $undo, $pid, $fixed, 'Failed undo marker rolls back the permission reversal', 500 );
        al_check( Store::audit( $result['audit_id'] ) === $audit, 'Failed undo preserves the entire audit record' );
        al_check( ! is_wp_error( Engine::undo( $result['audit_id'] ) ), 'Undo succeeds after removing the SQL failure' );
        al_check( al_row( $pid ) === $before, 'Successful undo retains consumed counts, allowance and expiry' );
        al_check( ! empty( Store::audit( $result['audit_id'] )['undone_at'] ), 'Successful undo records its reversal' );

        foreach ( array( 'START TRANSACTION' => 503, 'COMMIT' => 500 ) as $statement => $status ) {
            list( $o, $p, $pid, $token ) = $fixture();
            $before = al_row( $pid );
            $failure = $database_failure(
                '/^\s*' . preg_quote( $statement, '/' ) . '\s*;?\s*$/i',
                'ACCESS_LEDGER_INTENTIONAL_SQL_SYNTAX_ERROR',
                function () use ( $pid, $token ) { return Engine::repair( $pid, $token['token'] ); }
            );
            $rejected( $failure, $pid, $before, $statement . ' failure does not report a successful repair', $status );
            al_check( 0 === $audit_count( $pid ), $statement . ' failure leaves no committed audit' );
            al_check( false !== get_transient( 'al_preview_' . $token['token'] ), $statement . ' failure preserves the preview' );
        }

        foreach ( array( 'permission', 'order', 'account' ) as $conflict ) {
            list( $o, $p, $pid, $token ) = $fixture();
            $result = Engine::repair( $pid, $token['token'] );
            al_check( ! is_wp_error( $result ), 'Undo-conflict fixture was repaired' );
            if ( 'permission' === $conflict ) { al_change( $pid, array( 'user_email' => 'later-edit@example.test' ) ); }
            if ( 'order' === $conflict ) { $o->set_billing_email( 'later-order@example.test' ); $o->save(); }
            if ( 'account' === $conflict ) {
                $changed = wp_update_user( array( 'ID' => $o->get_customer_id(), 'user_email' => wp_generate_password( 12, false ) . '@example.test' ) );
                al_check( ! is_wp_error( $changed ), 'Undo-conflict account change saved' );
            }
            $unchanged = al_row( $pid );
            $rejected( Engine::undo( $result['audit_id'] ), $pid, $unchanged, 'Undo refuses a later ' . $conflict . ' edit' );
            al_check( empty( Store::audit( $result['audit_id'] )['undone_at'] ), 'Conflicted undo is not recorded as successful' );
        }

        list( $o, $p, $pid, $token ) = $fixture();
        $before = al_row( $pid );
        list( $undo_order, $undo_product, $undo_id, $undo_token ) = $fixture();
        $result = Engine::repair( $undo_id, $undo_token['token'] );
        al_check( ! is_wp_error( $result ), 'Authorization fixture has a reversible audit' );
        $undo_before = al_row( $undo_id );
        $scan_before = get_option( Store::STATE );
        // REST dispatch verifies permissions; HTTP cookie/nonce behavior needs separate HTTP tests.
        foreach ( array( $o->get_customer_id(), 0 ) as $unauthorized ) {
            wp_set_current_user( $unauthorized );
            $rejected( Engine::preview( $pid ), $pid, $before, 'Unauthorized engine preview', 403 );
            $rejected( Engine::repair( $pid, $token['token'] ), $pid, $before, 'Unauthorized engine repair', 403 );
            $rejected( Engine::undo( $result['audit_id'] ), $undo_id, $undo_before, 'Unauthorized engine undo', 403 );
            foreach ( array(
                '/permissions/' . $pid . '/preview', '/permissions/' . $pid . '/repair',
                '/audits/' . $result['audit_id'] . '/undo', '/scan', '/scan/step', '/scan/cancel',
            ) as $path ) {
                $request = new WP_REST_Request( 'POST', '/access-ledger/v1' . $path );
                $request->set_body_params( array( 'token' => $token['token'], 'days' => 30 ) );
                $response = rest_do_request( $request );
                al_check( $response->get_status() === ( $unauthorized ? 403 : 401 ), 'Unauthorized REST mutation denied: ' . $path );
            }
            al_check( al_row( $pid ) === $before && al_row( $undo_id ) === $undo_before, 'Unauthorized requests leave both permissions untouched' );
            al_check( get_option( Store::STATE ) === $scan_before, 'Unauthorized requests leave scan state untouched' );
            al_check( 0 === $audit_count( $pid ) && empty( Store::audit( $result['audit_id'] )['undone_at'] ), 'Unauthorized writes create no audit and do not mark undo' );
        }
        wp_set_current_user( $actor );
        al_check( false !== get_transient( 'al_preview_' . $token['token'] ), 'Unauthorized requests do not consume an authorized preview' );

        echo 'ACCESS_LEDGER_ADVERSARIAL_RESULT: ' . ( $passed - $initial_passed ) . " passed; real WordPress/WooCommerce fixtures and SQL fault injection; no concurrent-worker claim\n";
    } finally {
        wp_set_current_user( $actor );
        remove_filter( 'pre_wp_mail', $block_mail, PHP_INT_MAX );
    }
} )();
