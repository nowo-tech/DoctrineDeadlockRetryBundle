# Upgrading

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
