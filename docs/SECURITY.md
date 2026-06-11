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

Before tagging a release:

- [ ] No credentials or tokens in the repository or default config.
- [ ] Dependencies reviewed (`composer update` / Dependabot).
- [ ] Tests and static analysis pass in CI.
- [ ] [CHANGELOG.md](CHANGELOG.md) documents security-relevant changes if any.
