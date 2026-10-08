# Changelog

All notable changes to `axitrace/module-tracking` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.0] - 2026-10-08

### Fixed
- **Purchases are now linked to the shopper's visitor profile.** Until now a purchase reached AxiTrace without the AxiTrace visitor and session ids (`vt_vid` / `vt_sid`), with an empty User-Agent and with no click id other than `_fbc`, so it could not be stitched to the visitor's profile and ad clicks, and AxiTrace recorded the User-Agent of the request sent by the Magento server instead of the shopper's. Purchases now carry `userId` (= `vt_vid`), `sessionId` (= `vt_sid`), the shopper's IP address and User-Agent, the browser ids `fbp`, `fbc`, `ttp`, `rdt_uuid`, `obref`, `_ga` and the click ids `gclid`, `gbraid`, `wbraid`, `ttclid`, `rdt_cid`, `oppref` under `data`. This needs no configuration.
- An order moving to `processing` in the admin (for example an invoice for a bank transfer order) no longer attaches the merchant's own `_fbp` / `_fbc` cookies to the buyer's purchase.

### Added
- A new observer on `sales_order_place_after` captures that identity in the request that places the order and stores it on the order in the new nullable column `sales_order.axitrace_browser_identity` (JSON). Run `bin/magento setup:upgrade` after updating. The purchase, the retry cron and the queue consumer read it from there, so a purchase confirmed later by an admin invoice or a payment webhook still carries the shopper's identity.
- Only the shopper's own browser request is read: the `frontend`, `webapi_rest` (the Luma checkout) and `graphql` areas, and only when the request carries cookies. Orders created in the admin, by a payment webhook or through the API by another system store nothing.
- Click ids follow the rules of the AxiTrace JavaScript SDK that wrote them and of the AxiTrace PHP SDK 1.10.0: a click id in the current URL wins; otherwise the cookie value `v2|<firstSeenMs>|<clickId>` is reduced to the bare click id, and a click older than 90 days (`gclid`, `gbraid`, `wbraid`, `ttclid`) or 28 days (`rdt_cid`, `oppref`) is not sent. Unversioned or malformed values are ignored. Browser ids are sent only when they match the format their pixel writes.

### Changed
- The `axitrace.order.placed` queue message carries the identity as a `browser` object instead of the top-level `fbp` / `fbc` keys. Messages queued by an earlier version are still read, including their `fbp` / `fbc`.

## [0.3.0] - 2026-10-02

