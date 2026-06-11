# Upgrading

## To 1.0.0

First stable release.

### Requirements

- PHP `>=8.1, <8.6` (Symfony 8.x requires PHP 8.4+).
- Symfony `6.0+` | `7.4+` | `8.0+` | `8.1+` (minimum tested minors: 7.4, 8.0, 8.1).
- Doctrine ORM and DoctrineBundle (`^2.8` or `^3.0` for Symfony 8).

### Install

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle
```

### Register and configure

Register `Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle` in `config/bundles.php`.

With Symfony Flex, the recipe installs `config/packages/nowo_doctrine_deadlock_retry.yaml`. Otherwise copy from `.symfony/recipe/nowo-tech/doctrine-deadlock-retry-bundle/1.0/config/packages/nowo_doctrine_deadlock_retry.yaml` (see [Installation](INSTALLATION.md) and [Configuration](CONFIGURATION.md)).
