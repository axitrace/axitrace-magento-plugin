# AxiTrace Magento E2E Harness

Docker Compose-based end-to-end test environment for the
`axitrace/module-tracking` + `axitrace/module-tracking-hyva` modules.

## What it boots

| Service | Image | Purpose |
|---------|-------|---------|
| `magento` | `markoshust/magento-nginx:1.24-0` | Magento 2.4.7 web server |
| `php` | `markoshust/magento-php:8.2-fpm-3` | Magento PHP-FPM |
| `db` | `mariadb:10.6` | Magento database |
| `opensearch` | `markoshust/magento-opensearch:2.12-0` | Magento search engine |
| `redis` | `redis:7.2-alpine` | Magento cache/session |
| `ingestion-mock` | local Go image (built from `mock-ingestion/`) | Captures POSTs from the module so tests can assert payload shape |

## Quickstart

```bash
make e2e
```

Brings the stack up, installs the module, places a synthetic order via REST,
and asserts the mock ingestion endpoint received exactly one event with a
matching deterministic event_id.

## Files

```
tests/e2e/
├── docker-compose.yml      stack definition
├── Makefile                up / install / order / e2e / down / logs-axi
├── install.sh              composer install + module:enable + setup:upgrade + cache:clean
├── place-order.sh          drives /rest/V1/carts/* + asserts capture
├── fixtures/
│   └── place-order.json    REST checkout body templates
└── mock-ingestion/         Go HTTP server, captures POSTs in memory
    ├── Dockerfile
    └── main.go
```

## Matrix testing

The companion `.github/workflows/ci.yml` runs the harness on PHP 8.1 / 8.2 / 8.3 / 8.4
against Magento 2.4.6 / 2.4.7 / 2.4.8 per the support matrix in §11 of the
implementation plan (`claude-work/magento2-plugin-plan.md`).
