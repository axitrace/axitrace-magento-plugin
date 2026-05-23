# Local development setup

This document covers running the AxiTrace Magento module on a developer
machine — booting Magento, installing the module, iterating on PHP changes,
and verifying events arrive at the mock ingestion endpoint.

## Tooling choice: Markshust/docker-magento

Per Phase 2 forum research the most maintained Docker-Magento harness is
[markshust/docker-magento](https://github.com/markshust/docker-magento). It
ships images for PHP 8.1 / 8.2 / 8.3 / 8.4 and Magento 2.4.6 / 2.4.7 / 2.4.8.
Warden is the alternative; either works. The E2E harness under `tests/e2e/`
uses Markshust directly so dev and CI share images.

## Boot sequence

```bash
cd magento-plugin/tests/e2e
make e2e   # boots + installs + verifies in one command
```

`make e2e` takes ~6–10 minutes on a cold pull, ~90 seconds on a warm one.

## Iterating on PHP changes

The compose file bind-mounts `magento-plugin/` into the Magento container at
`app/code/AxiTrace/Tracking`. Most changes propagate live:

| Type of change | Action required |
|----------------|-----------------|
| PHP class body / new method on existing class | None (opcache cleared on `cache:clean`) |
| New PHP class | `bin/magento cache:clean` |
| New `etc/*.xml` (di.xml, events.xml, queue_*, crontab, etc.) | `bin/magento setup:upgrade` + `cache:clean` |
| New `etc/db_schema.xml` table or column | `bin/magento setup:upgrade` |
| New `view/frontend/layout/*.xml` | `bin/magento cache:clean layout` |
| New `*.phtml` | `bin/magento cache:clean block_html` |
| New JS in `view/frontend/web/` | (Luma) `bin/magento cache:clean` (Hyva) `bin/magento cache:clean view_preprocessed` |

For a one-liner that handles all of the above:

```bash
docker compose exec -T php bin/magento setup:upgrade && \
docker compose exec -T php bin/magento cache:clean
```

## Watching module logs

```bash
make logs-axi
# (which runs: docker compose exec php tail -f var/log/axitrace.log)
```

## Switching PHP / Magento versions

Override the `MAGENTO_PHP_VERSION` and `MAGENTO_VERSION` env vars before
`make up`:

```bash
MAGENTO_PHP_VERSION=8.3 MAGENTO_VERSION=2.4.7 make up
```

The compose file uses parameter substitution to pick the right Markshust image.
