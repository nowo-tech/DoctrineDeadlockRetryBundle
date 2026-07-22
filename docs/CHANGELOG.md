# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [2.0.3] - 2026-07-22

### Added

- Demo `FRANKENPHP_MODE` (`classic` \| `worker`, default **worker**) via `.env.example`, Compose, and `docker/entrypoint.sh` (REQ-DEMO-010).

### Changed

- Demo Dockerfiles copy `docker/entrypoint.sh` instead of an inline entrypoint script.

### Documentation

- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md): document `FRANKENPHP_MODE` switching (recreate container, no rebuild).
- Demo README: FrankenPHP mode section.

## [2.0.2] - 2026-07-20

### Added

- Code of Conduct ([CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md)).
- Git hygiene tooling (REQ-GIT-001): `.githooks/commit-msg`, `make setup-hooks`, `make check-no-cursor-coauthor`, and CI job `git-hygiene`.
- [GITHUB_CI.md](GITHUB_CI.md) documenting CI requirements for commit history.

### Changed

- Demo dependency locks and Makefile paths for update-deps targets.
- Dev dependency updates in `composer.lock`.

### Documentation

- Contributing: Code of Conduct and git hooks setup.
- Release: re-run `make check-no-cursor-coauthor` after tagging, before push.
- README links to GITHUB_CI and Code of Conduct.

## [2.0.1] - 2026-07-09

### Changed

- Internal exception-chain detection in `DeadlockRetryService` (no public API change).
- Dev dependency updates in `composer.lock`.

### Added

- CodeRabbit configuration and GitHub Actions workflow.
- GitHub Spec Kit documentation and spec-driven development tooling.

### Documentation

- Expanded release security checklist in [SECURITY.md](SECURITY.md).
- README badges (GitHub stars, coverage).
- [SPEC-KIT.md](SPEC-KIT.md) guide.

## [2.0.0] - 2026-06-11

### Changed

- Minimum PHP version is **8.2** (`composer.json`, CI, and documentation).
- `RetryProfile` uses `readonly class` again (PHP 8.2+).
- CI matrix runs PHP **8.2–8.5** (PHP 8.1 removed).

### Removed

- PHP **8.1** support. Remain on **1.0.1** if you cannot upgrade PHP.

## [1.0.1] - 2026-06-11

### Fixed

- `RetryProfile` PHP 8.1 compatibility: promoted `readonly` properties instead of `readonly class`.

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

[2.0.3]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.3
[2.0.2]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.2
[2.0.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.1
[2.0.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.0
[1.0.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v1.0.0
