# Access Ledger for WooCommerce: research and decision

Research date: 12 November 2026. This is a product decision record, not a test report or a production-readiness certificate. The shipped behavior is described in [CONTRACT.md](CONTRACT.md); its email-only correction is narrower than the earlier identity-repair proposals.

## Decision and actual reviewer votes

Three research agents independently reviewed the alternatives and all three voted for **B**, narrowed to WooCommerce download-access diagnostics and guarded correction of existing permissions. The table records their completed research responses; it is not a simulated panel or an invented consensus.

| Reviewer | Actual vote | Qualification in the completed response |
| --- | --- | --- |
| Membership operations specialist | B: proceed with the narrowed v1 | Useful operational differentiation, but not a unique category or proven willingness to pay. WP Wham already preserves counts and logs; missing grants remain unresolved. |
| Skeptical product/security reviewer | B: conditional build | A useful first release with bounded repairs; flagship potential remains unproven. Ownership ambiguity must prevent changes. |
| Market researcher | B: proceed | Credible flagship foundation through cross-order audits, explanations, triage and guarded repair. Adoption comparable to WP Fusion is unproven. |

**Recorded result: B 3/3; A 0/3; C 0/3.** These votes concern the product direction, not approval of subsequently written code. They were checked against the agents' completed research responses. The email-only final contract is narrower than the earlier identity-repair proposals.

The security review used the following weighting, with 5 best on every criterion (including lower security risk). Its original B differentiation score was 3.5 and total 71.5 before the WP Wham evidence was incorporated. The revised judgment reduces differentiation to 3 and the total to 69; the vote remains B. This is one reviewer's judgment, not an average of three incompatible scoring methods.

| Criterion | Weight | A: webhook outbox | B: narrowed Access Ledger | C: general user portability |
| --- | ---: | ---: | ---: | ---: |
| Breadth and severity of need | 25% | 4 | 3 | 4 |
| Differentiation after competitor review | 25% | 1 | 3 | 1 |
| Security and controllable harm | 25% | 2 | 4 | 2 |
| Achievable, verifiable scope | 20% | 4 | 4 | 3 |
| Recurring operational value | 5% | 3 | 3 | 3 |
| Weighted total / 100 | 100% | 54 | 69 | 50 |

Scores are research judgments, not measured demand, market share, or test results. B's smaller addressable audience is an explicit tradeoff for a publicly obtainable integration and bounded behavior.

## Competitors and primary sources

Vendor documentation and plugin-directory descriptions establish advertised capabilities. They do not establish independently tested quality, reliability, or feature absence. Research was bounded, not an exhaustive market census.

