# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/doctrine-deadlock-retry-bundle`  
**Last audited**: 2026-11-16 (3.0.0)

Every production artifact under `src/` is listed below. Tests under `tests/` are out of Packagist scope.

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoDoctrineDeadlockRetryBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `Config/RetryProfile.php` | Retry profile value object | FR-PROFILE-001 |
| `DependencyInjection/Configuration.php` | Config tree `nowo_doctrine_deadlock_retry` | FR-CFG-001 |
| `DependencyInjection/NowoDoctrineDeadlockRetryExtension.php` | DI extension, profile wiring | FR-CFG-002 |
| `Exception/UnknownRetryProfileException.php` | Unknown profile error | FR-PROFILE-002 |
| `Service/DeadlockRetryService.php` | `flush()` / `retry()` / `getEntityManager()` with backoff and closed-manager recovery | FR-SVC-001, FR-SVC-002, FR-SVC-003, FR-SVC-004 |

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | `DeadlockRetryService` wiring (`@?doctrine`) | FR-DI-001 |

## Additions 3.0 (classification, repair, worker guard, HTTP mapping, transaction runner)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Connection/ConnectionRepairer.php` | Connection repair (3.0) | FR-REPAIR-001 |
| `Contract/DeadlockRetryInterface.php` | Public contract | FR-SVC-005 |
| `DependencyInjection/Compiler/NowoDoctrineDeadlockRetryServiceIds.php` | Service ids | FR-DI-002 |
| `DependencyInjection/Compiler/TransactionRunnerAliasPass.php` | Transaction runner adapter (3.0) | FR-TX-001 |
| `EventListener/MessengerWorkerGuardSubscriber.php` | Worker guard (3.0) | FR-WORKER-001 |
| `EventListener/TransientFailureExceptionListener.php` | HTTP mapping (3.0) | FR-HTTP-001 |
| `EventListener/WorkerGuardSubscriber.php` | Worker guard (3.0) | FR-WORKER-001 |
| `Failure/TransientFailureClassifier.php` | Failure classification (3.0) | FR-CLASS-001 |
| `Failure/TransientFailureKind.php` | Failure classification (3.0) | FR-CLASS-001 |
| `Transaction/DeadlockRetryTransactionRunner.php` | Transaction runner adapter (3.0) | FR-TX-001 |
| `Worker/ConnectionGuard.php` | Worker guard (3.0) | FR-WORKER-001, FR-REPAIR-001 |

## Additions 3.1 (write-unit parity: sleeper port, Messenger-wrapped failures, minimum backoff)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Failure/ThrowableChain.php` | Failure chain walk (3.1) | FR-CLASS-002 |
| `Sleep/SleeperInterface.php` | Sleeper port (3.1) | FR-SLEEP-001 |
| `Sleep/UsleepSleeper.php` | Sleeper port (3.1) | FR-SLEEP-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 6 | 6 |
| YAML config | 1 | 1 |
| Additions 3.0 | 11 | 11 |
| Additions 3.1 | 3 | 3 |
| **Total production sources** | **21** | **21** |
