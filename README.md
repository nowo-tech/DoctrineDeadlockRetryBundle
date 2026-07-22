# Doctrine Deadlock Retry Bundle

[![CI](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/doctrine-deadlock-retry-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle)
[![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/doctrine-deadlock-retry-bundle.svg)](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-6.0%2B%20%7C%207.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com)
[![GitHub stars](https://img.shields.io/github/stars/nowo-tech/doctrine-deadlock-retry-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle)
[![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from Packagist and give it a star on GitHub.

Symfony bundle that retries `EntityManager::flush()` and custom operations when Doctrine DBAL raises a deadlock (`SQLSTATE[40001]`, MySQL error 1213).

## Features

- **DeadlockRetryService**: `flush(?string $profile)` and `retry(callable $operation, ?string $profile)`.
- **Named profiles**: configure `max_retries`, `sleep_ms`, and `rollback_on_deadlock` per use case.
- **Default profile**: used when no profile name is passed.
- Detects `DeadlockException` and related driver errors in the exception chain.

## Documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Demo (Symfony 7 & 8)](demo/README.md) — run `make -C demo up-symfony8` from the bundle root.
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md) — `FRANKENPHP_MODE` (`classic` \| `worker`, default **worker**).

## Quick example

```php
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;

public function __construct(
    private readonly DeadlockRetryService $deadlockRetry,
) {
}

public function save(Order $order): void
{
    $this->entityManager->persist($order);
    $this->deadlockRetry->flush();
    $this->deadlockRetry->flush('batch');
}
```

## Requirements

- PHP >= 8.2, < 8.6 (Symfony 8.x requires PHP 8.4+)
- Symfony 6.0+ | 7.4+ | 8.0+ | 8.1+ (minimum tested minors: 7.4, 8.0, 8.1)
- Doctrine ORM and DoctrineBundle

## Tests and coverage

- Tests: PHPUnit (PHP)
- PHP: 100%

## Version policy

The Composer package is [`nowo-tech/doctrine-deadlock-retry-bundle`](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle). Source and issues: [`nowo-tech/DoctrineDeadlockRetryBundle`](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle).

We follow [Semantic Versioning](https://semver.org/). See [Changelog](docs/CHANGELOG.md). Security support is described in the [Security policy](.github/SECURITY.md#supported-versions).

## License

MIT. See [LICENSE](LICENSE).
