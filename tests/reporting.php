<?php
/** Persisted report/API tests; included by integration.php on a disposable WordPress database. */
use AccessLedger\API;
use AccessLedger\Engine;
use AccessLedger\Store;

if ( ! defined( 'ACCESS_LEDGER_TESTS' ) || ! ACCESS_LEDGER_TESTS ) {
    throw new RuntimeException( 'Set ACCESS_LEDGER_TESTS=true on a disposable site.' );
}

( function () {
    global $wpdb, $passed;
    $initial_passed = $passed;
    $actor = get_current_user_id();
    $previous_state = get_option( Store::STATE );
    $table = $wpdb->prefix . 'access_ledger_reports';
    $scan_id = wp_generate_uuid4();
    $other_scan = wp_generate_uuid4();
    $state = array(
        'id' => $scan_id, 'status' => 'completed', 'processed' => 105, 'page' => 7, 'cursor' => 105, 'max_id' => 105,
        'days' => 30, 'since' => time() - 30 * DAY_IN_SECONDS, 'cutoff' => time(),
        'started_at' => gmdate( 'c' ), 'finished_at' => gmdate( 'c' ),
    );
    $get = function ( $path, $params = array() ) {
        $request = new WP_REST_Request( 'GET', '/access-ledger/v1' . $path );
        $request->set_query_params( $params );
        return rest_do_request( $request );
    };
    $validate = function ( $response, $schema ) {
        al_check( 200 === $response->get_status(), $schema . ': successful response' );
        al_check( true === rest_validate_value_from_schema( $response->get_data(), API::schema( $schema ) ), $schema . ': response matches advertised schema' );
        al_check( 'private, no-store, max-age=0' === ( $response->get_headers()['Cache-Control'] ?? '' ), $schema . ': private no-store response' );
        return $response->get_data();
    };
    $parse_csv = function ( $csv ) {
        $stream = fopen( 'php://temp', 'w+' );
        fwrite( $stream, $csv );
        rewind( $stream );
        $header = fgetcsv( $stream, 0, ',', '"', '' );
        $rows = array();
        while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
            if ( count( $header ) !== count( $row ) ) { throw new RuntimeException( 'CSV row has the wrong column count.' ); }
            $rows[] = array_combine( $header, $row );
        }
        fclose( $stream );
        return $rows;
    };
    try {
        list( $order, $product, $permission_id ) = al_fixture();
        al_change( $permission_id, array( 'user_email' => 'old@example.test' ) );
        $permission_before = al_row( $permission_id );
        $audit_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}access_ledger_audits" );
        $template = Engine::inspect( $order->get_id() );
        // Synthetic saved snapshots exercise real SQL pagination without creating 1,005 Woo orders.
        $insert = function ( $i, $scan = null, $checked_at = null ) use ( $wpdb, $table, $template, $scan_id ) {
            $report = $template;
            $report['order_id'] = 900000000 + $i;
            $report['order_number'] = (string) $report['order_id'];
            $report['order_key'] = 'private-order-key-sentinel';
            $report['permissions'][0]['file_url'] = 'https://example.test/private-file-sentinel';
            $ok = $wpdb->insert( $table, array(
                'scan_id' => $scan ?? $scan_id, 'order_id' => $report['order_id'],
                'checked_at' => $checked_at ?? gmdate( 'Y-m-d H:i:s' ),
                'issue_count' => 1, 'repairable_count' => 1, 'report' => wp_json_encode( $report ),
            ) );
            if ( false === $ok ) { throw new RuntimeException( 'Could not insert a reporting fixture.' ); }
            return (int) $wpdb->insert_id;
        };
        update_option( Store::STATE, $state, false );
        $ids = array();
        for ( $i = 1; $i <= 105; ++$i ) { $ids[ $i ] = $insert( $i ); }
        $insert( 2000, $other_scan );
        $insert( 2001, $scan_id, gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) );

        $dashboard = $validate( $get( '/dashboard' ), 'dashboard' );
        al_check( 50 === count( $dashboard['reports'] ) && $dashboard['pagination']['has_more'], 'Dashboard bounds the initial page to 50 of 105 reports' );
        al_check( $scan_id === $dashboard['scan']['id'] && $scan_id === $dashboard['pagination']['scan_id'], 'Dashboard state and pagination share a scan ID' );
        al_check( $ids[56] === $dashboard['pagination']['next_before_id'], 'Cursor is the last returned database ID, not an offset or order ID' );
        al_check( 105 === $dashboard['totals']['issues'], 'Totals cover the latest scan, excluding retained other scans and expired rows' );
        $page1 = $validate( $get( '/reports', array( 'scan_id' => $scan_id ) ), 'reports' );
        al_check( $dashboard['reports'] === $page1['reports'], 'Reports without before_id returns the same initial page' );
        $insert( 106 );
        $params = array( 'scan_id' => $scan_id, 'before_id' => (string) $page1['next_before_id'] );
        $page2 = $validate( $get( '/reports', $params ), 'reports' );
        al_check( 50 === count( $page2['reports'] ) && $ids[6] === $page2['next_before_id'], 'Second page uses an exclusive stable boundary despite a newer insert' );
        al_check( $page2 === $get( '/reports', $params )->get_data(), 'Retrying a cursor returns the same page' );
        $page3 = $validate( $get( '/reports', array( 'scan_id' => $scan_id, 'before_id' => $page2['next_before_id'] ) ), 'reports' );
        al_check( 5 === count( $page3['reports'] ) && ! $page3['has_more'] && null === $page3['next_before_id'], 'Final short page terminates pagination' );
        $orders = array_column( array_merge( $page1['reports'], $page2['reports'], $page3['reports'] ), 'order_id' );
        al_check( range( 900000105, 900000001 ) === $orders && 105 === count( array_unique( $orders ) ), 'All 105 original reports appear in order exactly once, with no boundary gaps or mixed scans' );
        $exact = $get( '/reports', array( 'scan_id' => $scan_id, 'before_id' => $ids[51] ) )->get_data();
        al_check( 50 === count( $exact['reports'] ) && ! $exact['has_more'] && null === $exact['next_before_id'], 'Exactly 50 remaining reports need no extra empty-page request' );
        $empty = $get( '/reports', array( 'scan_id' => $scan_id, 'before_id' => $ids[1] ) )->get_data();
        al_check( array() === $empty['reports'] && ! $empty['has_more'] && null === $empty['next_before_id'], 'Boundary at the oldest ID returns a bounded empty page' );
        $wpdb->delete( $table, array( 'id' => $ids[56] ) );
        al_check( $page2 === $get( '/reports', $params )->get_data(), 'Deleting the boundary row does not shift the next page' );
        $wpdb->delete( $table, array( 'id' => $ids[55] ) );
        $after_delete = $get( '/reports', $params )->get_data();
        al_check( range( 900000054, 900000005 ) === array_column( $after_delete['reports'], 'order_id' ), 'Deleting an older row causes no offset skip or duplicate' );

        foreach ( array( 0, -1, '1.5', '1e2', '01', '1 OR 1=1', str_repeat( '9', 30 ), array( 1 ) ) as $invalid ) {
            al_check( 400 === $get( '/reports', array( 'scan_id' => $scan_id, 'before_id' => $invalid ) )->get_status(), 'Invalid before_id rejected before querying reports' );
        }
        foreach ( array( array(), array( 'before_id' => $ids[56] ), array( 'scan_id' => '../scan' ), array( 'scan_id' => array( $scan_id ) ) ) as $invalid ) {
            al_check( 400 === $get( '/reports', $invalid )->get_status(), 'Missing or malformed scan_id rejected' );
        }
        al_check( 400 === $get( '/export', array( 'format' => 'xml' ) )->get_status(), 'Unsupported export format rejected' );
        al_check( 50 === count( $get( '/reports', array( 'scan_id' => $scan_id, 'per_page' => 99999, 'limit' => -1 ) )->get_data()['reports'] ), 'Unknown sizing parameters cannot lift the 50-report cap' );

        foreach ( array( $order->get_customer_id(), 0 ) as $unauthorized ) {
            wp_set_current_user( $unauthorized );
            foreach ( array(
                array( '/reports', $params ), array( '/reports', array( 'scan_id' => $scan_id ) ),
                array( '/export', array() ), array( '/export', array( 'format' => 'csv' ) ),
            ) as $route ) {
                $denied = $get( $route[0], $route[1] );
                al_check( ( $unauthorized ? 403 : 401 ) === $denied->get_status() && ! isset( $denied->get_data()['reports'] ), 'Unauthorized pagination/export discloses no reports' );
            }
        }
        wp_set_current_user( $actor );

        $new_scan = Store::scan_start( 30 );
        al_check( ! is_wp_error( $new_scan ) && $scan_id !== $new_scan['id'], 'Starting a real scan changes the scan ID' );
        $stale = $get( '/reports', $params );
        al_check( 409 === $stale->get_status() && 'access_ledger_scan_changed' === $stale->get_data()['code'], 'A new scan invalidates an old cursor even while its reports are retained' );
        al_check( array() === $get( '/dashboard' )->get_data()['reports'], 'New scan dashboard never includes prior-scan findings' );
        Store::scan_cancel();
        update_option( Store::STATE, $state, false );

        foreach ( array( '/reports', '/dashboard', '/export' ) as $route ) {
            $changed = false;
            $race = function ( $sql ) use ( &$changed, $table, $state, $other_scan ) {
                if ( ! $changed && false !== strpos( $sql, 'SELECT id, report FROM ' . $table ) ) {
                    $changed = true;
                    update_option( Store::STATE, array_merge( $state, array( 'id' => $other_scan ) ), false );
                }
                return $sql;
            };
            add_filter( 'query', $race, PHP_INT_MAX );
            try { $raced = $get( $route, $params ); }
            finally { remove_filter( 'query', $race, PHP_INT_MAX ); update_option( Store::STATE, $state, false ); }
            al_check( $changed && 409 === $raced->get_status(), 'Scan replacement during report SELECT is rejected: ' . $route );
        }

        for ( $i = 107; $i <= 1007; ++$i ) { $insert( $i ); }
        $json = $validate( $get( '/export' ), 'export' );
        al_check( 1000 === count( $json['reports'] ) && 900001007 === $json['reports'][0]['order_id'], 'JSON export caps 1,005 current reports at the newest 1,000' );
        $csv_response = $get( '/export', array( 'format' => 'csv' ) );
        $csv = $validate( $csv_response, 'export' );
        $csv_rows = $parse_csv( $csv );
        al_check( array_column( $json['reports'], 'order_id' ) === array_map( 'intval', array_column( $csv_rows, 'order_id' ) ), 'CSV and JSON export exactly the same latest-scan capped dataset in the same order' );
        al_check( '1000' === $csv_response->get_headers()['X-Access-Ledger-Report-Count'] && 'text/csv; charset=utf-8' === $csv_response->get_headers()['Content-Type'], 'CSV provides type and capped report count headers' );
        al_check( 0 === strpos( $csv_response->get_headers()['Content-Disposition'], 'attachment; filename="access-ledger-' ), 'CSV has a fixed safe attachment filename' );
        al_check( false === strpos( $csv . wp_json_encode( $json ), 'private-order-key-sentinel' ) && false === strpos( $csv . wp_json_encode( $json ), 'private-file-sentinel' ), 'Both formats preserve the public report allowlist' );
        al_check( $json['reports'][0]['issues'] === json_decode( $csv_rows[0]['issues'], true ) && $json['reports'][0]['permissions'] === json_decode( $csv_rows[0]['permissions'], true ), 'Nested findings and permissions round-trip as JSON CSV cells' );

        $values = array(
            '=1+1', '+SUM(1,2)', '-1+1', '@SUM(1,2)', " \t=1+1", "\r\n+1+1", "\0\x01-1+1", "\x7f@SUM(1,2)",
            "\u{00a0}=1+1", "\u{feff}\u{200b}@SUM(1,2)", 'ordinary text', "  ordinary text", "commas, quotes \" and\nnewlines\r\nend", "backslash\\\"quote", "'=already literal", '',
        );
        foreach ( $values as $i => $value ) {
            $report = $template;
            $report['order_id'] = 900001007 - $i;
            foreach ( array( 'order_number', 'status', 'customer_email', 'order_url', 'checked_at' ) as $field ) { $report[ $field ] = $value; }
            $wpdb->update( $table, array( 'report' => wp_json_encode( $report ) ), array( 'scan_id' => $scan_id, 'order_id' => $report['order_id'] ) );
        }
        $safe_csv = $get( '/export', array( 'format' => 'csv' ) )->get_data();
        $safe_rows = $parse_csv( $safe_csv );
        foreach ( $values as $i => $value ) {
            foreach ( array( 'order_number', 'status', 'customer_email', 'order_url', 'checked_at' ) as $field ) {
                al_check( ( $i < 10 ? "'" . $value : $value ) === $safe_rows[ $i ][ $field ], 'CSV formula prefix/quotes/newlines round-trip: case ' . $i . ', ' . $field );
            }
        }
        al_check( '=1+1' === $get( '/export' )->get_data()['reports'][0]['order_number'], 'CSV safety does not mutate stored reports or JSON values' );
        $request = new WP_REST_Request( 'GET', '/access-ledger/v1/export' );
        $request->set_query_params( array( 'format' => 'csv' ) );
        al_check( 10 === has_filter( 'rest_pre_serve_request', array( API::class, 'serve_csv' ) ), 'Raw CSV serving is registered with WordPress' );
        ob_start();
        $served = API::serve_csv( false, $csv_response, $request );
        $body = ob_get_clean();
        al_check( true === $served && $csv === $body, 'REST serving emits raw CSV rather than a JSON-quoted string' );
        foreach ( array( 'HEAD', 'error', 'already-served' ) as $mode ) {
            $request->set_method( 'HEAD' === $mode ? 'HEAD' : 'GET' );
            ob_start();
            $handled = API::serve_csv( 'already-served' === $mode, 'error' === $mode ? new WP_REST_Response( array( 'code' => 'denied' ), 403 ) : $csv_response, $request );
            $body = ob_get_clean();
            al_check( '' === $body && ( 'error' !== $mode ) === $handled, 'CSV serving respects ' . $mode );
        }

        $fault = function ( $sql ) use ( $table ) {
            return false !== strpos( $sql, 'SELECT id, report FROM ' . $table ) ? 'SELECT * FROM access_ledger_missing_reporting_table' : $sql;
        };
        $suppressed = $wpdb->suppress_errors( true );
        add_filter( 'query', $fault, PHP_INT_MAX );
        try {
            foreach ( array( '/reports', '/dashboard', '/export' ) as $route ) {
                al_check( 500 === $get( $route, $params )->get_status(), 'Database failure is not reported as an empty successful result: ' . $route );
            }
        } finally { remove_filter( 'query', $fault, PHP_INT_MAX ); $wpdb->suppress_errors( $suppressed ); }
        delete_option( Store::STATE );
        al_check( array() === $get( '/dashboard' )->get_data()['reports'] && null === $get( '/dashboard' )->get_data()['pagination']['scan_id'], 'No scan produces an empty dashboard and null scan ID' );
        al_check( array() === $get( '/export' )->get_data()['reports'] && array() === $parse_csv( $get( '/export', array( 'format' => 'csv' ) )->get_data() ), 'No scan exports empty JSON and a header-only CSV' );
        al_check( $permission_before === al_row( $permission_id ) && $audit_count === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}access_ledger_audits" ), 'Pagination and exports never mutate identity, allowances or correction audits' );
        echo 'ACCESS_LEDGER_REPORTING_RESULT: ' . ( $passed - $initial_passed ) . " passed; real REST dispatch and SQL with synthetic saved snapshots\n";
    } finally {
        wp_set_current_user( $actor );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE scan_id IN (%s, %s)", $scan_id, $other_scan ) );
        if ( false === $previous_state ) { delete_option( Store::STATE ); }
        else { update_option( Store::STATE, $previous_state, false ); }
    }
} )();
