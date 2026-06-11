# Installation

## Requirements

- PHP 8.1+ (Symfony 8.x requires PHP 8.4+)
- Symfony 6.0+ | 7.4+ | 8.0+ | 8.1+ (minimum supported minors: 7.4, 8.0, 8.1)
- Doctrine ORM and DoctrineBundle

## Composer

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle
```

## Register the bundle

```php
// config/bundles.php
return [
    // ...
    Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle::class => ['all' => true],
];
```

## Configuration file

With Symfony Flex, the recipe installs `config/packages/nowo_doctrine_deadlock_retry.yaml`. Otherwise copy from `.symfony/recipe/nowo-tech/doctrine-deadlock-retry-bundle/1.0/config/packages/nowo_doctrine_deadlock_retry.yaml` (see [Configuration](CONFIGURATION.md)).
