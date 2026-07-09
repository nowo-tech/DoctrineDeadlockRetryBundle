# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/doctrine-deadlock-retry-bundle`  
**Last audited**: 2026-07-07

Every production artifact under `src/` is listed below. Tests under `tests/` are out of Packagist scope.

## PHP classes (`src/**/*.php`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoDoctrineDeadlockRetryBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `Config/RetryProfile.php` | Retry profile value object | FR-PROFILE-001 |
| `DependencyInjection/Configuration.php` | Config tree `nowo_doctrine_deadlock_retry` | FR-CFG-001 |
| `DependencyInjection/NowoDoctrineDeadlockRetryExtension.php` | DI extension, profile wiring | FR-CFG-002 |
| `Exception/UnknownRetryProfileException.php` | Unknown profile error | FR-PROFILE-002 |
| `Service/DeadlockRetryService.php` | `flush()` / `retry()` with backoff | FR-SVC-001, FR-SVC-002 |

## Symfony config (`src/Resources/config/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | `DeadlockRetryService` wiring | FR-DI-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 6 | 6 |
| YAML config | 1 | 1 |
| **Total production sources** | **7** | **7** |
