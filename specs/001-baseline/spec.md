# Feature Specification: DoctrineDeadlockRetryBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Updated**: 2026-09-24  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md), [`docs/FRANKENPHP-WORKER-AUDIT.md`](../../docs/FRANKENPHP-WORKER-AUDIT.md)  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/doctrine-deadlock-retry-bundle`  
**Configuration root**: `nowo_doctrine_deadlock_retry`

Symfony bundle that retries Doctrine `flush()` and arbitrary callables when DBAL reports a **deadlock**, using named **retry profiles** (max attempts, sleep, optional rollback). Compatible with FrankenPHP worker mode when the kernel is **not** reset between requests (scenario B): a closed EntityManager is recovered through `ManagerRegistry::resetManager()`.

---

## User Scenarios & Testing

### US-01 — Automatic flush retry (P1)

**Given** a concurrent write causes a deadlock on `EntityManager::flush()` and the manager **stays open**, **When** the application calls `DeadlockRetryService::flush()`, **Then** the service retries up to the configured profile limit before rethrowing.

### US-02 — Named profiles (P1)

**Given** profiles `default` and `batch` in config, **When** `flush('batch')` is invoked, **Then** `batch` max_retries / sleep_ms / rollback_on_deadlock apply instead of the default profile.

### US-03 — Rollback before retry (P2)

**Given** `rollback_on_deadlock: true` and an active ORM transaction, **When** a deadlock is detected, **Then** the transaction rolls back before the next attempt.

### US-04 — Non-deadlock passthrough (P1)

**Given** any non-deadlock exception during `retry()`, **When** the operation fails, **Then** the original exception is thrown immediately without retry, and a closed manager is still reset when a registry is available.

### US-05 — Closed manager after flush deadlock (P1)

**Given** Doctrine ORM closes the entity manager on a failed flush (deadlock), **When** `flush()` is used, **Then** the service resets the manager via `ManagerRegistry` (when wired) and rethrows the original deadlock (the unit of work cannot be replayed).

### US-06 — Replayable retry under worker / no kernel reset (P1)

**Given** a long-running worker with no kernel reset between requests, **When** `retry(callable)` fails with a deadlock that closed the manager, **Then** the manager is reset before the next attempt and the callable can obtain an open manager with `getEntityManager()`.

---

## Requirements

### Bundle & DI

- **FR-BUNDLE-001**: `NowoDoctrineDeadlockRetryBundle` registers extension alias `nowo_doctrine_deadlock_retry`.
- **FR-DI-001**: `services.yaml` autowires `DeadlockRetryService` with `%nowo_doctrine_deadlock_retry.profiles%`, `%nowo_doctrine_deadlock_retry.default_profile%`, and optional `$managerRegistry: '@?doctrine'`.

### Configuration

- **FR-CFG-001**: `Configuration` defines `default_profile` (non-empty string, default `default`) and `profiles` map with per-profile `max_retries`, `sleep_ms`, `rollback_on_deadlock`.
- **FR-CFG-002**: Extension validates profiles at compile time and exposes parameters for the service.

### Retry profiles

- **FR-PROFILE-001**: `RetryProfile` encapsulates `maxAttempts()`, sleep interval, and rollback flag immutably.
- **FR-PROFILE-002**: Unknown profile names MUST throw `UnknownRetryProfileException`.

### Deadlock detection & retry loop

- **FR-SVC-001**: `DeadlockRetryService::flush(?string $profile)` runs `getEntityManager()->flush()` under the retry loop. When a deadlock closes the manager, the manager is reset (if a registry is available) and the original deadlock is rethrown (not silently retried as a no-op flush).
- **FR-SVC-002**: `retry(callable $operation, ?string $profile)` MUST detect deadlocks via `DeadlockException`, SQLSTATE `40001`, and MySQL error `1213` in the exception chain; sleep between attempts per profile; reset a closed manager before the next attempt; rethrow after max attempts.
- **FR-SVC-003**: `getEntityManager()` MUST return the current open manager, resetting a closed one through `ManagerRegistry::resetManager()` when a registry is available.
- **FR-SVC-004**: A closed manager MUST also be reset before rethrowing a non-deadlock exception, so later requests on the same worker do not keep a closed manager.

