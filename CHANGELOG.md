# Changelog

## 1.1.0

- Added Load older findings and `GET /reports` with exclusive `before_id` keyset pagination, a required stable `scan_id`, and at most 50 reports per response. Scan changes return HTTP 409; pages never combine scans.
- Added a `(scan_id, id)` report index and dashboard pagination metadata. Expanded findings persist during same-scan background refreshes; explicit Refresh returns to the newest page. Search and filters clearly apply only to loaded findings.
- Added user-triggered CSV export alongside JSON through `GET /export?format=csv`. Both use the same latest-scan dataset capped at 1,000 reports. CSV quotes/newlines round-trip, and formula-like values receive an apostrophe before any leading whitespace or controls.
- Routed admin requests through WordPress API Fetch's path middleware so query parameters work with both plain and pretty permalinks. Browser authorization checks require 401/403 rather than accepting a routing 404.
- Added integration coverage for pagination boundaries, concurrent inserts/deletes and scan replacement, access denial, export caps, formula injection and raw CSV serving; extended browser fixtures and checks beyond 50 findings.
- Preserved correction eligibility, identity checks, preview tokens, transactional writes, guarded undo and public-report field allowlists. No new dependencies.

## 1.0.0

- Added order-level explanations and resumable digital-download access audits.
- Added explicit uncertainty for missing/new files and intentional restrictions.
- Added operator-bound email previews, conditional corrections and guarded undo.
- Added audit history, JSON export, responsive accessible admin UI and integration tests.
