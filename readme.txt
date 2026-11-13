=== Access Ledger for WooCommerce ===
Contributors: memberfixstudio-ind
Tags: woocommerce, downloads, support, audit, permissions
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Requires Plugins: woocommerce
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Explain digital-download access issues and preview narrowly verified email corrections without resetting limits or logs.

== Description ==
Find the reason a customer cannot download a purchase. Inspect one order or scan recent orders in resumable batches, review dated findings, export JSON or CSV evidence, and apply a verified email-only correction with guarded undo.

Start with the latest 50 orders with findings. Load older findings adds up to 50 at a time from the same scan. Search and filters cover loaded orders only; Refresh returns to the latest page. A new scan discards the previous scan's loaded findings. JSON and CSV exports each include up to 1,000 reports from the latest scan, regardless of filters or loaded pages. CSV uses one row per report with nested findings and permissions in JSON cells; spreadsheet formula-like values receive a leading apostrophe.

Missing permissions, refunds, expired access, exhausted limits and ambiguous ownership remain review-only. Access Ledger never creates or revives permissions. It preserves download counts, remaining allowances, expiry, identifiers and logs.

Requires WooCommerce 10+ and transactional InnoDB tables for corrections. No external service, telemetry or customer email. Reports contain customer data, accessible only to users who manage WooCommerce. Reports are retained 30 days; corrections 90 days, cleaned by daily WP-Cron or scans. Exports contain personal data and must be handled securely. No usable download URLs or order keys appear in exports.

== Installation ==
1. Upload access-ledger.zip through Plugins > Add New > Upload Plugin.
2. Activate alongside WooCommerce.
3. Open WooCommerce > Access Ledger and enter an order ID or start a recent-order scan.

== Frequently Asked Questions ==
= Does it restore missing or revoked downloads? =
No. Their absence does not prove that restoring access is authorized.

= What changes during a correction? =
Only the email on one existing permission, when it belongs to the same registered customer, the key matches, and the order billing email equals that account's email.

= Does this support MemberPress or LearnDash? =
This release diagnoses WooCommerce digital downloads only.

= Does uninstall delete customer data? =
WooCommerce data is never deleted. Plugin records are preserved unless ACCESS_LEDGER_DELETE_DATA is defined as true before uninstall.

== Changelog ==
= 1.1.0 =
* Load older findings with bounded scan-specific keyset pagination and explicit loaded-search scope.
* CSV export alongside JSON, capped at 1,000 latest-scan reports with spreadsheet formula protection.
* Pagination, authorization, export serialization and browser integration coverage; identity and correction safety unchanged.

= 1.0.0 =
* Initial release: explanations, resumable scans, safe email previews, conditional corrections, audit and guarded undo.
