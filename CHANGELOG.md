# Changelog

All notable changes to `axitrace/module-tracking` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.5] - 2026-08-14

### Fixed
- **Events now track out of the box.** Previously every event toggle defaulted to *off* (`ScopeConfig::isSetFlag` with no `etc/config.xml` defaults), so a freshly installed and enabled module forwarded nothing until the merchant turned each event on by hand. Added `etc/config.xml` defaulting the full conversion funnel to *on* — Purchase, AddToCart, ViewContent, InitiateCheckout, AddPaymentInfo. `page.view` stays off by default (high volume, opt-in) and `view_category` stays off (not a canonical event). Merchants can still disable any event under Stores → Configuration → AxiTrace → Events.

## [0.1.4] - 2026-08-13

### Added
- Storefront pixel now fires `begin_checkout` on the checkout page (`checkout_index_index`) with cart value, currency, and item count, read from the server-authoritative quote via the new `ViewModel\CheckoutContext` (mirrors `ViewModel\OrderConfirmationContext`'s defensive try/catch pattern — any failure returns an empty payload rather than interrupting the checkout render).
- Storefront pixel now fires `add_payment_info` (with the same cart value/currency/item count) when the customer reaches the payment step. Primary trigger: the checkout SPA's URL hash reaching `#payment` (`Magento_Checkout/js/model/step-navigator` syncs the active step to `location.hash`), which fires regardless of how many payment methods are configured. Fallback trigger: a delegated `change` listener on `payment[method]` radio inputs, for checkout customizations that don't use step-navigator's hash sync.
- New per-event admin toggles under Stores → Configuration → AxiTrace → Events: "Checkout started events" (`begin_checkout_enabled`) and "Add payment info events" (`add_payment_info_enabled`). Both default to disabled (opt-in), matching the existing toggle pattern.

## [0.1.3] - 2026-07-13

### Added
- Purchase events now carry the merchant's own Meta browser pixel cookies (`_fbp`/`_fbc`) when present. Captured in `OrderStateTransitionObserver` (the only request-scoped point in the purchase dispatch flow — `OrderEventConsumer` runs fully asynchronously via the MysqlMq cron consumer, with no HTTP request/cookie access), embedded in the `axitrace.order.placed` queue message, and forwarded by `OrderEventNormalizer`. Improves Facebook CAPI browser/server event matching; no behavior change when cookies are absent.

## [0.1.2] - 2026-05-23

### Fixed
- Storefront pixel (`view/frontend/templates/pixel.phtml`) now explicitly calls `window.Axitrace.init(...)` after the SDK loads and dispatches the appropriate event based on the page type. Previously the SDK was loaded but never initialized, so no client-side events (page view, product view, add-to-cart) were ever sent. Mirrors the WooCommerce frontend bridge pattern.
- Page-type → SDK event mapping: `home`/`cart`/`cms` → `page.view`; `product` → `product.view` (with `params.sku` at top level as required by ingestion); `category` → `page.view` with category metadata (no dedicated `category.view` event in current ingestion spec); add-to-cart click on Luma `button.tocart` → `product.addtocart`.

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