### Added
- Optional **AxiTrace secret key** setting (Stores > Configuration > AxiTrace > General), encrypted at rest and configurable per store view. When set, requests to AxiTrace carry `Authorization: Basic base64(<secret key>:)`, which AxiTrace requires before it accepts product costs or refunds. Find it in AxiTrace under Settings, in the Container Information card, as Secret Key. When empty, no Authorization header, no costs and no refunds are sent; purchases still change as listed below (`tax`, `shipping`, `taxesIncluded`, per-line `externalId`, and the store view's API base URL).
- Purchase events now carry `data.tax` (order tax amount), `data.shipping` (shipping charged including its tax) and `data.taxesIncluded = true` (the revenue sent is Magento's grand total, which always contains the tax), all in the order currency like `revenue`.
- Every product line carries `externalId` = `magento:<product id>`; for a configurable product this is the simple product that was actually bought.
- With the secret key set, every product line that has a cost carries `unitCost` (`{amount, currency}`): in this order, the order item's `base_cost` of the product that was bought (for a configurable product the simple child line, then the parent line), then the current `cost` attribute of that product, then of the parent product. A zero or missing cost is left out. Costs are sent only when the store's base currency (the currency of Magento product costs) equals the order currency.
- Refunds: a new observer on `sales_order_creditmemo_save_after` sends each credit memo to `POST /v1/refund` (refund id = credit memo id, amount = credit memo grand total in the order currency, one line per refunded product with sku, externalId, quantity and amount). A new observer on `order_cancel_after` sends the canceled amount (`total_canceled`, gross, in the order currency) with refund id `cancel-<order id>`: a full cancellation (nothing paid) as `isCancellation = true` with no lines, which AxiTrace reverses as the whole order; a partial cancellation as `isCancellation = true` with the canceled lines (gross, pro rata to the canceled quantity), which AxiTrace reverses line by line; a partial cancellation that cancels no product line (only uninvoiced shipping) as an amount-only refund, so it is never read as a whole-order reversal. `orderId` is the order entity id, the same `orderId` the purchase is stored under. Both are sent only with the secret key set and only for orders this module reported as a purchase. They never interrupt the credit memo or the cancellation: every failure is logged to `var/log/axitrace.log`.

### Changed
- If AxiTrace answers 401 to a purchase sent with the secret key, the purchase is sent again in the same run without the key and without costs, and the rejection is logged critical. A wrong key loses profit data, never the purchase.
- Requests now use the API base URL configured for the order's store view; before 0.3.0 they always used the default-scope value.

### Security
- The secret key is never sent over a non-https API base URL. With such a URL the key is not used: purchases go without the Authorization header and without costs, no refund is sent, and a warning is logged to `var/log/axitrace.log`.

## [0.2.0] - 2026-09-16

### Added
- Purchase events now carry the shopper's cookie consent decision as `data.consent`, read from Magento's own Cookie Restriction Mode (`Stores > Configuration > General > Web > Default Cookie Settings > Cookie Restriction Mode`). When the mode is enabled, a buyer who clicked "Allow Cookies" on this website (cookie `user_allowed_save_cookie`) is reported as `granted` and a buyer who did not is reported as `denied`. AxiTrace stores every purchase either way (revenue reporting is unaffected) and forwards a `denied` purchase to no ad platform, with the ad identifiers stripped.
- The decision is captured in `OrderStateTransitionObserver` (the only request-scoped point of the purchase dispatch flow) and travels through the `axitrace.order.placed` queue message into `OrderEventNormalizer`. The rules live in the framework-free `Model\Consent\CookieRestrictionConsentResolver`, covered branch by branch in `tests/Unit/Model/Consent`.
- Orders created outside the storefront (admin invoice, payment webhook, cron, REST API) carry no consent state at all instead of a wrong `denied`: those requests have no buyer cookies, so a missing cookie proves nothing there. Stores that run Cookie Restriction Mode off also send no consent state, exactly as before, and the AxiTrace workspace consent setting decides.
- No storefront change: the AxiTrace JavaScript SDK reads the same `user_allowed_save_cookie` on Luma and Hyva, so the browser side needs no template edit and the companion `axitrace/module-tracking-hyva` package is unchanged.

## [0.1.5] - 2026-08-14

### Fixed
- **Events now track out of the box.** Previously every event toggle defaulted to *off* (`ScopeConfig::isSetFlag` with no `etc/config.xml` defaults), so a freshly installed and enabled module forwarded nothing until the merchant turned each event on by hand. Added `etc/config.xml` defaulting the full conversion funnel to *on* - Purchase, AddToCart, ViewContent, InitiateCheckout, AddPaymentInfo. `page.view` stays off by default (high volume, opt-in) and `view_category` stays off (not a canonical event). Merchants can still disable any event under Stores → Configuration → AxiTrace → Events.

## [0.1.4] - 2026-08-13

### Added
- Storefront pixel now fires `begin_checkout` on the checkout page (`checkout_index_index`) with cart value, currency, and item count, read from the server-authoritative quote via the new `ViewModel\CheckoutContext` (mirrors `ViewModel\OrderConfirmationContext`'s defensive try/catch pattern - any failure returns an empty payload rather than interrupting the checkout render).
- Storefront pixel now fires `add_payment_info` (with the same cart value/currency/item count) when the customer reaches the payment step. Primary trigger: the checkout SPA's URL hash reaching `#payment` (`Magento_Checkout/js/model/step-navigator` syncs the active step to `location.hash`), which fires regardless of how many payment methods are configured. Fallback trigger: a delegated `change` listener on `payment[method]` radio inputs, for checkout customizations that don't use step-navigator's hash sync.
- New per-event admin toggles under Stores → Configuration → AxiTrace → Events: "Checkout started events" (`begin_checkout_enabled`) and "Add payment info events" (`add_payment_info_enabled`). Both default to disabled (opt-in), matching the existing toggle pattern.

## [0.1.3] - 2026-07-13

### Added
- Purchase events now carry the merchant's own Meta browser pixel cookies (`_fbp`/`_fbc`) when present. Captured in `OrderStateTransitionObserver` (the only request-scoped point in the purchase dispatch flow - `OrderEventConsumer` runs fully asynchronously via the MysqlMq cron consumer, with no HTTP request/cookie access), embedded in the `axitrace.order.placed` queue message, and forwarded by `OrderEventNormalizer`. Improves Facebook CAPI browser/server event matching; no behavior change when cookies are absent.

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
- Adobe Commerce (on-prem editions) - same versions.
- PHP 8.1 / 8.2 / 8.3 / 8.4 (per Magento version matrix).
- Luma theme out of the box. Hyva theme via the separate `axitrace/module-tracking-hyva` package.
