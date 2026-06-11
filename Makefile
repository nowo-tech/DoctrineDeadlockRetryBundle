# Makefile for Doctrine Deadlock Retry Bundle
# All dev targets use the root docker-compose.yml.

COMPOSE_FILE := docker-compose.yml
COMPOSE     := docker-compose -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down build shell install assets test test-coverage coverage-php-percent cs-check cs-fix qa clean release-check release-check-demos composer-sync rector rector-dry phpstan update validate

help:
	@echo "Doctrine Deadlock Retry Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up              Start Docker container"
	@echo "  down            Stop Docker container"
	@echo "  build           Rebuild Docker image (no cache)"
	@echo "  shell           Open shell in container"
	@echo "  install         Install Composer dependencies"
	@echo "  assets          No-op (no frontend in this bundle)"
	@echo "  test            Run PHPUnit tests"
	@echo "  test-coverage   Run tests with code coverage"
	@echo "  cs-check        Check code style"
	@echo "  cs-fix          Fix code style"
	@echo "  rector          Apply Rector refactoring"
	@echo "  rector-dry      Run Rector in dry-run mode"
	@echo "  phpstan         Run PHPStan static analysis"
	@echo "  qa              Run all QA checks (cs-check + test)"
	@echo "  release-check   Pre-release pipeline (includes demo release-verify)"
	@echo ""
	@echo "Demos (Symfony 7/8): make -C demo help"
	@echo "  composer-sync   Validate composer.json and align composer.lock"
	@echo "  clean           Remove vendor and cache"
	@echo "  update          Update composer.lock"
	@echo "  validate        Run composer validate --strict"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec -T $(SERVICE_PHP) composer config --unset platform.php 2>/dev/null || true
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer config --unset platform.php 2>/dev/null || true
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	@echo "Dependencies installed."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer config --unset platform.php 2>/dev/null || true
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

release-check: ensure-up composer-sync cs-fix cs-check rector-dry phpstan test-coverage release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-verify

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer config --unset platform.php 2>/dev/null || true
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml coverage-php.txt .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle."

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
include $(BUNDLE_ROOT)/.scripts/Makefile.update-deps.mk
