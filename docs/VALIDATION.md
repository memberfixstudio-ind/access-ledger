# Validation record

Release candidate 1.0.0, checked 12 November 2026. All fixtures use disposable stores and reserved example.test email addresses. No client data was used.

## Executed locally

| Environment | Result |
|---|---|
| WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.2.29, MySQL 8.0.35, legacy order storage | 197 assertions passed |
| Same versions, separate database with HPOS enabled before creating fixtures | 197 assertions passed |
| WordPress 7.1.4, WooCommerce 11.3.0, PHP 8.3.36, MySQL 8.0.35, HPOS | 199 assertions passed (includes two additional database-read failure checks) |
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

- GitHub Actions runs minimum-version and current-version combinations on every push; its badge reflects the actual latest result.
- This is an integration-tested first release, not evidence of long-running deployment across hosting providers or every WooCommerce extension.
- No paid MemberPress, LearnDash, subscriptions or CRM integration is advertised or tested.
- No large-store throughput benchmark, external penetration test, or screen-reader study has been performed. Keyboard controls, native dialogs, text alternatives and a 390px layout are included; browser tests check mobile page overflow and runtime errors.
- A scan is an observation, not a transactionally frozen store-wide snapshot. Concurrent deletions or backdated imports can shift pagination. Rerun after imports settle.
- Transactional InnoDB is required for corrections. SQLite/Playground is not a validated repair environment.
