# Doctrine Deadlock Retry Bundle

[![CI](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/doctrine-deadlock-retry-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/doctrine-deadlock-retry-bundle.svg)](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-6.4%20%7C%207.4%20%7C%208.0%20%7C%208.1-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/DoctrineDeadlockRetryBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from Packagist and give it a star on GitHub.

Symfony bundle that retries `EntityManager::flush()` and custom operations on transient Doctrine DBAL failures (deadlocks, lock wait timeouts, serialization failures), repairs the connection after a failure, and optionally guards long-running workers and maps exhausted retries to 409 + `Retry-After` + `CONFLICT_001`.

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

This includes running when the kernel is **not** reset between requests (see [worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)).

## Features

- **DeadlockRetryService**: `flush(?string $profile)`, `retry(callable $operation, ?string $profile)`, and `getEntityManager()`.
- **Named profiles**: `max_retries`, exponential backoff with full jitter (`backoff_base_ms`, alias `sleep_ms`; `backoff_max_ms`; `jitter`), `retry_lock_wait_timeout`, a `max_total_ms` budget and `rollback_on_deadlock` per use case. Requires DBAL 4 / ORM 3.
- **Default profile**: used when no profile name is passed.
- Detects transient failures in the exception chain: `RetryableException`, MySQL / MariaDB 1213, 1205 and 1020, SQLSTATE 40001 / 40P01 / 55P03 (the ORM's 1305 after a nested deadlock counts through the deadlock it carries).
- After a failure, rolls back what the operation left open and closes the connection only when that rollback fails (DBAL 4 nested deadlocks), so no worker keeps a poisoned nesting level; `retry()` clears the entity manager before a replay.
- Opt-in **worker guard** (`kernel.request` / `kernel.terminate`) and **HTTP mapping** (409 + `Retry-After` + `CONFLICT_001`, `CONFLICT_002` when part was committed, 503 optional). See [docs/CONCURRENCY.md](docs/CONCURRENCY.md). See [high-load readiness](docs/USAGE.md#high-load-readiness).
- Resets a closed EntityManager through `ManagerRegistry` so long-running workers do not keep serving a closed manager.

## Version policy

The Composer package is [`nowo-tech/doctrine-deadlock-retry-bundle`](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle). Source and issues: [`nowo-tech/DoctrineDeadlockRetryBundle`](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle).

We follow [Semantic Versioning](https://semver.org/). See [Changelog](docs/CHANGELOG.md). Security support is described in the [Security policy](.github/SECURITY.md#supported-versions).

## Quick example

```php
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;

public function __construct(
    private readonly DeadlockRetryService $deadlockRetry,
) {
}

public function save(Order $order): void
{
    $this->deadlockRetry->retry(function () use ($order): void {
        $em = $this->deadlockRetry->getEntityManager();
        $em->persist($order);
        $em->flush();
    });
}
```

## Requirements

- PHP >= 8.2, < 8.6 (Symfony 8.x requires PHP 8.4+)
- Symfony 6.4 | 7.4 | 8.0 | 8.1 (minimum tested minors: 6.4, 7.4, 8.0, 8.1)
- Doctrine ORM and DoctrineBundle

## Documentation

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

- [DDD / hexagonal host](docs/USAGE.md#using-it-from-a-ddd--hexagonal-application)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)
- [Demo (Symfony 7 & 8)](demo/README.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [GitHub Actions CI requirements](docs/GITHUB_CI.md)

## Tests and coverage

- Tests: PHPUnit (PHP)
- PHP: 100%
- TS/JS: N/A
- Python: N/A

## License

MIT. See [LICENSE](LICENSE).
