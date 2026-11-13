# Validation record

All fixtures use disposable stores and reserved example.test email addresses. No client data was used.

## 1.1.0 — 13 November 2026

| Environment / check | Observed result |
| --- | --- |
| WordPress 6.8.3 · WooCommerce 10.0.4 · PHP 8.2.29 · MariaDB 13.0.2 · legacy orders | **356 assertions passed** |
| WordPress 7.1.4 · WooCommerce 11.3.0 · PHP 8.3.36 · MariaDB 13.0.2 · legacy orders | **356 assertions passed** |
| Installable ZIP on a fresh WordPress 7.1.4 / WooCommerce 11.3.0 / PHP 8.3.36 / MariaDB 13.0.2 site, HPOS enabled before fixtures | Activation succeeded; **356 assertions passed** against the extracted runtime |
| Two independent PHP processes, current legacy runtime | Correction completed while another process attempted download consumption; final email, remaining allowance (4) and count (1) preserved |
| Chrome, actual admin and WooCommerce download handler | Native file request returned **403 → 200 → 403** before correction, after correction and after undo; successful response matched the fixture file exactly |
| Chrome, reporting UI with plain permalinks | Scan, bounded older findings, loaded search/filters, same-scan background refresh, explicit refresh, stale-scan recovery and CSV/JSON downloads passed |
| Browser authorization and mobile layout | Cookie requests without REST nonces returned 401/403; no JavaScript runtime errors; no horizontal page overflow at 390px |

Each integration run includes **37 base, 162 adversarial and 157 reporting assertions**. The reporting suite uses 105 synthetic saved snapshots for cursor boundaries and 1,005 for the export cap; those are not 1,005 separately created orders. The browser fixture creates 55 additional real WooCommerce orders to exercise findings beyond the first 50.

New reporting checks cover page boundaries, retries, inserts/deletes, stale and mid-read scan changes, authorization, strict arguments, raw CSV serving, formula-prefix escaping and database read failures. Pagination and export leave permissions and correction audits unchanged. The shared request helper uses WordPress's root-URL middleware so pagination and CSV queries work with plain and pretty permalinks; eight direct middleware checks covered root/subdirectory URLs.

All PHP and JavaScript syntax checks and `git diff --check` passed. A separate CSV self-check covered formula prefixes, leading controls/Unicode whitespace, commas, quotes, backslashes, multiline and nested JSON fields, plus empty output. This serializer check was not a database test.

Outbound mail and external HTTP were blocked on the disposable sites. The initial hosted queue cleared after publication. [GitHub Actions run 43465195677](https://github.com/memberfixstudio-ind/access-ledger/actions/runs/43465195677) passed on the release code in all three configured MySQL 8 environments: PHP 8.2/WP 6.8.3/Woo 10.0.4 legacy, PHP 8.3/WP 7.1.4/Woo 11.3.0 legacy, and the same current versions with HPOS. Local browser evidence remains separate from those hosted CLI checks.

## 1.0.0 historical checks — 12 November 2026

### Executed locally

| Environment | Result |
|---|---|
| WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.2.29, MySQL 8.0.35, legacy order storage | 197 assertions passed |
| Same versions, separate database with HPOS enabled before creating fixtures | 197 assertions passed |
| WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.3.36, MySQL 8.0.35, HPOS | 199 assertions passed (includes two additional database-read failure checks) |
| WordPress 6.8.3, WooCommerce 10.0.4, PHP 8.2.29, MySQL 8.0.35, legacy order storage | 199 assertions passed |
| Installable ZIP in a separate HPOS site | Activation and dashboard smoke check passed |
| Two independent PHP processes sharing MySQL, legacy and HPOS | Correction held its transaction while another process attempted download consumption. Email, remaining allowance and count were all preserved. |
| Chrome, actual WordPress admin and REST API | Preview, exact-email confirmation, correction, guarded undo, nonce denial, scan, JSON export, search and mobile layout exercised |
| Native WooCommerce download handler | Actual fixture file: mismatched email returned HTTP 403; correction returned HTTP 200 with exact file contents; undo returned HTTP 403 |
| PHP and JavaScript syntax | All runtime/test PHP files and admin.js checked |

The core suite includes 162 adversarial checks using real WooCommerce records and SQL fault injection: tampered/expired/operator-bound previews, account/order/permission changes, refunds, revocation, duplicate grants, exhausted/expired allowances, expiry-day boundaries, order-key mismatch, forced audit insert/commit/undo failures, rollback and unauthorized API mutations.

The preview-to-application consumption test and the **separate two-process concurrency test** are different checks. Neither is a load benchmark.

## Reproduce

Use a disposable WordPress installation, install WooCommerce, activate this plugin, and define `ACCESS_LEDGER_TESTS` as true in its wp-config.php. These scripts create users, products, orders, refunds and permissions. Never run them on a live store.

```sh
wp eval-file /path/to/access-ledger/tests/integration.php
# From the WordPress root with wp on PATH:
bash /path/to/access-ledger/tests/concurrency.sh
```

To exercise the browser and a real download, run `wp eval-file /path/to/access-ledger/tests/e2e-fixture.php > /tmp/al-fixture.json`, start the local site, then run tests/browser.cjs with AL_BASE, AL_FIXTURE, AL_USER, AL_PASSWORD and AL_ARTIFACT_DIR. Install Playwright only in your test environment; AL_PLAYWRIGHT and AL_CHROME can select an existing installation/browser. This fixture changes the disposable site's download method and login requirement. Do not use it on production.

## Scope and remaining validation

- The GitHub Actions matrix is configured for baseline and current-version combinations on every push. The initial 1.0.0 runs remained queued during that earlier release session. **The 1.1.0 release-code run passed all three jobs**, as linked above. Check [Actions](https://github.com/memberfixstudio-ind/access-ledger/actions/workflows/test.yml) for its current status.
- The release requires PHP 8.2+. PHP 7.4/8.1 compatibility is not advertised because those runtimes were not exercised.
- This is an integration-tested first release, not evidence of long-running deployment across hosting providers or every WooCommerce extension.
- No paid MemberPress, LearnDash, subscriptions or CRM integration is advertised or tested.
- No large-store throughput benchmark, external penetration test, or screen-reader study has been performed. Keyboard controls, native dialogs, text alternatives and a 390px layout are included; browser tests check mobile page overflow and runtime errors.
- A scan is an observation, not a transactionally frozen store-wide snapshot. Concurrent deletions or backdated imports can shift pagination. Rerun after imports settle.
- Transactional InnoDB is required for corrections. SQLite/Playground is not a validated repair environment.
