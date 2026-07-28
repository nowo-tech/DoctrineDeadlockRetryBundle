# Security – DoctrineDeadlockRetryBundle

## Table of contents

- [Summary](#summary)
- [Logging and observability (REQ-OBS-001)](#logging-and-observability-req-obs-001)
- [Aspects reviewed](#aspects-reviewed)
- [Reporting](#reporting)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)

## Summary

The bundle exposes a single service (`DeadlockRetryService`) and configuration. It does not register HTTP routes or store secrets. Integrators must still avoid logging sensitive SQL or entity data in custom deadlock handlers.

## Logging and observability (REQ-OBS-001)

This bundle **does not** inject `Psr\Log\LoggerInterface` / Monolog by default. Retries are intentional and silent unless the application wraps calls.

- **Never** log full SQL, bind parameters, credentials, or entity dumps that may contain PII from custom catch/retry wrappers.
- Prefer structured context (`['bundle' => 'doctrine-deadlock-retry', 'profile' => '…', 'attempt' => n]`) if you add logging in the host app.
- Shipped `src/` must not use `dump()`, `error_log()`, or `var_dump()`.

## Aspects reviewed

- **Retry loops**: `max_retries` and `sleep_ms` are bounded by configuration; misconfiguration could delay requests but not bypass authorization.
- **Rollback**: When `rollback_on_deadlock` is true, the active ORM transaction is rolled back before retry; callers must re-apply work (see [USAGE.md](USAGE.md)).
- **No secrets** in default configuration.

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
| **Permissions / exposure** | No HTTP routes; service used only by application code. |
| **Limits / DoS** | `max_retries` and `sleep_ms` appropriate for production latency budgets. |

Record confirmation in the release PR or tag notes.

## AI security audit (REQ-SEC-004)

| Field | Value |
|-------|--------|
| **Date** | 2026-07-28 |
| **Method** | Campaign static review of `src/`, Flex recipe, demos, and this document |
| **Grade** | **Pass (good)** |
| **Overall residual risk** | **Low** |

### Residuals (accepted)

- Misconfigured `max_retries` / `sleep_ms` can increase request latency under contention — operational, not auth bypass.
- Host applications remain responsible for not logging sensitive SQL in custom wrappers.

No Critical/High findings remain open for shipping.
