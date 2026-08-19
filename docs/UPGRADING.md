# Upgrading

## Table of contents

- [To 2.0.8](#to-208)
- [To 2.0.7](#to-207)
- [To 2.0.6](#to-206)
- [To 2.0.5](#to-205)
- [To 2.0.4](#to-204)
- [To 2.0.3](#to-203)
- [To 2.0.2](#to-202)
- [To 2.0.1](#to-201)
- [To 2.0.0](#to-200)
- [To 1.0.1](#to-101)
- [To 1.0.0](#to-100)

## To 2.0.8

No application upgrade steps.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.0.7

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). Shipped demos are Symfony 8 only (Symfony 6/7 demo apps removed).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
php bin/console cache:clear
```

## To 2.0.6

Patch release; no configuration or public API changes for application code.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Maintainers / CI:

- Makefiles detect `docker compose` vs `docker-compose` automatically and invoke Compose via a shell helper (WSL-safe).
- Optional monorepo `../.scripts/Makefile*.mk` includes no longer break a standalone clone (e.g. GitHub Actions).

## To 2.0.5

Patch release; no configuration or public API changes for application code.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Maintainers:

- `make demo-smoke` boots the Symfony 8 FrankenPHP demo and asserts HTTP 200 (also `.github/workflows/demo-smoke.yml`).
- PHPUnit / CI fail on **direct** Symfony deprecations (`SYMFONY_DEPRECATIONS_HELPER=max[direct]=0`).
- CI runs a dedicated PHPStan job.

## To 2.0.4

Patch release; no configuration or public API changes for application code.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Maintainers / demo runners:

- Prefer `.env.example` over any local `.env.dev` (do not commit `.env.dev`).
- Demos use **SQLite** only; do not rely on Flex-generated Postgres `DATABASE_URL` / `compose.override.yaml`.
- `make release-check` now fails if unresolved open GitHub PRs remain (`make check-open-prs`).
- Package QA uses `nowo-tech/phpstan-frankenphp` in **require-dev** only (not a consumer dependency).

## To 2.0.3

Patch release; no configuration or public API changes. Demo-only: FrankenPHP mode is now selected with `FRANKENPHP_MODE` in each demo `.env` (default `worker`).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

If you run the bundled demos, copy the new `FRANKENPHP_MODE` keys from `.env.example` into your local `.env` and recreate containers after changing the mode (`docker compose up -d`).

## To 2.0.2

Patch release; no configuration or public API changes.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Contributors: run `make setup-hooks` once per clone (REQ-GIT-001). See [Contributing](CONTRIBUTING.md) and [GITHUB_CI.md](GITHUB_CI.md).

## To 2.0.1

Patch release; no configuration or public API changes.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.0.0

Requires **PHP 8.2 or newer**. No configuration or public API changes beyond the platform requirement.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

If you must stay on **PHP 8.1**, pin **1.0.1**:

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle:^1.0.1
```

## To 1.0.1

Patch release; no configuration or API changes (supports PHP 8.1).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 1.0.0

First stable release. Install with:

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle
```

Register `Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle` in `config/bundles.php` and add `config/packages/nowo_doctrine_deadlock_retry.yaml` (see [Installation](INSTALLATION.md)).
