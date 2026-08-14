# AxiTrace for Magento 2 / Adobe Commerce

Server-side tracking module for Magento 2 / Adobe Commerce stores. Forwards
order, product-view, add-to-cart, and other commerce events to AxiTrace, which
relays them to Facebook CAPI, TikTok Events API, Google Ads offline conversions,
and GA4 — server-side, with deterministic event ids that dedupe against any
client-side pixels you may also be running.

The module itself is **free** under the MIT License. AxiTrace bills the SaaS
that processes the forwarded events on
[axitrace.com](https://axitrace.com/pricing) (Stripe). There is no module-level
licence check or API call back to AxiTrace for billing purposes.

- **Magento Open Source**: 2.4.6 / 2.4.7 / 2.4.8
- **Adobe Commerce** (on-prem): same versions
- **PHP**: 8.1 / 8.2 / 8.3 / 8.4 (per Magento version matrix)
- **Themes**: Luma (default), Hyva via the separate
  [`axitrace/module-tracking-hyva`](https://packagist.org/packages/axitrace/module-tracking-hyva) package
- **Not supported**: Adobe Commerce as Cloud Service (ACCS) — uses App Builder,
  not PHP modules.

---

## Install (Composer — recommended)

```bash
composer require axitrace/module-tracking
# Hyva users:
composer require axitrace/module-tracking-hyva

bin/magento module:enable AxiTrace_Tracking Hyva_AxiTraceTracking
bin/magento setup:upgrade
bin/magento cache:clean
```

## Install (ZIP — for hosts without Composer access)

1. Download the latest ZIP from
   [axitrace.com/downloads/axitrace-magento-plugin-latest.zip](https://axitrace.com/downloads/axitrace-magento-plugin-latest.zip).
2. Unzip into `app/code/AxiTrace/Tracking` (and `Hyva/AxiTraceTracking` if you
   need Hyva support).
3. Run the same `module:enable` / `setup:upgrade` / `cache:clean` sequence.

## Install (Adobe Commerce Marketplace)

The module is distributed primarily via Composer/Packagist and direct ZIP (above)
— no Marketplace account is required to install it. An Adobe Commerce Marketplace
listing may be published later for discovery; if/when it is, you will also be able
to install with the Marketplace authentication keys from
[commercemarketplace.adobe.com/customer/accessKeys/](https://commercemarketplace.adobe.com/customer/accessKeys/).

---

## Configure (under 5 minutes)

1. **Get your workspace public key**: sign in at
   [axitrace.com/dashboard](https://axitrace.com/dashboard). Each workspace has
   a `pk_live_...` / `pk_test_...` key. Copy it.
2. **Open the Magento admin** → **Stores → Configuration → AxiTrace**.
3. **Enable AxiTrace tracking**: set to **Yes**.
4. **Paste your workspace public key**. The field is encrypted at rest.
5. *(Optional)* **Auto-Detect Domain**: if you have a verified custom tracking
   domain on AxiTrace, click the button to populate it. Otherwise leave blank
   to use the default (`stat.axitrace.com`).
6. **Test Connection**: the button issues an AJAX request to AxiTrace and
   shows a coloured status. Green means you're connected.
7. **Save Config**.
8. **Place a test order** in your storefront. Within 1–2 minutes, the Status
   indicator turns green ("last event N minutes ago") and AxiTrace's dashboard
   shows the order on the events feed.

## What it captures

| Event | Source |
|-------|--------|
| `transaction.charge` | `sales_order_save_after` observer fires on the first state transition into `processing`. Idempotent via `axitrace_event_log` UNIQUE constraint. |
| `view_content` | Storefront pixel on PDP (Luma + Hyva) |
| `view_category` | Storefront pixel on category page |
| `product.addToCart` | Storefront pixel via Magento cart events |
| `begin_checkout` | Storefront pixel on the checkout page, with cart value/currency/item count. Off by default — enable "Checkout started events". |
| `add_payment_info` | Storefront pixel, best-effort, when the customer reaches the payment step. Off by default — enable "Add payment info events". |
| `page.view` | Off by default (high volume) |

PII (email, phone) is forwarded in **plain text** server-to-server; Facebook's
PHP SDK and TikTok's API hash internally per their requirements.

## Operations

### Logs

All module activity logs to a dedicated file:

```
var/log/axitrace.log
```

Critical events (`->critical()`) also flow through Magento's central log so
they are picked up by any monolog-based alert pipeline.

### Idempotency table

The module ships its own table `axitrace_event_log` (declared via
`etc/db_schema.xml`):

| column | purpose |
|--------|---------|
| `event_id_hash` | UUID v5 from `magento_order:{increment_id}`; UNIQUE — blocks double-fire on async payment auto-invoice flows |
| `status` | `pending`, `sent`, `failed`, `skipped` |
| `attempts` | retry counter — capped at 5 by the retry cron |
| `last_error` | truncated error from ingestion-api |

You can inspect it via any DB client; nothing in it should ever be PII.

### Cron jobs

Two cron jobs are registered (`etc/crontab.xml`):

| name | schedule | what |
|------|----------|------|
| `axitrace_consumer_runner` | every minute | spawns the `axitrace_order_consumer` for up to 100 messages |
| `axitrace_retry_failed` | every 15 minutes | re-publishes `axitrace_event_log` rows with `status=failed` and `attempts<5` |

If Magento cron is healthy (`bin/magento cron:run` runs every minute via system
cron), the module needs zero ops intervention.

### Manual retry

```bash
bin/magento axitrace:retry-failed
```

Equivalent effect to one tick of the retry cron — useful after an
ingestion-api incident.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `axitrace_event_log` empty after placing an order | Module disabled, or the order didn't transition to `processing` | Enable in config; verify the payment method auto-invoices |
| Rows stuck in `pending` | Magento cron not running | `bin/magento cron:run --group=default` once; install system cron |
| Rows stuck in `failed` with `connection timed out` | Outbound network blocked from your hosting | Allowlist `api.axitrace.com:443` |
| Hyva storefront fires no events | Hyva compat package not installed | `composer require axitrace/module-tracking-hyva` + `setup:upgrade` |
| Hyva CSP errors in browser console | Hyva FPC + block_html cache stale | `bin/magento cache:flush full_page block_html` |

## Reporting issues

[github.com/axitrace/axitrace-magento-plugin/issues](https://github.com/axitrace/axitrace-magento-plugin/issues)
or email `info@axitrace.com`.

## License

MIT — see [LICENSE.md](./LICENSE.md).
