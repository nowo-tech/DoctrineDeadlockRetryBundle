# Security – DoctrineDeadlockRetryBundle

## Summary

The bundle exposes a single service (`DeadlockRetryService`) and configuration. It does not register HTTP routes or store secrets. Integrators must still avoid logging sensitive SQL or entity data in custom deadlock handlers.

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
| **Logging** | Custom deadlock handlers reviewed for PII and query leakage. |
| **Cryptography** | N/A — no custom cryptography in this bundle. |
| **Permissions / exposure** | No HTTP routes; service used only by application code. |
| **Limits / DoS** | `max_retries` and `sleep_ms` appropriate for production latency budgets. |

Record confirmation in the release PR or tag notes.
