<?php
namespace AccessLedger;
defined( 'ABSPATH' ) || exit;

/** Read-only explanations and deliberately narrow, conditional corrections. */
class Engine {
    private static function error( $message, $status = 409 ) {
        return new \WP_Error( 'access_ledger_conflict', $message, array( 'status' => $status ) );
    }
    private static function rows( $order_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d ORDER BY permission_id", $order_id ), ARRAY_A );
        return $wpdb->last_error ? null : $rows;
    }
    private static function row( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE permission_id = %d", $id ), ARRAY_A );
    }
    private static function issue( &$issues, $code, $severity, $title, $detail, $id = 0, $repairable = false ) {
        $issues[] = compact( 'code', 'severity', 'title', 'detail', 'repairable' ) + array( 'permission_id' => (int) $id );
    }
    private static function allowed( $order ) {
        return $order->is_paid() && $order->is_download_permitted() && ! count( $order->get_refunds() );
    }
    private static function valid_allowance( $row ) {
        return ( '' === $row['downloads_remaining'] || (int) $row['downloads_remaining'] > 0 )
            && ( ! $row['access_expires'] || '0000-00-00 00:00:00' === $row['access_expires'] || strtotime( $row['access_expires'] . ' UTC' ) >= strtotime( 'midnight', time() ) );
    }
    private static function purchased( $order, $product_id ) {
        foreach ( $order->get_items() as $item ) {
            if ( (int) ( $item->get_variation_id() ?: $item->get_product_id() ) === (int) $product_id ) {
                return true;
            }
        }
        return false;
    }
    /** Identity must already agree everywhere except for the permission's email. */
    private static function repairable( $row, $order, $rows ) {
        if ( ! is_array( $rows ) || ! self::allowed( $order ) || ! self::valid_allowance( $row ) || ! self::purchased( $order, $row['product_id'] ) ) {
            return false;
        }
        $user = get_userdata( $order->get_customer_id() );
        if ( ! $user || (int) $row['user_id'] !== (int) $user->ID || $row['order_key'] !== $order->get_order_key()
            || $user->user_email !== $order->get_billing_email() || ! is_email( $user->user_email )
            || $row['user_email'] === $user->user_email ) {
            return false;
        }
        $product = wc_get_product( $row['product_id'] );
        if ( ! $product || ! $product->is_downloadable() || ! isset( $product->get_downloads()[ $row['download_id'] ] ) ) {
            return false;
        }
        $matches = array_filter( $rows, function ( $r ) use ( $row ) { return $r['product_id'] === $row['product_id'] && $r['download_id'] === $row['download_id']; } );
        return count( $matches ) === 1;
    }
    public static function inspect( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order instanceof \WC_Order ) {
            return self::error( __( 'Order not found.', 'access-ledger' ), 404 );
        }
        $issues = array();
        $rows = self::rows( $order_id );
        if ( ! is_array( $rows ) ) { return self::error( __( 'Download permissions could not be read. Check database availability.', 'access-ledger' ), 500 ); }
        $permissions = array();
        $expected = array();
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                self::issue( $issues, 'orphan_product', 'review', __( 'Product no longer exists', 'access-ledger' ), __( 'The purchased product was deleted. Its original files cannot be inferred.', 'access-ledger' ) );
                continue;
            }
            if ( ! $product->is_downloadable() ) { continue; }
            foreach ( $product->get_downloads() as $file_id => $file ) {
                $expected[ $product->get_id() . ':' . $file_id ] = $file->get_name();
            }
        }
        if ( ( $rows || $expected ) && ! $order->is_download_permitted() ) {
            self::issue( $issues, 'order_blocked', 'expected', __( 'Order currently blocks downloads', 'access-ledger' ), __( 'Check the order status and the store’s “Grant access after payment” setting. This can be an intentional restriction.', 'access-ledger' ) );
        }
        if ( count( $order->get_refunds() ) ) {
            self::issue( $issues, 'refunded', 'review', __( 'Refund requires review', 'access-ledger' ), __( 'This order has a partial or full refund. Corrections are disabled to avoid changing refunded access.', 'access-ledger' ) );
        }
        $seen = array();
        foreach ( $rows as $row ) {
            $id = (int) $row['permission_id'];
            $key = $row['product_id'] . ':' . $row['download_id'];
            $seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;
            $product = wc_get_product( $row['product_id'] );
            $file = $product ? ( $product->get_downloads()[ $row['download_id'] ] ?? null ) : null;
            $permissions[] = array( 'id' => $id, 'product_id' => (int) $row['product_id'], 'product_name' => $product ? $product->get_name() : __( 'Deleted product', 'access-ledger' ), 'download_id' => $row['download_id'], 'file_name' => $file ? $file->get_name() : __( 'Removed file', 'access-ledger' ), 'remaining' => $row['downloads_remaining'], 'count' => (int) $row['download_count'], 'expires' => $row['access_expires'], 'email' => $row['user_email'], 'user_id' => (int) $row['user_id'], 'key_matches' => $row['order_key'] === $order->get_order_key() );
            if ( ! $file || ! self::purchased( $order, $row['product_id'] ) || ! $product->is_downloadable() ) {
                self::issue( $issues, 'removed_file', 'review', __( 'File or purchased product changed', 'access-ledger' ), __( 'The permission no longer matches a current purchased download. No new file will be granted automatically.', 'access-ledger' ), $id );
            }
            if ( $row['user_email'] !== $order->get_billing_email() || (int) $row['user_id'] !== $order->get_customer_id() || $row['order_key'] !== $order->get_order_key() ) {
                self::issue( $issues, 'identity_mismatch', 'review', __( 'Download identity differs from the order', 'access-ledger' ), __( 'Email, account or order-key differences can cause rejected links. Only verified email-only differences qualify for a correction.', 'access-ledger' ), $id, self::repairable( $row, $order, $rows ) );
            }
            if ( '' !== $row['downloads_remaining'] && (int) $row['downloads_remaining'] <= 0 ) {
                self::issue( $issues, 'exhausted', 'expected', __( 'Download allowance used up', 'access-ledger' ), __( 'This is an expected restriction. Access Ledger does not reset allowances.', 'access-ledger' ), $id );
            }
            if ( $row['access_expires'] && '0000-00-00 00:00:00' !== $row['access_expires'] && strtotime( $row['access_expires'] . ' UTC' ) < strtotime( 'midnight', time() ) ) {
                self::issue( $issues, 'expired', 'expected', __( 'Download access expired', 'access-ledger' ), __( 'This permission has reached its expiry. Access Ledger does not extend access.', 'access-ledger' ), $id );
            }
        }
        foreach ( $expected as $key => $name ) {
            if ( empty( $seen[ $key ] ) ) {
                self::issue( $issues, 'missing_permission', 'review', __( 'Current file has no permission', 'access-ledger' ), sprintf( __( '%s: this may be an intentional revocation or a file added after purchase. Purchase-time entitlement is unknown. Review the order; no permission will be recreated.', 'access-ledger' ), $name ) );
            }
        }
        foreach ( $seen as $key => $count ) {
            if ( $count > 1 ) {
                self::issue( $issues, 'duplicate_permission', 'review', __( 'Duplicate file permissions', 'access-ledger' ), __( 'More than one permission exists for the same order, product and file. Resolve the ambiguity manually.', 'access-ledger' ) );
            }
        }
        if ( ! $rows && ! $expected && ! $issues ) {
            self::issue( $issues, 'no_downloads', 'info', __( 'No digital downloads found', 'access-ledger' ), __( 'This order contains no current downloadable files or permission records.', 'access-ledger' ) );
        }
        return array( 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'status' => $order->get_status(), 'paid' => $order->is_paid(), 'customer_id' => $order->get_customer_id(), 'customer_email' => $order->get_billing_email(), 'order_url' => $order->get_edit_order_url(), 'checked_at' => gmdate( 'c' ), 'issues' => $issues, 'permissions' => $permissions );
    }
    private static function context( $row, $order ) {
        $product = wc_get_product( $row['product_id'] );
        $user = get_userdata( $order->get_customer_id() );
        return hash_hmac( 'sha256', wp_json_encode( array( $row['permission_id'], $row['product_id'], $row['download_id'], $row['order_id'], $row['order_key'], $row['user_id'], $order->get_customer_id(), $order->get_billing_email(), $order->get_order_key(), $order->get_status(), $order->is_download_permitted(), count( $order->get_refunds() ), $user ? $user->user_email : '', $product ? array_keys( $product->get_downloads() ) : array(), self::purchased( $order, $row['product_id'] ) ) ), wp_salt( 'auth' ) );
    }
    public static function preview( $permission_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( __( 'Not authorized.', 'access-ledger' ), 403 ); }
        $row = self::row( $permission_id );
        $order = $row ? wc_get_order( $row['order_id'] ) : false;
        if ( ! $order || ! self::repairable( $row, $order, self::rows( $row['order_id'] ) ) ) {
            return self::error( __( 'This permission cannot be safely corrected. Inspect the current order.', 'access-ledger' ) );
        }
        $token = bin2hex( random_bytes( 24 ) );
        set_transient( 'al_preview_' . $token, array( 'actor' => get_current_user_id(), 'id' => (int) $permission_id, 'email' => $row['user_email'], 'context' => self::context( $row, $order ) ), 600 );
        return array( 'token' => $token, 'permission_id' => (int) $permission_id, 'order_id' => (int) $row['order_id'], 'before' => array( 'email' => $row['user_email'], 'user_id' => (int) $row['user_id'], 'key_matches' => true ), 'after' => array( 'email' => $order->get_billing_email(), 'user_id' => (int) $row['user_id'], 'key_matches' => true ), 'expires_at' => gmdate( 'c', time() + 600 ), 'explanation' => __( 'Only the permission email will change to match the same registered customer and order. Download counts, limits, expiry and logs stay intact. Existing links using the old email may stop working; use My Account → Downloads afterward.', 'access-ledger' ) );
    }
    /** Narrow SQL avoids WC_Data::save overwriting concurrently consumed allowances. */
    private static function swap( $row, $email ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}woocommerce_downloadable_product_permissions SET user_email = %s WHERE permission_id = %d AND BINARY user_email = BINARY %s AND user_id = %d AND order_id = %d AND product_id = %d AND download_id = %s AND order_key = %s AND (downloads_remaining = '' OR CAST(downloads_remaining AS SIGNED) > 0) AND (access_expires IS NULL OR access_expires = '0000-00-00 00:00:00' OR access_expires >= %s)", $email, $row['permission_id'], $row['user_email'], $row['user_id'], $row['order_id'], $row['product_id'], $row['download_id'], $row['order_key'], gmdate( 'Y-m-d 00:00:00' ) ) );
    }
    private static function transactional() {
        global $wpdb;
        foreach ( array( 'woocommerce_downloadable_product_permissions', 'access_ledger_audits' ) as $suffix ) {
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->prefix . $suffix ), ARRAY_A );
            if ( ! $status || 'InnoDB' !== $status['Engine'] ) { return false; }
        }
        return true;
    }
    public static function repair( $permission_id, $token ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( __( 'Not authorized.', 'access-ledger' ), 403 ); }
        if ( ! preg_match( '/^[a-f0-9]{48}$/D', $token ) ) { return self::error( __( 'Invalid preview.', 'access-ledger' ) ); }
        $preview = get_transient( 'al_preview_' . $token );
        if ( ! $preview || $preview['actor'] !== get_current_user_id() || $preview['id'] !== (int) $permission_id ) { return self::error( __( 'Preview expired or belongs to another operator.', 'access-ledger' ) ); }
        if ( ! self::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( __( 'Corrections require transactional InnoDB tables.', 'access-ledger' ), 503 ); }
        // Lock the permission, never the counters copied into an earlier preview.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE permission_id = %d FOR UPDATE", $permission_id ), ARRAY_A );
        $order = $row ? wc_get_order( $row['order_id'] ) : false;
        if ( ! $order || $preview['email'] !== $row['user_email'] || ! hash_equals( $preview['context'], self::context( $row, $order ) ) || ! self::repairable( $row, $order, self::rows( $row['order_id'] ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( __( 'Evidence changed. Open a fresh preview; nothing was changed.', 'access-ledger' ) );
        }
        $before = array( 'user_email' => $row['user_email'], 'user_id' => (int) $row['user_id'], 'context' => $preview['context'] );
        $after = array( 'user_email' => $order->get_billing_email(), 'user_id' => (int) $row['user_id'], 'context' => $preview['context'] );
        if ( 1 !== self::swap( $row, $after['user_email'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Permission changed during correction.', 'access-ledger' ) ); }
        $audit_id = Store::record_audit( $permission_id, $row['order_id'], wp_json_encode( $before ), wp_json_encode( $after ) );
        if ( ! $audit_id ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Could not save the audit trail. Correction rolled back.', 'access-ledger' ), 500 ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Database commit failed. Recheck current state before retrying.', 'access-ledger' ), 500 ); }
        delete_transient( 'al_preview_' . $token );
        return array( 'audit_id' => $audit_id, 'message' => __( 'Permission email corrected. Download history preserved.', 'access-ledger' ) );
    }
    public static function undo( $audit_id ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( __( 'Not authorized.', 'access-ledger' ), 403 ); }
        $audit = Store::audit( $audit_id );
        if ( ! $audit || (int) $audit['actor_id'] !== get_current_user_id() || ! empty( $audit['undone_at'] ) ) { return self::error( __( 'Correction not found or already reversed.', 'access-ledger' ) ); }
        $before = json_decode( $audit['before_state'], true );
        $after = json_decode( $audit['after_state'], true );
        if ( ! self::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( __( 'Corrections require transactional InnoDB tables.', 'access-ledger' ), 503 ); }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE permission_id = %d FOR UPDATE", $audit['permission_id'] ), ARRAY_A );
        $order = $row ? wc_get_order( $row['order_id'] ) : false;
        if ( ! $order || $row['user_email'] !== $after['user_email'] || ! hash_equals( $after['context'], self::context( $row, $order ) ) || ! self::allowed( $order ) ) {
            $wpdb->query( 'ROLLBACK' );
            return self::error( __( 'The order or permission changed since correction. Undo refused.', 'access-ledger' ) );
        }
        if ( 1 !== self::swap( $row, $before['user_email'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Permission changed during undo.', 'access-ledger' ) ); }
        if ( ! Store::mark_undone( $audit_id ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Could not record undo. Nothing was changed.', 'access-ledger' ), 500 ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Database commit failed. Recheck current state before retrying.', 'access-ledger' ), 500 ); }
        return array( 'message' => __( 'Previous permission email restored. Download history preserved.', 'access-ledger' ) );
    }
}
