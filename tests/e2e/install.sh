#!/usr/bin/env bash
# Install the AxiTrace module into the running Magento docker-compose stack.
#
# Idempotent: re-runs are safe. Useful for CI and local re-installs after
# code changes (composer copies the local working tree into the Magento
# autoload paths via the bind mount declared in docker-compose.yml).
set -euo pipefail

DC="docker compose -f $(dirname "$0")/docker-compose.yml"

echo "[install.sh] Waiting for Magento container..."
$DC exec -T php bash -c 'until bin/magento --version >/dev/null 2>&1; do sleep 2; done'

echo "[install.sh] Enabling AxiTrace_Tracking + Hyva_AxiTraceTracking modules..."
$DC exec -T php bin/magento module:enable AxiTrace_Tracking Hyva_AxiTraceTracking || true

echo "[install.sh] Running setup:upgrade..."
$DC exec -T php bin/magento setup:upgrade

echo "[install.sh] Configuring module to talk to mock ingestion..."
$DC exec -T php bin/magento config:set axitrace/general/enabled 1
$DC exec -T php bin/magento config:set --lock-env axitrace/advanced/api_base_url http://ingestion-mock:9999
$DC exec -T php bin/magento config:set axitrace/general/workspace_public_key 'pk_test_axitrace_e2e_dummy_key_00000001'

echo "[install.sh] Clearing cache..."
$DC exec -T php bin/magento cache:clean

echo "[install.sh] Done."
