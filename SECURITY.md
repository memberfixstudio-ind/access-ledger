# Security

Please report suspected vulnerabilities privately using this repository's GitHub Security Advisories when enabled, or contact the maintainer through the LinkedIn link in README.md. Do not include real customer records in public issues.

## Trust boundaries

WordPress authenticates requests; every API route requires manage_woocommerce. Repair and undo independently check the capability. Preview tokens are random, operator-bound and expire after ten minutes. Server code re-reads order and permission data. Customer values are rendered with textContent or WordPress escaping. Exports never include order keys or bearer download links.

Corrections affect existing permission email only. They cannot create access, transfer accounts, clear expiry or reset a quota. An operator still needs to verify the customer's request and ownership; database consistency is not identity verification of the person contacting support.

Store backups, database editors and third-party plugins can bypass WordPress hooks. Access Ledger cannot reconstruct historical intent or stop privileged external code from altering data. Keep backups and test extensions on staging.

## Data

Reports and correction history contain customer emails and are stored locally. Exported JSON must be handled as personal data. Retention is described in README.md; uninstall can remove all plugin-owned records without touching WooCommerce data.
