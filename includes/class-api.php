<?php
namespace AccessLedger;
defined( 'ABSPATH' ) || exit;

/** Capability-gated REST endpoints; cookie authentication uses WordPress's REST nonce. */
class API {
    const REST_NAMESPACE = 'access-ledger/v1';

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_csv' ), 10, 3 );
    }

    public static function permission() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error( 'access_ledger_forbidden', __( 'You cannot manage WooCommerce.', 'access-ledger' ), array( 'status' => rest_authorization_required_code() ) );
        }
        if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
            return new \WP_Error( 'access_ledger_unavailable', __( 'WooCommerce must be active.', 'access-ledger' ), array( 'status' => 503 ) );
        }
        return true;
    }

    private static function integer_arg( $max, $default = null ) {
        $arg = array(
            'type' => 'integer', 'minimum' => 1, 'maximum' => $max,
            'required' => null === $default,
            'validate_callback' => function ( $value ) use ( $max ) {
                return ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[1-9][0-9]*$/D', $value ) ) )
                    && false !== filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => $max ) ) );
            },
            'sanitize_callback' => function ( $value ) { return (int) $value; },
        );
        if ( null !== $default ) { $arg['default'] = $default; }
        return $arg;
    }

    public static function routes() {
        $id = array( 'id' => self::integer_arg( PHP_INT_MAX ) );
        $before_id = self::integer_arg( PHP_INT_MAX );
        $before_id['required'] = false;
        $routes = array(
            '/dashboard' => array( 'GET', 'dashboard', array(), 'dashboard' ),
            '/reports' => array( 'GET', 'reports', array(
                'scan_id' => array( 'type' => 'string', 'required' => true, 'minLength' => 36, 'maxLength' => 36,
                    'validate_callback' => function ( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value ); },
                ),
                'before_id' => $before_id,
            ), 'reports' ),
            '/scan' => array( 'POST', 'scan_start', array( 'days' => self::integer_arg( 365, 30 ) ), 'scan' ),
            '/scan/step' => array( 'POST', 'scan_step', array(), 'scan' ),
            '/scan/cancel' => array( 'POST', 'scan_cancel', array(), 'scan' ),
            '/orders/(?P<id>[1-9][0-9]*)' => array( 'GET', 'inspect', $id, 'report' ),
            '/permissions/(?P<id>[1-9][0-9]*)/preview' => array( 'POST', 'preview', $id, 'preview' ),
            '/permissions/(?P<id>[1-9][0-9]*)/repair' => array( 'POST', 'repair', $id + array( 'token' => array(
                'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 512,
                'validate_callback' => function ( $value ) { return is_string( $value ) && strlen( $value ) <= 512 && 1 === preg_match( '/^[A-Za-z0-9._~=-]+$/D', $value ); },
            ) ), 'mutation' ),
            '/audits/(?P<id>[1-9][0-9]*)/undo' => array( 'POST', 'undo', $id, 'mutation' ),
            '/export' => array( 'GET', 'export', array( 'format' => array( 'type' => 'string', 'enum' => array( 'json', 'csv' ), 'default' => 'json' ) ), 'export' ),
        );
        foreach ( $routes as $path => $route ) {
            list( $method, $callback, $args, $schema ) = $route;
            register_rest_route( self::REST_NAMESPACE, $path, array(
                'methods' => $method, 'callback' => array( __CLASS__, $callback ),
                'permission_callback' => array( __CLASS__, 'permission' ), 'args' => $args,
                'schema' => function () use ( $schema ) { return self::schema( $schema ); },
            ) );
        }
    }

    private static function response( $data ) {
        if ( is_wp_error( $data ) ) { return $data; }
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    public static function dashboard() { return self::response( Store::dashboard() ); }
    public static function reports( $request ) { return self::response( Store::report_page( $request['scan_id'], (int) $request['before_id'] ) ); }
    public static function scan_start( $request ) { return self::response( Store::scan_start( $request['days'] ) ); }
    public static function scan_step() { return self::response( Store::scan_step() ); }
    public static function scan_cancel() { return self::response( Store::scan_cancel() ); }

    public static function inspect( $request ) {
        $result = Engine::inspect( (int) $request['id'] );
        return self::response( is_wp_error( $result ) ? $result : Store::public_report( $result ) );
    }

    public static function preview( $request ) {
        $result = Engine::preview( (int) $request['id'] );
        if ( ! is_wp_error( $result ) ) {
            $result = array_intersect_key( $result, array_flip( array( 'token', 'permission_id', 'order_id', 'before', 'after', 'expires_at', 'explanation' ) ) );
            foreach ( array( 'before', 'after' ) as $key ) {
                $result[ $key ] = array_intersect_key( $result[ $key ], array_flip( array( 'email', 'user_id', 'key_matches' ) ) );
            }
        }
        return self::response( $result );
    }

    public static function repair( $request ) {
        return self::response( Engine::repair( (int) $request['id'], $request['token'] ) );
    }

    public static function undo( $request ) {
        $audit = Store::audit( (int) $request['id'] );
        if ( ! $audit ) {
            return new \WP_Error( 'access_ledger_audit_missing', __( 'This audit record was not found or has expired.', 'access-ledger' ), array( 'status' => 404 ) );
        }
        if ( (int) $audit['actor_id'] !== get_current_user_id() ) {
            return new \WP_Error( 'access_ledger_audit_actor', __( 'Only the administrator who made this correction can undo it.', 'access-ledger' ), array( 'status' => 403 ) );
        }
        return self::response( Engine::undo( (int) $request['id'] ) );
    }

    public static function export( $request ) {
        $reports = Store::reports( 1000 );
        if ( is_wp_error( $reports ) ) { return $reports; }
        if ( 'csv' !== $request['format'] ) {
            return self::response( array( 'generated_at' => gmdate( 'c' ), 'reports' => $reports ) );
        }
        $csv = self::csv( $reports );
        if ( is_wp_error( $csv ) ) { return $csv; }
        $response = self::response( $csv );
        $response->header( 'Content-Type', 'text/csv; charset=utf-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="access-ledger-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $response->header( 'X-Access-Ledger-Report-Count', (string) count( $reports ) );
        return $response;
    }

    private static function csv( $reports ) {
        $columns = array( 'order_id', 'order_number', 'status', 'paid', 'customer_id', 'customer_email', 'order_url', 'checked_at', 'issues', 'permissions' );
        $stream = fopen( 'php://temp', 'w+' );
        if ( false === $stream ) { return new \WP_Error( 'access_ledger_export', __( 'Could not prepare the export.', 'access-ledger' ), array( 'status' => 500 ) ); }
        try {
            if ( false === fputcsv( $stream, $columns, ',', '"', '', "\r\n" ) ) { throw new \RuntimeException( 'csv' ); }
            foreach ( $reports as $report ) {
                $cells = array();
                foreach ( $columns as $column ) {
                    $value = $report[ $column ] ?? '';
                    if ( is_array( $value ) || is_bool( $value ) ) { $value = wp_json_encode( $value ); }
                    if ( false === $value ) { throw new \RuntimeException( 'csv' ); }
                    $value = (string) $value;
                    // Prefix before any leading whitespace/control characters so spreadsheets cannot execute a formula.
                    $cells[] = preg_match( '/^[\p{Z}\p{C}\s]*[=+\-@]/u', $value ) ? "'" . $value : $value;
                }
                if ( false === fputcsv( $stream, $cells, ',', '"', '', "\r\n" ) ) { throw new \RuntimeException( 'csv' ); }
            }
            rewind( $stream );
            $csv = stream_get_contents( $stream );
            if ( false === $csv ) { throw new \RuntimeException( 'csv' ); }
            return $csv;
        } catch ( \Throwable $e ) {
            return new \WP_Error( 'access_ledger_export', __( 'Could not prepare the export.', 'access-ledger' ), array( 'status' => 500 ) );
        } finally {
            fclose( $stream );
        }
    }

    public static function serve_csv( $served, $response, $request ) {
        if ( ! $served && '/' . self::REST_NAMESPACE . '/export' === $request->get_route()
            && 'csv' === $request['format'] && 200 === $response->get_status()
            && 'text/csv; charset=utf-8' === ( $response->get_headers()['Content-Type'] ?? '' ) && is_string( $response->get_data() ) ) {
            if ( 'HEAD' !== $request->get_method() ) {
                // Already serialized and formula-safe; HTML escaping would corrupt the CSV download.
                echo $response->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            return true;
        }
        return $served;
    }

    private static function object_schema( $properties, $nullable = false ) {
        return array( 'type' => $nullable ? array( 'object', 'null' ) : 'object', 'properties' => $properties, 'additionalProperties' => false );
    }

    public static function schema( $name ) {
        $string = array( 'type' => 'string' );
        $number = array( 'type' => 'integer', 'minimum' => 0 );
        $bool = array( 'type' => 'boolean' );
        $date = array( 'type' => array( 'string', 'null' ) );
        $issue = self::object_schema( array(
            'code' => array( 'type' => 'string', 'enum' => array( 'identity_mismatch', 'missing_permission', 'expired', 'exhausted', 'removed_file', 'order_blocked', 'refunded', 'duplicate_permission', 'orphan_product', 'guest_login', 'no_downloads' ) ),
            'severity' => $string, 'title' => $string, 'detail' => $string, 'permission_id' => $number, 'repairable' => $bool,
        ) );
        $permission = self::object_schema( array(
            'id' => $number, 'product_id' => $number, 'product_name' => $string, 'download_id' => $string,
            'file_name' => $string, 'remaining' => array( 'type' => array( 'string', 'integer' ) ),
            'count' => $number, 'expires' => $date, 'email' => $string, 'user_id' => $number, 'key_matches' => $bool,
        ) );
        $report = self::object_schema( array(
            'order_id' => $number, 'order_number' => $string, 'status' => $string, 'paid' => $bool,
            'customer_id' => $number, 'customer_email' => $string, 'order_url' => $string, 'checked_at' => $string,
            'issues' => array( 'type' => 'array', 'items' => $issue ), 'permissions' => array( 'type' => 'array', 'items' => $permission ),
        ) );
        $scan = self::object_schema( array(
            'id' => $string, 'status' => array( 'type' => 'string', 'enum' => array( 'running', 'completed', 'cancelled', 'failed' ) ),
            'processed' => $number, 'page' => $number, 'cursor' => $number, 'max_id' => $number,
            'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365 ),
            'since' => $number, 'cutoff' => $number, 'started_at' => $string, 'finished_at' => $date, 'error' => $string,
        ), true );
        $identity = self::object_schema( array( 'email' => $string, 'user_id' => $number, 'key_matches' => $bool ) );
        $audit = self::object_schema( array( 'id' => $number, 'permission_id' => $number, 'order_id' => $number, 'actor_id' => $number, 'created_at' => $string, 'undone_at' => $date ) );
        $pagination = array(
            'scan_id' => array( 'type' => array( 'string', 'null' ) ), 'has_more' => $bool,
            'next_before_id' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
        );
        $schemas = array(
            'report' => $report, 'scan' => $scan,
            'reports' => self::object_schema( $pagination + array( 'reports' => array( 'type' => 'array', 'maxItems' => 50, 'items' => $report ) ) ),
            'dashboard' => self::object_schema( array(
                'scan' => $scan, 'reports' => array( 'type' => 'array', 'maxItems' => 50, 'items' => $report ),
                'pagination' => self::object_schema( $pagination ),
                'audits' => array( 'type' => 'array', 'maxItems' => 50, 'items' => $audit ),
                'totals' => self::object_schema( array( 'orders' => $number, 'issues' => $number, 'repairable' => $number ) ),
            ) ),
            'preview' => self::object_schema( array( 'token' => $string, 'permission_id' => $number, 'order_id' => $number, 'before' => $identity, 'after' => $identity, 'expires_at' => array( 'type' => array( 'string', 'integer' ) ), 'explanation' => $string ) ),
            'mutation' => self::object_schema( array( 'audit_id' => $number, 'message' => $string ) ),
            'export' => array( 'oneOf' => array(
                self::object_schema( array( 'generated_at' => $string, 'reports' => array( 'type' => 'array', 'maxItems' => 1000, 'items' => $report ) ) ),
                array( 'type' => 'string', 'description' => 'CSV attachment when format=csv; one row per report, at most 1000 reports.' ),
            ) ),
        );
        return array( '$schema' => 'http://json-schema.org/draft-04/schema#', 'title' => 'access-ledger-' . $name ) + $schemas[ $name ];
    }
}
