# Runtime contract

This document describes the 1.1.0 implementation. See [research](RESEARCH.md) for the alternatives considered and [validation](VALIDATION.md) for executed checks versus pending runtime suites.

## Inspection and correction

`Engine::inspect(order_id)` reads live WooCommerce order and permission records. Reports include dated findings and permission allowances. They exclude order keys, file URLs and usable download links. A database read failure returns an error, never a fabricated missing-permission finding.

`Engine::preview(permission_id)` requires `manage_woocommerce` and an eligible email-only mismatch. A random token is stored in a ten-minute WordPress transient, bound to the operator, permission and HMAC of the observed identity context.

`Engine::repair(permission_id, token)` checks the live evidence again, locks the existing permission row, conditionally updates only `user_email`, and commits an audit record in the same InnoDB transaction. The account ID and order key must already agree; neither is changed. Missing permissions are never recreated. Refunds, expired/exhausted allowances, duplicate grants and ambiguous ownership prevent correction.

`Engine::undo(audit_id)` requires the original operator and unchanged order/permission identity. It restores only the previous email, retaining current counters. Audit records contain before/after email, account ID and a context fingerprint, never the order key itself.

## Scan and storage

`Store` owns two plugin tables: reports retained for 30 days and corrections retained for 90 days. Daily WP-Cron and new scans prune expired plugin records. No WooCommerce data is deleted.

Scans cover 1–365 days by order creation date. A persisted cursor, maximum order ID, time cutoff and renewable lock coordinate bounded batches of at most 20 orders, advanced by WP-Cron or the open dashboard. Concurrent deletions and backdated imports can shift pagination; rerun after imports settle. Physical-only and healthy orders count toward progress but do not clutter saved findings.

The dashboard starts with up to 50 reports from the latest scan. Load older findings uses descending report-table IDs, an exclusive `before_id` boundary and the same `scan_id`. Every page contains at most 50 reports; the query reads at most 51 rows to determine whether another page exists. A `(scan_id, id)` index supports this query. Newer inserts do not shift older page boundaries; deleted rows are omitted without offset skips. Retention still applies on every request.

Search, finding type and repairability filters run only over loaded findings. Once older loading starts, background dashboard refreshes preserve those findings and their cursor while the scan ID is unchanged. Explicit Refresh resets to the newest page. A different scan ID discards the old list; stale page responses cannot append to a replacement list. There is no automatic load-all or server-side search.

JSON and CSV exports share the same latest-scan query, sorted by report-table ID descending and capped at 1,000 retained reports. They ignore UI filters and loaded pages. Neither pages nor exports represent a transactionally frozen store history. State changes detected during dashboard, page or export reads return HTTP 409 rather than relabeling another scan's reports.

## API and interface

All routes under `access-ledger/v1` require `manage_woocommerce`. Cookie-authenticated REST requests use WordPress nonce validation. Read responses are private and not cacheable.

| Method | Route | Purpose |
|---|---|---|
| GET | `/dashboard` | Scan state, findings, recent audits and totals |
| GET | `/reports?scan_id={uuid}&before_id={id}` | At most 50 older findings from the same latest scan |
| POST | `/scan`, `/scan/step`, `/scan/cancel` | Start, advance or cancel a read-only scan |
| GET | `/orders/{id}` | Live order explanation |
| POST | `/permissions/{id}/preview` | Preview eligible email correction |
| POST | `/permissions/{id}/repair` | Apply with the preview token |
| POST | `/audits/{id}/undo` | Guarded reversal by the original operator |
| GET | `/export` or `/export?format=json` | Bounded JSON report export (existing shape) |
| GET | `/export?format=csv` | Bounded UTF-8 CSV attachment |

### Pagination contract

`GET /dashboard` retains `scan`, `reports`, `audits` and `totals`, and adds:

```json
{
  "pagination": {
    "scan_id": "7f4e8cc2-2f83-4e78-aabb-e70a4fda2ec0",
    "has_more": true,
    "next_before_id": 123
  }
}
```

`scan_id` equals `scan.id`, or is `null` when no scan exists. `next_before_id` is the last returned report-table ID when `has_more` is true; it is `null` on the last/empty page. Report objects are unchanged. This cursor is not a WooCommerce order ID.

`GET /reports` accepts these query arguments:

| Parameter | Contract |
|---|---|
| `scan_id` | Required 36-character lowercase UUID returned by the dashboard or a previous page. It must still identify the latest scan. |
| `before_id` | Optional positive integer, at most PHP_INT_MAX. Pass `next_before_id` unchanged for the next page. Only report IDs strictly less than it are returned. Omit it for the newest page of the supplied scan. |

Response: `{ "scan_id": "…", "reports": [/* at most 50 unchanged public reports */], "has_more": true, "next_before_id": 123 }`. Page size is fixed; no parameter can request an unbounded response. `has_more` is evaluated at read time. The cursor need not still exist after retention/deletion, and its use never removes the `scan_id` constraint.

- Invalid/missing required arguments: HTTP **400**, standard WordPress REST validation errors.
- Well-formed scan ID that is no longer current (including a scan replaced during the read): HTTP **409**, code `access_ledger_scan_changed`. Discard loaded findings and fetch `/dashboard` again.
- Anonymous/customer access: HTTP **401/403**, respectively; cookie-authenticated reads require the WordPress REST nonce. The existing WooCommerce-availability gate also applies.
- Failed report reads/invalid stored JSON: HTTP **500**, code `access_ledger_store`; failures are not empty successful pages.

Successful dashboard/page/export responses retain `Cache-Control: private, no-store, max-age=0` and `X-Content-Type-Options: nosniff`.

### Export contract

`format` is an optional enum, `json` (default) or `csv`; invalid values return HTTP 400. Both formats remain capability-gated and user-triggered. Each export selects the current scan independently; separate requests can differ if the scan advances, changes or records expire between them.

JSON keeps `{ "generated_at": "UTC ISO-8601", "reports": [/* at most 1000 */] }`.

CSV returns raw CSV, not a JSON-encoded string, with `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="access-ledger-YYYY-MM-DD.csv"`, and `X-Access-Ledger-Report-Count` (0–1000). Errors retain the standard JSON REST error body. HEAD emits no body. A no-scan/empty dataset produces a header-only CSV.

Column order: `order_id, order_number, status, paid, customer_id, customer_email, order_url, checked_at, issues, permissions`. One row represents one report. Booleans use `true`/`false`; `issues` and `permissions` are full JSON arrays inside CSV cells. The same public-field allowlist excludes order keys, file URLs and usable download links.

CSV serialization uses comma delimiters, doubled double quotes, CRLF row endings and an empty proprietary escape character, preserving embedded quotes/newlines ([PHP fputcsv](https://www.php.net/manual/en/function.fputcsv.php)). Cells beginning with `=`, `+`, `-` or `@`, including after Unicode whitespace/separators/control/format characters, receive an apostrophe **before the original cell content**. This changes only CSV presentation; stored reports and JSON are unchanged. Raw attachment output uses WordPress's [REST serving filter](https://developer.wordpress.org/reference/hooks/rest_pre_serve_request/) only for successful CSV responses.

Assets load only on WooCommerce → Access Ledger. The interface uses WordPress API Fetch with namespace-relative `path` requests, allowing WordPress's root URL middleware to preserve query arguments under both plain and pretty permalinks. It uses native dialogs and scoped JavaScript/CSS; customer values are rendered as text. There is no build dependency, public webhook, external service, telemetry or customer email delivery.
