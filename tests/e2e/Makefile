# Convenience targets for running the AxiTrace Magento E2E harness locally.
#
# Usage:
#   make up        — boot the stack (Magento + MariaDB + OpenSearch + Redis + mock-ingestion)
#   make install   — enable the module, run setup:upgrade, configure to use mock-ingestion
#   make order     — drive a synthetic order through REST and assert capture
#   make e2e       — up + install + order in one go (recommended for CI)
#   make down      — stop the stack
#   make logs-axi  — tail the module's dedicated logger

DC := docker compose -f docker-compose.yml

.PHONY: up install order e2e down logs-axi

up:
	$(DC) up -d
	@echo "Waiting for Magento to settle (60s)..."
	@sleep 60

install:
	./install.sh

order:
	./place-order.sh

e2e: up install order

down:
	$(DC) down -v

logs-axi:
	$(DC) exec php tail -n 200 -f /var/www/html/var/log/axitrace.log
