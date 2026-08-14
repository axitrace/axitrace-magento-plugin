# AxiTrace — Server-Side Tracking for Magento 2 / Adobe Commerce

## Short description (≤120 chars)

Free server-side tracking module. Forwards orders to Facebook CAPI, TikTok, Google Ads, GA4 — Luma + Hyva ready.

## Long description (≤25,989 chars)

AxiTrace is a free Magento 2 / Adobe Commerce module that captures
order events server-side and forwards them to Facebook Conversions API,
TikTok Events API, Google Ads offline conversions, and GA4 Measurement
Protocol — without browser dependence, ad blockers, or third-party cookies.

The module itself is fully free under the MIT licence. AxiTrace operates the
SaaS that processes the forwarded events and is billed on
[axitrace.com/pricing](https://axitrace.com/pricing) (Stripe). This pattern
mirrors Klaviyo, SendGrid, Yotpo, and Dotdigital — all approved Marketplace
extensions that pair a free Magento module with a paid SaaS account.

### Why server-side tracking matters in 2026

- iOS 14.5+ App Tracking Transparency, Safari ITP and Firefox ETP block or
  shorten the lifetime of third-party cookies, breaking conversion attribution
  for ~40% of your North American audience.
- Ad-blocker adoption sits around 30% in Western Europe and parts of LATAM.
- Facebook's own Meta Pixel browser fire rate has been declining year over year.

Forwarding events server-side from your Magento store closes those gaps. AxiTrace
generates a deterministic UUID v5 `event_id` and shares it with your client-side
pixel so platforms dedupe the two sources and you don't double-count.

### Features

- **Asynchronous capture** via Magento MessageQueue (MySQL transport by default;
  works on any Magento install without RabbitMQ infrastructure). Optional AMQP
  via env override.
- **Idempotent observer** keyed on UUID v5 of the order increment_id — async
  payment auto-invoice flows (Stripe, Adyen, Klarna) can fire the underlying
  save_after multiple times; AxiTrace fires exactly once.
- **Built-in retry cron** for failed events with a 15-minute cadence and a
  per-event attempt cap.
- **Polished admin UI** — Stores → Configuration → AxiTrace. Workspace key is
  encrypted at rest via Magento's `Encrypted` backend model. Test Connection
  and Auto-Detect Domain buttons give live feedback in under 3 seconds.
- **Storefront pixel** for Luma — vanilla JS, defer-loaded. Fires product
  view, add-to-cart, checkout-started, and add-payment-info events in
  addition to server-side purchase capture.
- **Hyva theme support** via the separate `axitrace/module-tracking-hyva`
  Composer package. Alpine.js CSP-build compatible, strict
  `$hyvaCsp->registerInlineScript()` placement audited in CI.

### Versions supported

| Magento | PHP |
|---------|-----|
| 2.4.6   | 8.1, 8.2 |
| 2.4.7   | 8.2, 8.3 |
| 2.4.8   | 8.3, 8.4 |

Adobe Commerce on-prem editions share the same support matrix.

### Out of scope

- Adobe Commerce as Cloud Service (ACCS) — uses Adobe App Builder
  (Node.js/serverless); a separate App Builder extension is planned.
- B2B catalog event capture — out of scope for v0.1.0.
- Order cancellation and refund events — coming in a future release.

### Integration prerequisite

You need an AxiTrace workspace at [axitrace.com/signup](https://axitrace.com/signup).
Workspace creation is free; pricing for SaaS event processing is at
[axitrace.com/pricing](https://axitrace.com/pricing).

### Privacy

The module forwards PII (email, phone) to AxiTrace in plain text over HTTPS.
Facebook's PHP SDK and TikTok's Events API hash internally per their
documented requirements. AxiTrace does not sell or share customer PII; full
privacy notice at [axitrace.com/privacy](https://axitrace.com/privacy).

### Support

Email `info@axitrace.com` or open an issue at
[github.com/axitrace/axitrace-magento-plugin](https://github.com/axitrace/axitrace-magento-plugin).

---

**Required external account**: AxiTrace SaaS account at axitrace.com. Free
sign-up; pricing for event processing visible before account creation.
