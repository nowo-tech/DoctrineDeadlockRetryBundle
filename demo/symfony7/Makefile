# Makefile for Symfony 7.0 Demo (Doctrine Deadlock Retry Bundle)

# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
# Always invoke via $(call dc,...) so Make routes through the shell — direct execve of the WSL
# Docker Desktop CLI symlink fails with "Permission denied" (EACCES).
COMPOSE_BIN := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
dc = $(SHELL) -c '$(COMPOSE_BIN) $(1)'
# For optional monorepo update-deps include (REQ-MAKE-008).
COMPOSE := $(COMPOSE_BIN)
SERVICE_PHP := php

.PHONY: help up down build install shell logs test test-coverage cache-clear update-bundle ensure-up verify schema-update restart

help:
	@echo "Doctrine Deadlock Retry Bundle - Symfony 7.0 Demo"
	@echo ""
	@echo "  make up             Start containers"
	@echo "  make down           Stop containers"
	@echo "  make build          Build image (no cache)"
	@echo "  make install        Install dependencies"
	@echo "  make shell          Open shell in container"
	@echo "  make logs           Show logs"
	@echo "  make test           Run tests"
	@echo "  make test-coverage  Run tests with coverage"
	@echo "  make cache-clear    Clear Symfony cache"
	@echo "  make update-bundle  Update bundle from path and clear cache"
	@echo "  make verify         Start and check HTTP 200"
	@echo ""

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@$(call dc,up -d)
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(call dc,exec -T -e COMPOSER_MEMORY_LIMIT=-1 -e COMPOSER_MAX_PARALLEL_HTTP=4 php composer install --no-interaction --prefer-dist)
	@$(call dc,exec -T php php bin/console doctrine:schema:update --force --no-interaction) 2>/dev/null || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(call dc,down)

restart: ensure-up
	@$(call dc,restart)

build:
	@$(call dc,build --no-cache)

ensure-up:
	@if ! $(call dc,exec -T php true) >/dev/null 2>&1; then \
		$(call dc,up -d); \
		sleep 3; \
	fi

install: ensure-up
	@$(call dc,exec -T -e COMPOSER_MEMORY_LIMIT=-1 -e COMPOSER_MAX_PARALLEL_HTTP=4 php composer install --no-interaction --prefer-dist)

schema-update: ensure-up
	@$(call dc,exec -T php php bin/console doctrine:schema:update --force --no-interaction)

shell: ensure-up
	@$(call dc,exec php sh)

logs:
	@$(call dc,logs -f)

test: ensure-up schema-update
	@$(call dc,exec -T php php bin/console doctrine:schema:update --force --no-interaction --env=test)
	@$(call dc,exec -e APP_ENV=test php composer test)

test-coverage: ensure-up schema-update
	@$(call dc,exec -T php php bin/console doctrine:schema:update --force --no-interaction --env=test)
	@$(call dc,exec php composer test-coverage)

cache-clear: ensure-up
	@$(call dc,exec php php bin/console cache:clear)

update-bundle: ensure-up
	@$(call dc,exec php composer update nowo-tech/doctrine-deadlock-retry-bundle --no-interaction)
	@$(call dc,exec php php bin/console cache:clear)
	@echo "Bundle updated."

verify:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@port=$$(grep '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$port" ] && port=$$(grep '^PORT=' .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$port" ] && port=8017; \
	$(call dc,up -d) 2>/dev/null; sleep 10; \
	$(call dc,exec -T php composer install --no-interaction --prefer-dist) 2>/dev/null || true; \
	$(call dc,exec -T php php bin/console doctrine:schema:update --force --no-interaction) 2>/dev/null || true; \
	code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$$port 2>/dev/null || echo "000"); \
	if [ "$$code" = "200" ]; then echo "OK (HTTP $$code)"; else echo "Failed (HTTP $$code)"; exit 1; fi

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
