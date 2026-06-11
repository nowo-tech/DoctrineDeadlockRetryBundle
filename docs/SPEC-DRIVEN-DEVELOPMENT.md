# Spec-driven development

Two layers stay in sync:

1. **Product behaviour** — what applications may rely on (`DeadlockRetryService`, profiles, deadlock detection). See [USAGE.md](USAGE.md) and [CONFIGURATION.md](CONFIGURATION.md). **PHPUnit** and **PHPStan** enforce contracts.
2. **Traceability** — `REQ-*` identifiers in Makefiles when scripted behaviour must stay discoverable.

## User stories

| ID | Story |
| --- | --- |
| US-01 | As an integrator, I want automatic retries on DBAL deadlocks so that transient `flush()` failures do not fail the request immediately. |
| US-02 | As an integrator, I want named retry profiles so that heavy batch jobs can use longer backoff than default HTTP flows. |
| US-03 | As an integrator, I want optional rollback before retry so that InnoDB victim transactions can be retried safely. |
| US-04 | As a maintainer, I want tests and PHPStan in CI so that retry semantics do not regress. |

## Functional scope

**In scope**

- `DeadlockRetryService::flush(?string $profile)` and `retry(callable, ?string $profile)`.
- Configuration `nowo_doctrine_deadlock_retry` with `default_profile` and `profiles.*`.
- Detection of `DeadlockException`, SQLSTATE `40001`, and MySQL code `1213` in the exception chain.

**Non-goals**

- Retrying non-deadlock exceptions.
- Replacing application-level transaction orchestration or message-queue idempotency.
- Demos (none in this repository).

## Validating the spec

```bash
make qa
make release-check
```

## Requirement identifiers

| ID | Where | Meaning |
| --- | --- | --- |
| REQ-MAKE-001 | Root `Makefile` | Docker `ensure-up`, standard dev targets |
| REQ-MAKE-002 | Root `Makefile` | `release-check` pipeline |

## See also

- [USAGE.md](USAGE.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [ENGRAM.md](ENGRAM.md)
