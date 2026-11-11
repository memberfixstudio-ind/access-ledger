<?php
namespace AccessLedger;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private static $screen = '';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$screen = add_submenu_page(
			'woocommerce',
			__( 'Product Access Ledger', 'access-ledger' ),
			__( 'Access Ledger', 'access-ledger' ),
			'manage_woocommerce',
			'access-ledger',
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( ! self::$screen || self::$screen !== $hook ) {
			return;
		}
		wp_enqueue_style( 'access-ledger-admin', plugins_url( 'assets/admin.css', ACCESS_LEDGER_FILE ), array(), ACCESS_LEDGER_VERSION );
		wp_enqueue_script( 'access-ledger-admin', plugins_url( 'assets/admin.js', ACCESS_LEDGER_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), ACCESS_LEDGER_VERSION, true );
		wp_localize_script( 'access-ledger-admin', 'accessLedger', array(
			'root'  => rest_url( 'access-ledger/v1/' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
		wp_set_script_translations( 'access-ledger-admin', 'access-ledger', plugin_dir_path( ACCESS_LEDGER_FILE ) . 'languages' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		?>
		<div class="wrap al-app" id="access-ledger">
			<header class="al-header">
				<div>
					<p class="al-eyebrow"><span class="al-mark" aria-hidden="true">≡</span> <?php esc_html_e( 'WooCommerce / Store operations', 'access-ledger' ); ?></p>
					<h1><?php esc_html_e( 'Product Access Ledger', 'access-ledger' ); ?></h1>
					<p class="al-intro"><?php esc_html_e( 'Understand download access. Correct an email without resetting a customer’s limits.', 'access-ledger' ); ?></p>
				</div>
				<div class="al-actions">
					<button class="al-button al-button-quiet" id="al-refresh" type="button"><?php esc_html_e( 'Refresh', 'access-ledger' ); ?></button>
					<button class="al-button" id="al-export" type="button" disabled><?php esc_html_e( 'Export JSON', 'access-ledger' ); ?></button>
				</div>
			</header>
			<noscript><p class="al-notice"><?php esc_html_e( 'Enable JavaScript to review orders and run access checks. No customer access has been changed.', 'access-ledger' ); ?></p></noscript>
			<div id="al-error" class="al-notice al-notice-error" role="alert" hidden></div>
			<div id="al-notice" class="al-notice al-notice-success" role="status" aria-live="polite" aria-atomic="true"></div>
			<div id="al-announcement" class="al-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>

			<section class="al-stats" aria-label="<?php esc_attr_e( 'Saved audit totals', 'access-ledger' ); ?>">
				<div class="al-stat"><span class="al-label"><?php esc_html_e( 'Orders checked', 'access-ledger' ); ?></span><strong id="al-total-orders">—</strong><span class="al-muted"><?php esc_html_e( 'Processed in the latest scan', 'access-ledger' ); ?></span></div>
				<div class="al-stat"><span class="al-label"><?php esc_html_e( 'Findings to review', 'access-ledger' ); ?></span><strong id="al-total-issues">—</strong><span class="al-muted"><?php esc_html_e( 'Including access that has ended', 'access-ledger' ); ?></span></div>
				<div class="al-stat al-stat-accent"><span class="al-label"><?php esc_html_e( 'Email fixes available', 'access-ledger' ); ?></span><strong id="al-total-repairable">—</strong><span class="al-muted"><?php esc_html_e( 'Each change needs a fresh preview', 'access-ledger' ); ?></span></div>
			</section>

			<div class="al-workspace">
				<section class="al-panel" aria-labelledby="al-scan-heading">
					<div class="al-section-head"><div><p class="al-eyebrow"><?php esc_html_e( '01 / Review your store', 'access-ledger' ); ?></p><h2 id="al-scan-heading"><?php esc_html_e( 'Check download access', 'access-ledger' ); ?></h2></div><span id="al-scan-status" class="al-badge"><?php esc_html_e( 'Loading', 'access-ledger' ); ?></span></div>
					<p class="al-muted"><?php esc_html_e( 'Review orders in a recent date range. A scan never changes customer access.', 'access-ledger' ); ?></p>
					<form id="al-scan-form" class="al-inline-form">
						<div class="al-field"><label for="al-days"><?php esc_html_e( 'Order date range', 'access-ledger' ); ?></label><select id="al-days" name="days" disabled>
							<option value="7"><?php esc_html_e( 'Last 7 days', 'access-ledger' ); ?></option>
							<option value="30" selected><?php esc_html_e( 'Last 30 days', 'access-ledger' ); ?></option>
							<option value="90"><?php esc_html_e( 'Last 90 days', 'access-ledger' ); ?></option>
							<option value="365"><?php esc_html_e( 'Last 365 days', 'access-ledger' ); ?></option>
						</select></div>
						<button class="al-button al-button-primary" id="al-scan-start" type="submit" disabled><?php esc_html_e( 'Start scan', 'access-ledger' ); ?></button>
						<button class="al-button" id="al-scan-cancel" type="button" hidden><?php esc_html_e( 'Cancel scan', 'access-ledger' ); ?></button>
					</form>
					<div class="al-scan-progress"><progress id="al-progress" aria-label="<?php esc_attr_e( 'Order scan in progress', 'access-ledger' ); ?>" hidden></progress><p id="al-scan-summary" class="al-muted"><?php esc_html_e( 'Loading the latest scan…', 'access-ledger' ); ?></p><p id="al-scan-date" class="al-meta"></p></div>
				</section>
				<section class="al-panel" aria-labelledby="al-lookup-heading">
					<p class="al-eyebrow"><?php esc_html_e( '02 / Resolve a support request', 'access-ledger' ); ?></p><h2 id="al-lookup-heading"><?php esc_html_e( 'Check one order', 'access-ledger' ); ?></h2>
					<p class="al-muted"><?php esc_html_e( 'Get a fresh explanation of an order’s download permissions.', 'access-ledger' ); ?></p>
					<form id="al-lookup-form" class="al-inline-form" novalidate>
						<div class="al-field"><label for="al-order-id"><?php esc_html_e( 'Order ID', 'access-ledger' ); ?></label><input id="al-order-id" name="order_id" type="text" inputmode="numeric" pattern="[0-9]+" autocomplete="off" aria-describedby="al-order-help al-order-error" placeholder="<?php esc_attr_e( 'Enter an order ID', 'access-ledger' ); ?>"></div>
						<button class="al-button" id="al-lookup" type="submit"><?php esc_html_e( 'Explain access', 'access-ledger' ); ?></button>
					</form>
					<p id="al-order-help" class="al-meta"><?php esc_html_e( 'Use the numeric ID from the WooCommerce order URL.', 'access-ledger' ); ?></p><p id="al-order-error" class="al-field-error" hidden></p>
				</section>
			</div>

			<section class="al-panel al-explanation" id="al-explanation" aria-labelledby="al-explanation-title" hidden>
				<div class="al-section-head"><div><p class="al-eyebrow"><?php esc_html_e( 'Live order check', 'access-ledger' ); ?></p><h2 id="al-explanation-title" tabindex="-1"></h2></div><button class="al-button al-button-quiet" id="al-explanation-close" type="button"><?php esc_html_e( 'Close details', 'access-ledger' ); ?></button></div>
				<div id="al-explanation-content"></div>
			</section>

			<section class="al-panel al-reports" aria-labelledby="al-reports-heading">
				<div class="al-section-head"><div><p class="al-eyebrow"><?php esc_html_e( 'The access record', 'access-ledger' ); ?></p><h2 id="al-reports-heading"><?php esc_html_e( 'Orders with findings', 'access-ledger' ); ?></h2></div><span id="al-report-count" class="al-meta"></span></div>
				<p class="al-context"><?php esc_html_e( 'A missing permission may have been intentionally revoked. It is reported for review and will never be recreated here. Newly added files are not automatically owed to earlier purchasers.', 'access-ledger' ); ?></p>
				<div class="al-filters">
					<div class="al-field al-search"><label for="al-search"><?php esc_html_e( 'Search saved checks', 'access-ledger' ); ?></label><input type="search" id="al-search" name="search" autocomplete="off" placeholder="<?php esc_attr_e( 'Order, email or product', 'access-ledger' ); ?>" aria-describedby="al-report-scope"></div>
					<div class="al-field"><label for="al-filter"><?php esc_html_e( 'Show orders', 'access-ledger' ); ?></label><select id="al-filter"><option value="all"><?php esc_html_e( 'All orders with findings', 'access-ledger' ); ?></option><option value="repairable"><?php esc_html_e( 'Email fix available', 'access-ledger' ); ?></option></select></div>
					<div class="al-field"><label for="al-issue-filter"><?php esc_html_e( 'Finding type', 'access-ledger' ); ?></label><select id="al-issue-filter"><option value="all"><?php esc_html_e( 'All findings', 'access-ledger' ); ?></option></select></div>
				</div>
				<p id="al-report-scope" class="al-meta"><?php esc_html_e( 'The latest 50 orders with findings from the last scan. These snapshots stay unchanged until a new scan; open an order for a live check. Export includes up to 1,000 saved reports and does not use these filters.', 'access-ledger' ); ?></p>
				<div id="al-reports-content" aria-busy="true"><p class="al-empty"><?php esc_html_e( 'Loading saved checks…', 'access-ledger' ); ?></p></div>
			</section>

			<section class="al-panel" aria-labelledby="al-history-heading">
				<div class="al-section-head"><div><p class="al-eyebrow"><?php esc_html_e( 'Every change accounted for', 'access-ledger' ); ?></p><h2 id="al-history-heading" tabindex="-1"><?php esc_html_e( 'Correction history', 'access-ledger' ); ?></h2></div><span class="al-badge"><?php esc_html_e( 'Guarded undo', 'access-ledger' ); ?></span></div>
				<p class="al-muted"><?php esc_html_e( 'Undo restores the previous permission email only if the current state still allows it. Download counts, expiry and history stay unchanged.', 'access-ledger' ); ?></p><div id="al-history-content" aria-busy="true"><p class="al-empty"><?php esc_html_e( 'Loading correction history…', 'access-ledger' ); ?></p></div>
			</section>
			<footer class="al-footer"><span><?php esc_html_e( 'Observe first. Change only what you can verify.', 'access-ledger' ); ?></span><span><?php esc_html_e( 'Download permissions · Email-only corrections', 'access-ledger' ); ?></span></footer>

			<dialog id="al-repair-dialog" class="al-dialog" aria-labelledby="al-dialog-title" aria-describedby="al-dialog-description">
				<div class="al-dialog-head"><p class="al-eyebrow"><?php esc_html_e( 'Review before changing access', 'access-ledger' ); ?></p><button type="button" class="al-button al-button-quiet" id="al-dialog-close" autofocus><?php esc_html_e( 'Close', 'access-ledger' ); ?></button></div>
				<h2 id="al-dialog-title"><?php esc_html_e( 'Review email correction', 'access-ledger' ); ?></h2><p id="al-dialog-description" class="al-muted"></p>
				<div id="al-dialog-error" class="al-notice al-notice-error" role="alert" hidden></div><div id="al-dialog-content"></div>
				<form id="al-repair-form" novalidate hidden><div class="al-field"><label for="al-confirm-email"><?php esc_html_e( 'Type the new email to confirm', 'access-ledger' ); ?></label><input id="al-confirm-email" name="confirmation_email" type="text" inputmode="email" autocomplete="off" spellcheck="false" autocapitalize="none" aria-describedby="al-confirm-help al-confirm-error"><p id="al-confirm-help" class="al-meta"><?php esc_html_e( 'Enter the exact email shown above. This changes one existing permission only.', 'access-ledger' ); ?></p><p id="al-confirm-error" class="al-field-error" hidden></p></div><button class="al-button al-button-primary" id="al-repair-confirm" type="submit"><?php esc_html_e( 'Confirm email correction', 'access-ledger' ); ?></button></form>
				<div class="al-dialog-actions"><button class="al-button" id="al-preview-again" type="button" hidden><?php esc_html_e( 'Get a fresh preview', 'access-ledger' ); ?></button><button class="al-button" id="al-undo-confirm" type="button" hidden><?php esc_html_e( 'Undo email correction', 'access-ledger' ); ?></button><button class="al-button al-button-quiet" id="al-dialog-cancel" type="button"><?php esc_html_e( 'Cancel', 'access-ledger' ); ?></button></div>
			</dialog>
		</div>
		<?php
	}
}
