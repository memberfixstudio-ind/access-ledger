# Runtime contract

This document describes the shipped 1.0.0 implementation. See [research](RESEARCH.md) for the alternatives considered.

## Inspection and correction

`Engine::inspect(order_id)` reads live WooCommerce order and permission records. Reports include dated findings and permission allowances. They exclude order keys, file URLs and usable download links. A database read failure returns an error, never a fabricated missing-permission finding.

`Engine::preview(permission_id)` requires `manage_woocommerce` and an eligible email-only mismatch. A random token is stored in a ten-minute WordPress transient, bound to the operator, permission and HMAC of the observed identity context.

`Engine::repair(permission_id, token)` checks the live evidence again, locks the existing permission row, conditionally updates only `user_email`, and commits an audit record in the same InnoDB transaction. The account ID and order key must already agree; neither is changed. Missing permissions are never recreated. Refunds, expired/exhausted allowances, duplicate grants and ambiguous ownership prevent correction.

`Engine::undo(audit_id)` requires the original operator and unchanged order/permission identity. It restores only the previous email, retaining current counters. Audit records contain before/after email, account ID and a context fingerprint, never the order key itself.

## Scan and storage

`Store` owns two plugin tables: reports retained for 30 days and corrections retained for 90 days. Daily WP-Cron and new scans prune expired plugin records. No WooCommerce data is deleted.

Scans cover 1–365 days by order creation date. A persisted cursor, maximum order ID, time cutoff and renewable lock coordinate bounded batches of at most 20 orders, advanced by WP-Cron or the open dashboard. Concurrent deletions and backdated imports can shift pagination; rerun after imports settle. Physical-only and healthy orders count toward progress but do not clutter saved findings.

The dashboard shows up to 50 reports from the latest scan. JSON export returns up to 1,000. These caps are explicit; neither is a complete store history.

## API and interface

All routes under `access-ledger/v1` require `manage_woocommerce`. Cookie-authenticated REST requests use WordPress nonce validation. Read responses are private and not cacheable.

| Method | Route | Purpose |
|---|---|---|
| GET | `/dashboard` | Scan state, findings, recent audits and totals |
| POST | `/scan`, `/scan/step`, `/scan/cancel` | Start, advance or cancel a read-only scan |
| GET | `/orders/{id}` | Live order explanation |
| POST | `/permissions/{id}/preview` | Preview eligible email correction |
| POST | `/permissions/{id}/repair` | Apply with the preview token |
| POST | `/audits/{id}/undo` | Guarded reversal by the original operator |
| GET | `/export` | Bounded JSON report export |

Assets load only on WooCommerce → Access Ledger. The interface uses WordPress API Fetch, native dialogs and scoped JavaScript/CSS; customer values are rendered as text. There is no build dependency, public webhook, external service, telemetry or customer email delivery.
