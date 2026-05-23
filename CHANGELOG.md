# Changelog

All notable changes to `axitrace/module-tracking` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-05-23

### Fixed
- Default `api_base_url` corrected from `https://api.axitrace.com` (NXDOMAIN) to `https://stat.axitrace.com` so out-of-the-box installations can reach the AxiTrace ingestion API. Existing installations with an explicit override are unaffected.

## [0.1.0] - 2026-05-22

### Added
- Initial release of the AxiTrace Magento 2 / Adobe Commerce server-side tracking module.
- Order state transition observer (`sales_order_save_after`) captures purchase events exactly once on transition into `processing`.
- Async MessageQueue publish + consumer pattern (`axitrace.order.placed` topic, MySQL transport default).
- Idempotency table `axitrace_event_log` compensates for MysqlMq silent-drop bug (magento/magento2#18140) and prevents observer double-fires on async payments.
- Cron-based consumer runner (1-minute cadence) and retry cron (15-minute cadence) for failed events.
- Storefront pixel (Luma) injecting `velitrack-sdk.js` on `before.body.end`, with order-confirmation purchase event push and deterministic UUID v5 event_id matching the server-side id.
- Admin configuration form under Stores → Configuration → AxiTrace (workspace public key, tracking domain, event toggles, Test Connection, Auto-Detect Domain, Status Indicator).
- CLI command `axitrace:retry-failed` for manual retry of failed events.

### Compatibility
- Magento Open Source 2.4.6 / 2.4.7 / 2.4.8.
- Adobe Commerce (on-prem editions) — same versions.
- PHP 8.1 / 8.2 / 8.3 / 8.4 (per Magento version matrix).
- Luma theme out of the box. Hyva theme via the separate `axitrace/module-tracking-hyva` package.
