<?php
namespace AccessLedger;
defined( 'ABSPATH' ) || exit;

/** Private reports, transactional audit records, and a resumable read-only scanner. */
class Store {
    const STATE = 'access_ledger_scan';
    const LOCK = 'access_ledger_scan_lock';
    const SCAN_HOOK = 'access_ledger_scan_step';
    const CLEANUP_HOOK = 'access_ledger_cleanup';
    const BATCH = 20;
    const LEASE = 120;

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}access_ledger_reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id varchar(36) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            checked_at datetime NOT NULL,
            issue_count int(10) unsigned NOT NULL DEFAULT 0,
            repairable_count int(10) unsigned NOT NULL DEFAULT 0,
            report longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_order (scan_id,order_id),
            KEY scan_id_id (scan_id,id),
            KEY checked_at (checked_at)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}access_ledger_audits (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            permission_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            before_state longtext NOT NULL,
            after_state longtext NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            undone_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY order_id (order_id)
        ) ENGINE=InnoDB $charset;" );
        foreach ( array( 'reports', 'audits' ) as $suffix ) {
            $table = $wpdb->prefix . 'access_ledger_' . $suffix;
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
            if ( ! $status || 'InnoDB' !== $status['Engine'] ) {
                return self::error( __( 'Access Ledger requires InnoDB storage.', 'access-ledger' ), 500 );
            }
        }
        update_option( 'access_ledger_version', ACCESS_LEDGER_VERSION, false );
        return true;
    }

    public static function register() {
        add_action( self::SCAN_HOOK, array( __CLASS__, 'scan_step' ) );
        add_action( self::CLEANUP_HOOK, array( __CLASS__, 'prune' ) );
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
        $state = self::state();
        if ( $state && 'running' === $state['status'] ) {
            self::schedule();
        }
    }

    private static function error( $message, $status = 409 ) {
        return new \WP_Error( 'access_ledger_store', $message, array( 'status' => $status ) );
    }

    public static function record_audit( $permission_id, $order_id, $before_state, $after_state ) {
        global $wpdb;
        if ( $permission_id < 1 || $order_id < 1 || ! is_string( $before_state ) || ! is_string( $after_state ) || ! get_current_user_id() ) {
            return 0;
        }
        $ok = $wpdb->insert( $wpdb->prefix . 'access_ledger_audits', array(
            'permission_id' => $permission_id, 'order_id' => $order_id,
            'before_state' => $before_state, 'after_state' => $after_state,
            'actor_id' => get_current_user_id(), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ), array( '%d', '%d', '%s', '%s', '%d', '%s' ) );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    public static function audit( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}access_ledger_audits WHERE id = %d AND created_at >= %s",
            $id, gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS )
        ), ARRAY_A );
    }

    public static function mark_undone( $id ) {
        global $wpdb;
        return 1 === $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}access_ledger_audits SET undone_at = %s WHERE id = %d AND undone_at IS NULL",
            gmdate( 'Y-m-d H:i:s' ), $id
        ) );
    }

    public static function audits() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, permission_id, order_id, actor_id, created_at, undone_at FROM {$wpdb->prefix}access_ledger_audits WHERE created_at >= %s ORDER BY id DESC LIMIT 50",
            gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS )
        ), ARRAY_A );
        foreach ( $rows as &$row ) {
            foreach ( array( 'id', 'permission_id', 'order_id', 'actor_id' ) as $key ) {
                $row[ $key ] = (int) $row[ $key ];
            }
        }
        return $rows;
    }

    public static function prune() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}access_ledger_reports WHERE checked_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}access_ledger_audits WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
    }

    private static function state() {
        // Read uncached state after acquiring the shared cron/UI lock.
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE ) );
        $state = maybe_unserialize( $value );
        return is_array( $state ) ? $state : null;
    }

    private static function save_state( $state ) {
        if ( update_option( self::STATE, $state, false ) || self::state() === $state ) {
            return true;
        }
        return self::error( __( 'Could not save scan progress. Retry the batch.', 'access-ledger' ), 500 );
    }

    private static function lock() {
        global $wpdb;
        $lease = wp_generate_uuid4() . '|' . ( time() + self::LEASE );
        if ( add_option( self::LOCK, $lease, '', false ) ) {
            return $lease;
        }
        $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
        if ( ! is_string( $old ) || (int) substr( $old, strrpos( $old, '|' ) + 1 ) >= time() ) {
            return self::error( __( 'Another scan request is working. Try again shortly.', 'access-ledger' ) );
        }
        $changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $lease, self::LOCK, $old ) );
        wp_cache_delete( self::LOCK, 'options' );
        return 1 === $changed ? $lease : self::error( __( 'Another scan request acquired the lock.', 'access-ledger' ) );
    }

    private static function renew( &$lease ) {
        global $wpdb;
        $expires = (int) substr( $lease, strrpos( $lease, '|' ) + 1 );
        if ( $expires < time() ) { return false; }
        $next = substr( $lease, 0, strrpos( $lease, '|' ) ) . '|' . max( time() + self::LEASE, $expires + 1 );
        $ok = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $next, self::LOCK, $lease ) );
        wp_cache_delete( self::LOCK, 'options' );
        if ( 1 !== $ok ) { return false; }
        $lease = $next;
        return true;
    }

    private static function unlock( $lease ) {
        global $wpdb;
        // A timed-out worker must never release its successor's lock.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, $lease ) );
        wp_cache_delete( self::LOCK, 'options' );
    }

    private static function schedule() {
        if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
            return wp_schedule_single_event( time() + 30, self::SCAN_HOOK, array(), true );
        }
        return true;
    }

    private static function query( $state ) {
        return array(
            'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ),
            'date_created' => $state['since'] . '...' . $state['cutoff'],
            'orderby' => 'ID', 'order' => 'ASC', 'limit' => self::BATCH,
            'page' => $state['page'], 'return' => 'ids',
        );
    }

    public static function scan_start( $days = 30 ) {
        if ( ! is_int( $days ) || $days < 1 || $days > 365 ) {
            return self::error( __( 'Choose a whole number of days from 1 to 365.', 'access-ledger' ), 400 );
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return self::error( __( 'WooCommerce must be active.', 'access-ledger' ), 503 );
        }
        $lease = self::lock();
        if ( is_wp_error( $lease ) ) { return $lease; }
        try {
            $previous = self::state();
            if ( $previous && 'running' === $previous['status'] ) {
                return self::error( __( 'A scan is already running. Cancel it before starting another.', 'access-ledger' ) );
            }
            $now = time();
            $state = array(
                'id' => wp_generate_uuid4(), 'status' => 'running', 'processed' => 0,
                'page' => 1, 'cursor' => 0, 'max_id' => 0, 'days' => $days,
                'since' => $now - $days * DAY_IN_SECONDS, 'cutoff' => $now,
                'started_at' => gmdate( 'c', $now ), 'finished_at' => null,
            );
            $args = self::query( $state );
            $args['order'] = 'DESC';
            $args['limit'] = 1;
            $ids = wc_get_orders( $args );
            $state['max_id'] = $ids ? (int) reset( $ids ) : 0;
            if ( ! $state['max_id'] ) {
                $state['status'] = 'completed';
                $state['finished_at'] = gmdate( 'c' );
            }
            if ( ! self::renew( $lease ) ) { return self::error( __( 'The scan lock expired. Please retry.', 'access-ledger' ) ); }
            $saved = self::save_state( $state );
            if ( is_wp_error( $saved ) ) { return $saved; }
            wp_clear_scheduled_hook( self::SCAN_HOOK );
            if ( 'running' === $state['status'] ) { self::schedule(); }
            self::prune();
            return $state;
        } catch ( \Throwable $e ) {
            return self::error( __( 'Could not start the scan. Check WooCommerce and database availability.', 'access-ledger' ), 500 );
        } finally {
            self::unlock( $lease );
        }
    }

    public static function scan_step() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return self::error( __( 'WooCommerce must be active.', 'access-ledger' ), 503 );
        }
        $lease = self::lock();
        if ( is_wp_error( $lease ) ) {
            $state = self::state();
            if ( $state && 'running' === $state['status'] ) { self::schedule(); }
            return $lease;
        }
        $state = null;
        try {
            $state = self::state();
            if ( ! $state || 'running' !== $state['status'] ) { return $state; }
            // Schedule a recovery tick before work in case PHP stops mid-batch.
            self::schedule();
            $started = microtime( true );
            $ids = wc_get_orders( self::query( $state ) );
            $complete_page = true;
            foreach ( $ids as $id ) {
                $id = (int) $id;
                if ( $id > $state['max_id'] ) { break; }
                if ( $id <= $state['cursor'] ) { continue; }
                $report = Engine::inspect( $id );
                if ( is_wp_error( $report ) ) { throw new \RuntimeException( 'inspect' ); }
                if ( ! self::renew( $lease ) ) { return self::error( __( 'Scan ownership changed. Reload its progress.', 'access-ledger' ) ); }
                $saved = self::save_report( $report, $state['id'] );
                if ( is_wp_error( $saved ) ) { throw new \RuntimeException( 'report' ); }
                ++$state['processed'];
                $state['cursor'] = $id;
                $saved = self::save_state( $state );
                if ( is_wp_error( $saved ) ) { return $saved; }
                if ( microtime( true ) - $started >= 10 ) { $complete_page = false; break; }
            }
            if ( $complete_page ) { ++$state['page']; }
            if ( $state['cursor'] >= $state['max_id'] || ( $complete_page && count( $ids ) < self::BATCH ) ) {
                $state['status'] = 'completed';
                $state['finished_at'] = gmdate( 'c' );
                wp_clear_scheduled_hook( self::SCAN_HOOK );
            }
            // shortcut: Woo pagination can miss concurrent deletions/backdated inserts; rerun after imports settle.
            $saved = self::save_state( $state );
            return is_wp_error( $saved ) ? $saved : $state;
        } catch ( \Throwable $e ) {
            if ( $state && self::renew( $lease ) ) {
                $state['status'] = 'failed';
                $state['error'] = __( 'The scan stopped because an order could not be inspected or saved. Start a new scan after checking WooCommerce and database availability.', 'access-ledger' );
                $state['finished_at'] = gmdate( 'c' );
                self::save_state( $state );
                wp_clear_scheduled_hook( self::SCAN_HOOK );
            }
            return self::error( __( 'The scan stopped. Reload its progress for details.', 'access-ledger' ), 500 );
        } finally {
            self::unlock( $lease );
        }
    }

    public static function scan_cancel() {
        $lease = self::lock();
        if ( is_wp_error( $lease ) ) { return $lease; }
        try {
            $state = self::state();
            if ( $state && 'running' === $state['status'] ) {
                $state['status'] = 'cancelled';
                $state['finished_at'] = gmdate( 'c' );
                $saved = self::save_state( $state );
                if ( is_wp_error( $saved ) ) { return $saved; }
            }
            wp_clear_scheduled_hook( self::SCAN_HOOK );
            return $state;
        } finally {
            self::unlock( $lease );
        }
    }

    /** Allowlist the engine's public report; never persist download URLs or order keys. */
    public static function public_report( $report ) {
        $result = array_intersect_key( $report, array_flip( array( 'order_id', 'order_number', 'status', 'paid', 'customer_id', 'customer_email', 'order_url', 'checked_at' ) ) );
        foreach ( array(
            'issues' => array( 'code', 'severity', 'title', 'detail', 'permission_id', 'repairable' ),
            'permissions' => array( 'id', 'product_id', 'product_name', 'download_id', 'file_name', 'remaining', 'count', 'expires', 'email', 'user_id', 'key_matches' ),
        ) as $key => $fields ) {
            $result[ $key ] = array();
            foreach ( $report[ $key ] ?? array() as $row ) {
                $result[ $key ][] = array_intersect_key( $row, array_flip( $fields ) );
            }
        }
        return $result;
    }

    private static function save_report( $report, $scan_id ) {
        global $wpdb;
        $codes = array_column( $report['issues'], 'code' );
        $downloadable = ! empty( $report['permissions'] ) || in_array( 'missing_permission', $codes, true );
        if ( ! $downloadable || empty( $report['issues'] ) || in_array( 'no_downloads', $codes, true ) ) {
            return true;
        }
        $report = self::public_report( $report );
        $json = wp_json_encode( $report );
        if ( false === $json ) { return self::error( __( 'Could not encode a report.', 'access-ledger' ), 500 ); }
        $repairable = count( array_filter( $report['issues'], function ( $issue ) { return ! empty( $issue['repairable'] ); } ) );
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}access_ledger_reports (scan_id, order_id, checked_at, issue_count, repairable_count, report) VALUES (%s, %d, %s, %d, %d, %s) ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), issue_count = VALUES(issue_count), repairable_count = VALUES(repairable_count), report = VALUES(report)",
            $scan_id, $report['order_id'], gmdate( 'Y-m-d H:i:s' ), count( $report['issues'] ), $repairable, $json
        ) );
        return false === $ok ? self::error( __( 'Could not save a report.', 'access-ledger' ), 500 ) : true;
    }

    private static function same_scan( $scan_id ) {
        if ( ( self::state()['id'] ?? null ) !== $scan_id ) {
            return new \WP_Error( 'access_ledger_scan_changed', __( 'The latest scan changed. Refresh the findings before loading more.', 'access-ledger' ), array( 'status' => 409 ) );
        }
        return true;
    }

    private static function report_rows( $scan_id, $limit, $before_id = 0 ) {
        global $wpdb;
        if ( null === $scan_id ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, report FROM {$wpdb->prefix}access_ledger_reports WHERE scan_id = %s AND checked_at >= %s"
                . ( $before_id ? $wpdb->prepare( ' AND id < %d', $before_id ) : '' ) . ' ORDER BY id DESC LIMIT %d',
            $scan_id, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), max( 1, min( 1000, (int) $limit ) )
        ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::error( __( 'Saved findings could not be read. Please retry.', 'access-ledger' ), 500 ); }
        foreach ( $rows as &$row ) {
            $report = json_decode( $row['report'], true );
            if ( ! is_array( $report ) ) { return self::error( __( 'A saved finding could not be decoded.', 'access-ledger' ), 500 ); }
            $row['report'] = self::public_report( $report );
        }
        return $rows;
    }

    public static function report_page( $scan_id, $before_id = 0 ) {
        $valid = self::same_scan( $scan_id );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $rows = self::report_rows( $scan_id, 51, $before_id );
        if ( is_wp_error( $rows ) ) { return $rows; }
        $valid = self::same_scan( $scan_id );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $has_more = count( $rows ) > 50;
        if ( $has_more ) { array_pop( $rows ); }
        return array(
            'scan_id' => $scan_id, 'reports' => array_column( $rows, 'report' ),
            'has_more' => $has_more, 'next_before_id' => $has_more ? (int) end( $rows )['id'] : null,
        );
    }

    public static function reports( $limit = 50 ) {
        $scan_id = self::state()['id'] ?? null;
        $rows = self::report_rows( $scan_id, $limit );
        if ( is_wp_error( $rows ) ) { return $rows; }
        $valid = self::same_scan( $scan_id );
        return is_wp_error( $valid ) ? $valid : array_column( $rows, 'report' );
    }

    public static function dashboard() {
        global $wpdb;
        $state = self::state();
        $page = self::report_page( $state['id'] ?? null );
        if ( is_wp_error( $page ) ) { return $page; }
        $counts = $state ? $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(issue_count), 0) AS issues, COALESCE(SUM(repairable_count), 0) AS repairable FROM {$wpdb->prefix}access_ledger_reports WHERE scan_id = %s AND checked_at >= %s",
            $state['id'], gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
        ), ARRAY_A ) : array();
        if ( $state && $wpdb->last_error ) { return self::error( __( 'Saved finding totals could not be read. Please retry.', 'access-ledger' ), 500 ); }
        $valid = self::same_scan( $state['id'] ?? null );
        if ( is_wp_error( $valid ) ) { return $valid; }
        $reports = $page['reports'];
        unset( $page['reports'] );
        return array(
            'scan' => $state, 'reports' => $reports, 'pagination' => $page, 'audits' => self::audits(),
            'totals' => array( 'orders' => $state ? (int) $state['processed'] : 0, 'issues' => (int) ( $counts['issues'] ?? 0 ), 'repairable' => (int) ( $counts['repairable'] ?? 0 ) ),
        );
    }
}
