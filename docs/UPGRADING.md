# Upgrading

## To 1.0.1

Patch release; no configuration or API changes.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

If you are on **PHP 8.1** and saw a parse error loading `RetryProfile`, upgrade to 1.0.1 or newer.

## To 1.0.0

First stable release. Install with:

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle
```

Register `Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle` in `config/bundles.php` and add `config/packages/nowo_doctrine_deadlock_retry.yaml` (see [Installation](INSTALLATION.md)).
