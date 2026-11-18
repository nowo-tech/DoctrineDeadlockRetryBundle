# Security – DoctrineDeadlockRetryBundle

## Table of contents

- [Summary](#summary)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Logging and observability (REQ-OBS-001)](#logging-and-observability-req-obs-001)
- [Aspects reviewed](#aspects-reviewed)
- [Dependencies](#dependencies)
- [Permissions](#permissions)
- [Reporting](#reporting)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
  - [Residuals (accepted)](#residuals-accepted)

## Summary

The bundle replays Doctrine units of work that failed for a transient reason (deadlock, lock wait timeout, serialization failure). Since 3.0 it also repairs connections a failure left in a transaction, offers an opt-in worker guard for long-running processes, maps transient failures that reach the kernel to `409` + `CONFLICT_001` / `CONFLICT_002` (`503` with `conflict_status: 503`; opt-in `http_mapping`) and adapts `nowo-tech/transaction-contract`'s `TransactionRunnerInterface`. It registers no routes and stores no secrets.

## Attack surface

| Surface | Exposure | Controls |
| --- | --- | --- |
| `DeadlockRetryService` / `DeadlockRetryTransactionRunner` | Application code only | Bounded profiles: `max_retries`, jittered backoff capped by `backoff_max_ms`, optional total budget `max_total_ms` |
| `TransientFailureExceptionListener` (opt-in `http_mapping`) | Every HTTP response that ends in a transient storage failure | Client sees a generic text with `409` + `Retry-After` + `CONFLICT_001` (or `CONFLICT_002` without `Retry-After`; `503` with `conflict_status: 503`); SQL, parameters and driver messages stay in the previous exception |
| `WorkerGuardSubscriber` / `MessengerWorkerGuardSubscriber` (opt-in `worker_guard`) | Every request / message of a long-running worker | Rolls back or closes connections left in a transaction, resets closed managers, optionally clears managers; touches only services already initialised |
| Configuration | Deployment | Strict tree; unknown profile names fail with `UnknownRetryProfileException` |

## Threat model and mitigations

- **Replaying work that already committed** — `TransientFailureClassifier` reads driver error codes only and refuses ambiguous cases (a bare `1305` savepoint error, a connection lost during commit, a `TransientStorageFailure` over a non-database error), so only failures where a full replay is safe are retried. Inside a Messenger `HandlerFailedException` every wrapped exception must be transient and no handler may have completed (no `HandledStamp`); a `DelayedMessageHandlingException` is never retried. A replayed dispatch is still only safe when it runs in one transaction, every handler writes through the shared transaction-bound entity manager or connection, and side effects wait for the commit; a raw DBAL write after a deadlock is auto-committed (ghost transaction). See [CONFIGURATION](CONFIGURATION.md#failures-wrapped-by-messenger).
- **Poisoned connection reused by the next request or message** — `ConnectionRepairer` rolls back to a known nesting level and closes the connection only when the rollback fails; the worker guard runs it at request / message boundaries.
- **Worker slot held by long retry loops** (availability) — retries and delays are bounded per profile and by `max_total_ms`; delays are jittered to avoid synchronized retries.
- **Information disclosure through error responses** — `http_mapping` never puts the original exception message (SQL, bind values) in the response.
- **Caller-supplied callables** — the service runs the callable the application passes; it never builds SQL from input.

## Logging and observability (REQ-OBS-001)

`DeadlockRetryService` and `ConnectionGuard` accept an optional `Psr\Log\LoggerInterface` (the `logger` service when present, wired with `NULL_ON_INVALID_REFERENCE`):

| Event | Level | Context |
| --- | --- | --- |
| Transient failure, operation retried | `info` | failure kind, profile, attempt, `delay_ms` |
| Connection left in a transaction and repaired | `warning` | connection name, nesting level, action |
| Entity manager left closed / with pending changes | `warning` | manager name |

Never logged: SQL, bind parameters, exception messages, entity data or credentials.

- **Never** log full SQL, bind parameters, credentials, or entity dumps that may contain PII from custom catch/retry wrappers.
- Prefer structured context (`['bundle' => 'doctrine-deadlock-retry', 'profile' => '…', 'attempt' => n]`) if you add logging in the host app.
- Shipped `src/` must not use `dump()`, `error_log()`, or `var_dump()`.

## Aspects reviewed

- **Retry loops**: `max_retries` and `sleep_ms` are bounded by configuration; misconfiguration could delay requests but not bypass authorization.
- **Rollback**: When `rollback_on_deadlock` is true, the active ORM transaction is rolled back before retry; callers must re-apply work (see [USAGE.md](USAGE.md)).
- **No secrets** in default configuration.

## Dependencies

- Runtime: `doctrine/dbal`, `doctrine/orm`, `doctrine/doctrine-bundle`, Symfony components, `psr/log`, `nowo-tech/transaction-contract`. QA tools stay in `require-dev`.
- CI runs `composer audit --locked`; Dependabot watches Composer and GitHub Actions.

## Permissions

The bundle adds no roles, voters or routes and performs no authorization: replayed operations run with the permissions of the code that called the service. Database credentials come from the host's Doctrine configuration.

## Reporting

Report security issues via GitHub Security Advisories on [DoctrineDeadlockRetryBundle](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/security/advisories).

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README where applicable. |
| **`.gitignore` and `.env`** | `.env` and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, or tokens in tracked files. |
| **Recipe / Flex** | Default recipe or installer templates do not ship production secrets. |
| **Input / output** | Retry configuration bounded; handlers must not log sensitive SQL/entity data. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | No Monolog channel by default; custom handlers reviewed for PII and query leakage. |
| **Cryptography** | N/A — no custom cryptography in this bundle. |
| **Permissions / exposure** | No HTTP routes; service used only by application code; `http_mapping` responses stay generic. |
| **Limits / DoS** | `max_retries`, `backoff_base_ms` / `backoff_max_ms` and `max_total_ms` appropriate for production latency budgets. |

Record confirmation in the release PR or tag notes.

## AI security audit (REQ-SEC-004)

| Field | Value |
|-------|--------|
| **Date** | 2026-11-15 (3.0.0, monorepo `BUNDLES_SECURITY_ANALYSIS.md` Appendix QA; first pass 2026-07-28) |
| **Method** | Full review of the 3.0.0 diff (classifier, connection repair, worker guard, HTTP mapping, transaction runner) plus the campaign static review of `src/`, Flex recipe, demos and this document |
| **Grade** | **Pass (good)** |
| **Overall residual risk** | **Low** |

### Residuals (accepted)

- Misconfigured `max_retries` / backoff / `max_total_ms` can increase request latency under contention — operational, not auth bypass.
- Host applications remain responsible for not logging sensitive SQL in custom wrappers.

No Critical/High findings remain open for shipping.
