# Concurrency: safe writes under contention

Normative source: REQ-RUNTIME-003 of the nowo-tech bundle specs. This bundle owns **no data** (no entities, no DBAL
writes of its own). It is the Doctrine retry runner, the worker guard and the HTTP mapping that other bundles and hosts
rely on, so this page documents what it gives its users. The retry and repair details are in
[USAGE: High-load readiness](USAGE.md#high-load-readiness).

## Table of contents

- [Write paths](#write-paths)
- [Codes](#codes)
- [Host options](#host-options)
- [Relation to transaction-doctrine](#relation-to-transaction-doctrine)
- [Tested on real databases](#tested-on-real-databases)
- [Residual risks](#residual-risks)

## Write paths

| Path | Transaction | Control | Conflict answer |
|------|-------------|---------|-----------------|
| `DeadlockRetryService::flush()` / `retry()` | The callable's own (`wrapInTransaction()` inside it); nothing is retried inside a caller's transaction | Replay with jittered backoff on deadlock (1213, 40P01), lock wait timeout (1205, 55P03; opt-in per profile), serialization failure (40001, MariaDB 1020) and any `RetryableException`; the connection and entity manager are repaired between attempts | Retries exhausted: the failure escapes (`TransientStorageFailure` through the runner) and `http_mapping` answers 409 `CONFLICT_001` |
| `DeadlockRetryTransactionRunner::run()` (`TransactionRunnerInterface` adapter) | One `EntityManager::wrapInTransaction()` per attempt | Same replay; a failed nested rollback (1213 then 1305) closes the connection instead of reporting success | `TransientStorageFailure` with the `Retry-After` hint; `http_mapping`: 409 `CONFLICT_001`, or `CONFLICT_002` when it carries a committed `ConcurrencyConflict` |
| Worker guard (`kernel.request`, `kernel.terminate`, Messenger) | none | Repairs a leftover nesting level, resets closed managers, clears open managers (opt-in `clear_managers`); never flushes or resets an open manager of the host | none |
| `http_mapping` listener | none | Stateless classification of the failure chain | 409 + `Retry-After` + `CONFLICT_001`; 409 without `Retry-After` + `CONFLICT_002`; `conflict_status: 503` keeps the old status |

## Codes

| Code | Meaning | Status | `Retry-After` |
|------|---------|--------|---------------|
| `CONFLICT_001` | Lock conflict (deadlock, lock wait timeout, serialization failure) and **nothing was committed**: replaying is safe | 409 (503 with `conflict_status: 503`) | yes (`retry_after_seconds`, or the hint of the `TransientStorageFailure`) |
| `CONFLICT_002` | Lock conflict and **part of the work may be committed** (a `ConcurrencyConflict` flagged committed, or a lost savepoint after a failed commit): reload before trying again | 409 (503 optional) | never |
| `RESOURCE_CHANGED` | A configured domain conflict (default: the ORM's `OptimisticLockException`). **Not** a lock conflict and never `CONFLICT_*` | 409 | no |

Where the code is: the `code` key of the `problem_json` body (additive; the other keys are unchanged),
`HasErrorCodeInterface::errorCode()` on the HttpException of the default `exception` format, and the response header
`X-Conflict-Code` (`CONFLICT_001` or `CONFLICT_002` only, never `RESOURCE_CHANGED`) in both formats, at 409 and at 503.

A `HandlerFailedException` is answered `CONFLICT_002` (no `Retry-After`) when its envelope carries a `HandledStamp`
(another handler already completed) or when any exception it wraps is a committed `ConcurrencyConflict`.

Not mapped at all (a real error, a 500, unless the host decides otherwise):

- **Unique-constraint violations.** They are not lock conflicts. Catch them where "only one" is an expected race and answer
  the domain result, or list the class in `conflict_exceptions` (then it answers `RESOURCE_CHANGED`).
- **Connection loss**, and a failed `COMMIT` whose outcome is unknown and that carries no lost savepoint.
- Any other database error.

## Host options

```yaml
nowo_doctrine_deadlock_retry:
    retry_after_seconds: 1
    worker_guard: { enabled: true }           # FrankenPHP worker mode with kernel reset false
    http_mapping:
        enabled: true
        paths: ['/api']
        conflict_status: 409                  # or 503
        conflict_exceptions: [Doctrine\ORM\OptimisticLockException]
        format: exception                     # or problem_json
```

- `conflict_status`: `409` (default) or `503` for hosts that keep the old answer. The codes do not change.
- Hosts that want their own JSON shape use `format: exception` and read `HasErrorCodeInterface::errorCode()` in their
  error renderer.
- Standard and DDD hosts: the services keep no per-request state; the private entity manager is chosen with
  `entity_manager`. See [CONFIGURATION](CONFIGURATION.md).

## Relation to transaction-doctrine

`nowo-tech/transaction-doctrine` `^1.5` is a dependency: this bundle takes `ConflictCode` (the codes and which one a
failure gets) and `TransientFailureClassifier::isCommitOutcomeUnknown()` (the `CONFLICT_002` rule) from it. The answers
differ in the cases only this bundle sees: a `HandledStamp`, a `DelayedMessageHandlingException` and a
`TransientStorageFailure` over a non-storage error answer `CONFLICT_002` here, which `ConflictCode::forFailure()` alone does
not. The two packages still carry **separate** classifiers and connection
repairers: this bundle's walks Messenger's `HandlerFailedException` (every wrapped exception transient, no
`HandledStamp`) and `TransientStorageFailure` hints, which the transaction-doctrine classifier does not. Removing the copies
needs that behaviour moved upstream first (a follow-up). Hosts that use `#[AsWriteUnit]` and the transaction-doctrine responder do not need `http_mapping`.

## Tested on real databases

The `Real databases` CI job (required on `main`) runs `tests/Database` against MySQL 8.4, MariaDB 11.4 and 11.8 and
PostgreSQL 16, with no test skipped: a nested deadlock with two sessions, lock wait timeouts, PostgreSQL
serialization failures, the worker guard, Messenger replay and the end-to-end 409 `CONFLICT_001` answer.

## Residual risks

- **Request-level detection.** The code is decided from the failure only. Work committed earlier in the same request by
  code that is not in the failure chain is not seen; request-level detection arrives with transaction-doctrine 1.6
  `forRequest()`.
- **Two responders.** When the `nowo-tech/transaction-doctrine` Symfony bundle is also registered, its conflict responder
  (priority +64) answers first, with its own JSON keys and `Retry-After` policy. Once it sets a response, propagation stops and this
  listener (-64) never runs for that failure. Enable only one of the two on the same paths.
- A replay is only safe for work that is **idempotent per attempt**: the callable must redo load, modify and flush, keep
  no entity from a previous attempt, and run external side effects (mail, HTTP, files) after the commit. The bundle
  cannot check that.
- A Messenger dispatch with several handlers is replayed only when no handler completed (no `HandledStamp`) and the
  dispatch runs in one transaction; see [CONFIGURATION](CONFIGURATION.md#failures-wrapped-by-messenger).
- `CONFLICT_002` is inferred from a lost savepoint or an explicit `committed` flag. A server that loses the savepoint without
  a recognisable 1305 message is not detected and stays a 500.
- With `clear_managers` off, open managers are not cleared at the end of a request; that is the host's choice.
- DBAL 4 and ORM 3 only.
