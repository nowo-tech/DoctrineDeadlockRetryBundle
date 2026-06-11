# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.0.1] - 2026-06-11

### Fixed

- `RetryProfile` no longer uses `readonly class` (PHP 8.2+ syntax); promoted properties are `readonly` instead so the bundle loads on PHP 8.1 as documented.

## [1.0.0] - 2026-05-20

### Added

- `DeadlockRetryService` with `flush(?string $profile)` and `retry(callable $operation, ?string $profile)`.
- Configuration key `nowo_doctrine_deadlock_retry` with named profiles (`max_retries`, `sleep_ms`, `rollback_on_deadlock`).
- Default profile when no profile name is passed.
- Detection of `DeadlockException`, SQLSTATE `40001`, and MySQL error `1213` in the exception chain.
- Symfony Flex recipe at `.symfony/recipe/nowo-tech/doctrine-deadlock-retry-bundle/1.0/`.
- FrankenPHP demos for Symfony 7.4 and Symfony 8.1.
- CI matrix for PHP 8.1–8.5 and Symfony 6.4, 7.0, 7.4, 8.0, and 8.1.

### Documentation

- Installation, configuration, usage, security, upgrading, and FrankenPHP demo guides.

[1.0.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v1.0.0