---

### Load readiness (3.0)

- **FR-CLASS-001**: `TransientFailureClassifier` decides from driver error codes along the previous chain whether a failure is transient (`TransientFailureKind`: deadlock, lock wait timeout, serialization failure, DBAL `RetryableException`); a bare `1305` savepoint error and a connection lost during commit are never transient; messages are never read or logged.
- **FR-RETRY-001**: Profiles bound retries with jittered exponential backoff (`backoff_base_ms`, alias `sleep_ms`; `backoff_max_ms`) and an optional total budget `max_total_ms`.
- **FR-REPAIR-001**: `ConnectionRepairer` brings a connection back to a known nesting level after a failure (rollback first, close only when the rollback fails).
- **FR-WORKER-001**: Opt-in `worker_guard`: `WorkerGuardSubscriber` (request start / `kernel.terminate`) and `MessengerWorkerGuardSubscriber` (message handled / failed) run `ConnectionGuard`, which repairs connections left in a transaction, resets closed managers and optionally clears managers; only services already initialised are touched; each repair logs one warning without SQL.
- **FR-HTTP-001**: Opt-in `http_mapping`: `TransientFailureExceptionListener` maps transient failures that reach the kernel to `503` + `Retry-After` and configured conflict classes to `409` (Symfony `HttpException` or RFC 9457 problem+json), with a generic client text.
- **FR-TX-001**: `DeadlockRetryTransactionRunner` implements `nowo-tech/transaction-contract`'s `TransactionRunnerInterface`; `TransactionRunnerAliasPass` aliases the contract to it.
- **FR-SVC-005**: `DeadlockRetryInterface` is the public contract of `DeadlockRetryService`.
- **FR-DI-002**: `NowoDoctrineDeadlockRetryServiceIds` holds the bundle's service ids for compiler passes and hosts.
- **FR-CLASS-002**: Failure chains are walked through `ThrowableChain`: `getPrevious()` and, with `symfony/messenger`, `WrappedExceptionsInterface::getWrappedExceptions()`, recursively, at most 64 levels and 256 throwables. A `HandlerFailedException` is transient only when every wrapped exception is transient and its envelope has no `HandledStamp` (strongest kind wins); a `DelayedMessageHandlingException` never is; 409 needs every wrapped exception to be a conflict. A `TransientStorageFailure` without previous is `Retryable`, over a storage error it takes that error's kind, over anything else it is not transient (503 still applies to any `TransientStorageFailure`).
- **FR-SLEEP-001**: `DeadlockRetryService` waits between attempts through `SleeperInterface::sleepMilliseconds(int)`, aliased to `UsleepSleeper`; hosts re-alias it (optional constructor argument, stateless).
- **FR-RETRY-002**: Profile option `backoff_min_ms` (default 0, at most `backoff_max_ms`, at most `max_total_ms` when set): with full jitter each delay is uniform between it and the exponential bound; without jitter the bound never falls below it.

## Success Criteria

- **SC-001**: **18/18** production files under `src/` mapped in [`code-inventory.md`](code-inventory.md).
- **SC-002**: Documented config keys match `Configuration.php` and [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md).
- **SC-003**: `make qa` / `make release-check` pass in CI.
- **SC-004**: PHPUnit covers flush retry, profile selection, rollback, non-deadlock passthrough, closed-manager reset, and no leak across requests without kernel reset.
- **SC-005**: PHPStan includes FrankenPHP classic + worker-strict rulesets with zero findings on `src/` and `tests/`.

---

## Explicit non-goals

- Retrying non-deadlock exceptions or generic connection errors.
- Replacing application-level saga / outbox patterns.
- Clearing the EntityManager identity map between HTTP requests (application responsibility under scenario B).
- Guaranteeing that `flush()` alone can replay ORM writes after Doctrine closes the manager (use `retry()` for that).

---

## Validation

| Check | Command |
| --- | --- |
| QA | `make qa` |
| Release gate | `make release-check` |
| Inventory | Row count matches `find src -type f` production set |

When changing behavior, update this spec, `code-inventory.md`, tests, and integrator docs.