| Product/source | Documented overlap | Consequence for this decision |
| --- | --- | --- |
| [Webhook Actions by Flow Systems](https://wordpress.org/plugins/flowsystems-webhook-actions/) and its [retry-policy article](https://wpwebhooks.org/blog/webhook-retry-policy-exponential-backoff/) | Persistent delivery queue, backoff, per-attempt history, dead-letter visibility, replay and bulk retry. | A generic webhook outbox is already covered. Reliability primitives are not a new category. |
| [WP Webhooks Automator](https://wp-webhooks.com/features/) | Incoming/outgoing webhook logs and automation features. This is a separate product from Flow Systems at wpwebhooks.org. | Do not conflate the brands or claim competitors lack failure visibility. A complete automatic-retry policy was not independently established for this product. |
| [WooCommerce download guide](https://woocommerce.com/document/digital-downloadable-product-handling/) | Built-in permission inspection, manual changes and regeneration. Regeneration deletes existing permissions and logs and loses old counts. New files ordinarily do not extend access to previous purchases. | There is a concrete support workflow, but missing permission is not proof a customer is owed a new grant. |
| [WP Wham product](https://wpwham.com/products/bulk-regenerate-download-permissions-for-woocommerce-orders/) and [documentation](https://wpwham.com/documentation/bulk-regenerate-download-permissions-for-woocommerce-orders/) | Bulk regeneration for all or selected orders; an option preserves existing download counts **and log data**. The documentation also describes optional expiry resets and order-key generation. | Preserving counts or logs is **not novel**. Do not market Access Ledger as the first or only preservation tool. |
| [MCRPD Regenerate Download Permissions](https://wordpress.org/plugins/mcrpd-regenerate-download-permissions-for-woocommerce/) | Filtered batch regeneration by product, category, status and date, with subscription-related options. | Bulk processing and targeted selection are already served. A different diagnostic workflow must earn its value. |
| [Course Access Debugger for LearnDash](https://wordpress.org/plugins/course-access-debugger-for-learndash/) | Access explanations, an enrollment audit, WooCommerce purchase checks and multiple access conditions. | Access explanation itself is not new across WordPress. No LearnDash compatibility is claimed by this v1. |
| [AccessTrace for LearnDash](https://wordpress.org/plugins/accesstrace-for-learndash/) | Passive enrollment history, cause attribution with unknown states, event classification and export. | An access ledger or uncertain-event classification is not novel across the wider market. |
| [WooCommerce Memberships](https://woocommerce.com/document/woocommerce-memberships-user-memberships/) and [WP Fusion batch operations](https://wpfusion.com/documentation/getting-started/batch-operations/) | Existing-purchase access grants and resynchronization operations respectively. | General membership reconciliation must be evaluated against native and established tools before expansion. |
| [WebToffee user/customer import/export](https://wordpress.org/plugins/users-customers-import-export-for-wp-woocommerce/), [WP All Import user migration](https://www.wpallimport.com/documentation/how-to-migrate-wordpress-users/), and [WordPress personal-data exporters](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/) | Mature migration/export capabilities and an extensible core privacy-export mechanism. | C needs a specific underserved migration pair. General portability carries relationship, identity and privilege risks beyond copying user metadata. |

## Practical differentiation

Proposed product promise: **Explain WooCommerce download-access discrepancies across orders, show the evidence for each finding, and correct a narrowly verified permission-email mismatch without changing the grant or its allowance.**

The proposed value is the combined operator workflow: resumable cross-order inspection, single-order explanation, uncertainty-aware triage, bounded export, an exact repair preview, fresh eligibility checks, an audit trail, and guarded reversal. Individual pieces already exist elsewhere. The reviewed sources did not establish this exact complete combination in a WooCommerce competitor; that is a research observation, not proof of uniqueness or a durable moat.

The current engine installs no grant-snapshot hooks. Saved scan reports are timestamped observations of the state seen during that scan, not purchase-time snapshots or proof that a file was granted when the order was placed. The repair audit records only Access Ledger's own corrections and reversals. Earlier research proposals for a continuous grant ledger are not implemented v1 capabilities.

The initial audience is agencies and operators supporting WooCommerce digital-download stores. A helpful support tool does not automatically have WP Fusion-scale demand. No customer interviews, willingness-to-pay research, conversion study, or adoption measurements were performed.

## Final v1 boundary

The final override in CONTRACT.md supersedes prior suggestions to restore missing grants, change user IDs or order keys, or retain encrypted order keys in audit records.

- Inspect current orders, products/files, download permissions, identity, limits, expiry and order eligibility. Store timestamps with findings and distinguish expected restrictions from unresolved discrepancies.
- Report missing permissions for human review. Absence cannot distinguish deliberate revocation, accidental deletion, or a file added after purchase. Historical snapshots do not authorize restoration.
- Permit an email-only correction on an **existing** permission only when its positive registered user ID matches the order customer, that account's email matches the order billing email, and its order key already matches the order. Verify a paid, download-eligible order, no refunds, current purchased file, no duplicate grant, and an unexpired/unexhausted allowance.
- Never change user ID, order key, permission ID, order/product/file identity, count, remaining allowance, expiry or download logs. Never create a missing permission. Guest transfers and ambiguous identities remain review-only.
- Preview the exact email change and revalidate current evidence at application. Bind the preview to the operator and permission, expire it, and prevent unintended reuse. Authorization is independent of possession of a token.
- Apply only the permitted field through a conditional write. Ordinary concurrent consumption must never be overwritten; becoming exhausted must prevent correction. An audit-persistence failure must leave the original permission unchanged.
- Record before/after email, user ID and a keyed context fingerprint. Do not persist or export raw order keys or usable download URLs. The final contract requires no encryption because those secrets are excluded.
- Undo conditionally, respecting current authorization and conflicting changes. It is not a general backup restore. A lost audit or changed context can make reversal unavailable.
- Use WooCommerce APIs for orders, supporting declared HPOS and legacy modes. Reports and exports remain restricted to authorized store managers. JSON export avoids adding CSV formula-execution hazards.

The contract's earlier stateless-HMAC preview description differs from the engine's later transient-backed design. The security requirement is trustworthy actor/state binding, expiration and replay handling; the mechanism must be documented consistently after implementation review. The final email-only override is unchanged by that mechanism choice.

## Why grant restoration was rejected

WooCommerce's public source contains direct permission-deletion paths, while its admin revocation hook belongs to a particular AJAX route. A hook-based tombstone cannot prove that every intentional revocation was captured. Download tracking also updates counters using SQL rather than relying exclusively on generic CRUD saves. A partial snapshot ledger can therefore be stale or incomplete.

Primary source references: [permission datastore](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-customer-download-data-store.php), [AJAX grant/revoke handlers](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-ajax.php), [permission model and download tracking](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-customer-download.php), and [download authorization handler](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/class-wc-download-handler.php). These are moving upstream references; release validation must pin actual installed versions.

Existing permission records also do not prove file delivery works. Hosting, approved download directories, file existence, authentication, caches and third-party filters can affect the customer's experience. A read-only report must not present an unperformed download as successful delivery.

## Acceptance gates, not completed tests

The following are required verification cases. Listing them here does **not** mean they have passed. The implementation owner supplies real WordPress/WooCommerce fixtures and records actual commands, versions and outcomes; stubs, mocks or static inspection alone cannot establish plugin interoperability.

| Gate | Required evidence |
| --- | --- |
| Read-only inspection | Order, permission and download-log values are unchanged after individual inspection and scans. Plugin-owned findings may be written. No customer email or file download is triggered. |
| Permission boundaries | Missing/revoked, historical/unknown, duplicate, removed-file, guest, user-ID mismatch and order-key mismatch cases cannot be repaired. |
| Exact eligibility | Paid/status settings, refunds including partial refunds, current purchased variation/file, finite/unlimited/exhausted allowances, and native WooCommerce expiry-day boundaries yield expected classifications. |
| Email-only write | Only the eligible email changes; all identifiers, dates, counts, remaining allowance and logs are unchanged. Verify the resulting customer download behavior separately. |
| Authorization and previews | Unauthorized requests, wrong actor/permission, expired/tampered/reused previews and changed ownership/account email are rejected without mutation. |
| Concurrent lifecycle changes | Consume a download, exhaust the final allowance, revoke the permission, refund the order, change identity or remove the file between preview and execution. Updates never recreate grants or overwrite consumption. Race tests must cover the write window, not only changes before a request. |
| Atomic audit and guarded undo | Force audit insert/commit failures and interrupted operations. No unlogged mutation or false success. Reversal preserves counters, rejects conflicting state, and records successful reversal atomically. |
| Storage and query correctness | Test supported MySQL/MariaDB transactions and lock behavior with the real schema. Playground/SQLite results must be labeled separately; SQL translation and SQLite's different locking do not prove InnoDB concurrency safety. |
| Scan and export limits | Test bounded pages, persisted resume/cancel, concurrent cron/UI steps, retention, and report/export caps. State clearly that capped exports and chosen date windows are not a complete store history. |
| Privacy and display | REST and admin output contain no order keys or bearer download URLs. PII remains access-controlled, retention is enforced, and untrusted names/details are rendered as text. |

## Limitations and release claims

- Public WooCommerce source makes this integration obtainable and inspectable without a commercial license. It does not prove runtime compatibility. LearnDash, MemberPress and paid subscription integrations are not verified v1 adapters.
- PHP/WordPress/WooCommerce minimums in the contract are target compatibility claims until the corresponding versions are actually exercised. A current-version happy path is insufficient evidence for every declared minimum.
- SQLite/Playground can provide valuable real-plugin functional evidence, but it cannot alone certify MySQL row-locking, transactional rollback or production scale.
- Read-only audit findings are observations at a time, not complete historical attribution. The word ledger refers to inspection/repair evidence, not a guaranteed event history of every permission mutation.
- Report retention and capped exports limit historical coverage. Capability checks do not remove the need to minimize personal data or control downloaded report files.
- No production-readiness, security-audit completion, exhaustive competitor coverage, or absence-of-competitors claim is made by this document. Release notes must describe observed tests and remaining gaps separately.
