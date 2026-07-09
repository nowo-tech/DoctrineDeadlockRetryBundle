# Feature Specification: DoctrineDeadlockRetryBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/doctrine-deadlock-retry-bundle`  
**Configuration root**: `nowo_doctrine_deadlock_retry`

Symfony bundle that retries Doctrine `flush()` and arbitrary callables when DBAL reports a **deadlock**, using named **retry profiles** (max attempts, sleep, optional rollback).

---

## User Scenarios & Testing

### US-01 — Automatic flush retry (P1)

**Given** a concurrent write causes a deadlock on `EntityManager::flush()`, **When** the application calls `DeadlockRetryService::flush()`, **Then** the service retries up to the configured profile limit before rethrowing.

### US-02 — Named profiles (P1)

**Given** profiles `default` and `batch` in config, **When** `flush('batch')` is invoked, **Then** `batch` max_retries / sleep_ms / rollback_on_deadlock apply instead of the default profile.

### US-03 — Rollback before retry (P2)

**Given** `rollback_on_deadlock: true` and an active ORM transaction, **When** a deadlock is detected, **Then** the transaction rolls back before the next attempt.

### US-04 — Non-deadlock passthrough (P1)

**Given** any non-deadlock exception during `retry()`, **When** the operation fails, **Then** the original exception is thrown immediately without retry.

---

## Requirements

### Bundle & DI

- **FR-BUNDLE-001**: `NowoDoctrineDeadlockRetryBundle` registers extension alias `nowo_doctrine_deadlock_retry`.
- **FR-DI-001**: `services.yaml` autowires `DeadlockRetryService` with `%nowo_doctrine_deadlock_retry.profiles%` and `%nowo_doctrine_deadlock_retry.default_profile%`.

### Configuration

- **FR-CFG-001**: `Configuration` defines `default_profile` (non-empty string, default `default`) and `profiles` map with per-profile `max_retries`, `sleep_ms`, `rollback_on_deadlock`.
- **FR-CFG-002**: Extension validates profiles at compile time and exposes parameters for the service.

### Retry profiles

- **FR-PROFILE-001**: `RetryProfile` encapsulates `maxAttempts()`, sleep interval, and rollback flag immutably.
- **FR-PROFILE-002**: Unknown profile names MUST throw `UnknownRetryProfileException`.

### Deadlock detection & retry loop

- **FR-SVC-001**: `DeadlockRetryService::flush(?string $profile)` delegates to `retry()` wrapping `EntityManager::flush()`.
- **FR-SVC-002**: `retry(callable $operation, ?string $profile)` MUST detect deadlocks via `DeadlockException`, SQLSTATE `40001`, and MySQL error `1213` in the exception chain; sleep between attempts per profile; rethrow after max attempts.

---

## Success Criteria

- **SC-001**: **7/7** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md).
- **SC-002**: Documented config keys match `Configuration.php` and [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: `make qa` / `make release-check` pass in CI.
- **SC-004**: PHPUnit covers flush retry, profile selection, rollback, and non-deadlock passthrough.

---

## Explicit non-goals

- Retrying non-deadlock exceptions or generic connection errors.
- Replacing application-level saga / outbox patterns.
- Demos (none shipped in this repository).

---

## Validation

| Check | Command |
| --- | --- |
| QA | `make qa` |
| Release gate | `make release-check` |
| Inventory | Row count matches `find src -type f` production set |

When changing behavior, update this spec, `code-inventory.md`, tests, and integrator docs.
