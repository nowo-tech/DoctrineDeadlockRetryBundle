# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 3.1.0](#to-310)
- [To 3.0.0](#to-300)
- [To 2.4.0](#to-240)
- [To 2.3.0](#to-230)
- [To 2.2.2](#to-222)
- [To 2.2.1](#to-221)
- [To 2.2.0](#to-220)
- [To 2.1.3](#to-213)
- [To 2.1.2](#to-212)
- [To 2.1.1](#to-211)
- [From 2.0.9 to 2.1.0](#from-209-to-210)
- [From 2.0.8 to 2.0.9](#from-208-to-209)
- [To 2.0.8](#to-208)
- [To 2.0.7](#to-207)
- [To 2.0.6](#to-206)
- [To 2.0.5](#to-205)
- [To 2.0.4](#to-204)
- [To 2.0.3](#to-203)
- [To 2.0.2](#to-202)
- [To 2.0.1](#to-201)
- [To 2.0.0](#to-200)
- [To 1.0.1](#to-101)
- [To 1.0.0](#to-100)

## Unreleased

Minor release (behaviour of the opt-in `http_mapping` changes). `composer update nowo-tech/doctrine-deadlock-retry-bundle`
also installs `nowo-tech/transaction-doctrine ^1.5`.

- **409 instead of 503.** A deadlock, lock wait timeout or serialization failure that reaches the kernel now answers
  **409 + `Retry-After` + `CONFLICT_001`**. Keep the old status with `http_mapping.conflict_status: 503` (the codes stay).
- **`CONFLICT_002`**: when part of the work may be committed (`ConcurrencyConflict::$committed`, or a lost savepoint after
  a failed commit) the answer is 409 **without** `Retry-After`. Clients must reload before retrying.
- **`instanceof` checks.** A host check `instanceof ServiceUnavailableHttpException` only matches with `conflict_status: 503`; by default the exception is a `ConflictHttpException` subclass.
- **`X-Conflict-Code` header** (`CONFLICT_001` / `CONFLICT_002`) is sent in both formats and both statuses; a `HandlerFailedException` with a completed handler, a `DelayedMessageHandlingException`, a committed conflict, or a `TransientStorageFailure` over a non-storage error answers `CONFLICT_002` (a lock conflict behind such a wrapper is now answered instead of a 500).
- **Shapes keep their keys.** `problem_json` gains a `code` key; the `exception` format throws subclasses of
  `ConflictHttpException` / `ServiceUnavailableHttpException` (see the `instanceof` note above). Read the code with
  `HasErrorCodeInterface::errorCode()`.
- **Default `conflict_exceptions` no longer lists `UniqueConstraintViolationException`**: a unique violation is not a lock
  conflict. Catch it where "only one" is an expected race and answer your domain result, or list it yourself (it then
  answers 409 `RESOURCE_CHANGED`, never `CONFLICT_*`). `OptimisticLockException` stays, with the code `RESOURCE_CHANGED`.

## To 3.1.0

From **3.0.0**:

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

From **3.0.0**, `composer update nowo-tech/doctrine-deadlock-retry-bundle`. No configuration change is required.

- **New profile option `backoff_min_ms`** (default `0`, so delays are unchanged). With full jitter each delay becomes
  random between it and the exponential bound. A value above `backoff_max_ms`, or a `max_total_ms` below it, is a
  configuration error.
- **`SleeperInterface`**: the service waits through `Sleep\SleeperInterface` (default `UsleepSleeper`). Code that
  builds `DeadlockRetryService` by hand keeps working: the new `$sleeper` argument is optional and last.
  `RetryProfile` gains an optional last argument `$backoffMinMs` too.
- **Messenger**: a `HandlerFailedException` is transient only when every wrapped exception is transient and no other
  handler completed in that attempt (no `HandledStamp`). 3.0.0 looked at the first wrapped exception only, so a
  deadlock in the first handler replayed handlers that had already succeeded, and a deadlock in a later one was not
  retried. Now a dispatch with one failing handler (or only transient failures, nothing completed) is retried; a
  domain error next to a deadlock is neither retried nor mapped to 503 (it stays a 500), and 409 needs every wrapped
  exception to be a conflict. A `DelayedMessageHandlingException` is never retried. When the retries are exhausted
  the `HandlerFailedException` escapes from `retry()` as is; the adapter wraps it in `TransientStorageFailure`.
  Replaying a dispatch is only safe under conditions listed in
  [CONFIGURATION](CONFIGURATION.md#failures-wrapped-by-messenger): check your handlers before relying on it.
- Failure chains are walked at most 64 levels deep (and 256 exceptions); 3.0.0 walked `getPrevious()` without a bound.

## To 3.0.0

From **2.4.0**:

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Target **3.0.0** (from 2.4.x). Major: the behaviour after a failure changes. No configuration change is required.

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle:^3.0
```

What changes, and what to check:

1. **Requirements: DBAL 4 and ORM 3 only.** `doctrine/dbal ^4.0` and `doctrine/orm ^3.0` (2.x allowed DBAL 3.8 / ORM
   2.13, which were never tested in CI). Stay on 2.4.x until you have moved to DBAL 4.
   New requirement: `nowo-tech/transaction-contract ^1.0` (no dependencies beyond PHP). The bundle aliases its
   `TransactionRunnerInterface` to the retrying adapter unless you (or another compiler pass that ran first) defined
   it.
2. **The connection is repaired after every failure.** The levels the failed operation left open are rolled back
   (instead of 2.x's single blind `EntityManager::rollback()`); when that rollback fails, which is what happens after
   a deadlock in a nested transaction, the connection is **closed**. A close reconnects on the next query and
   **loses session state**: variables you `SET` once, temporary tables, and **session-level locks**: MySQL
   `GET_LOCK()`, PostgreSQL advisory locks (`pg_advisory_lock`, Symfony Lock's `PostgreSqlStore` / `DoctrineDbalPostgreSqlStore`
   on the same connection). An ordinary failure (rollback works) keeps all of it. Put connection settings in a DBAL
   middleware or the driver's init command so a reconnect restores them.
3. **Nothing is retried inside your own transaction.** If you call `flush()` / `retry()` while a transaction is open
   (nesting level > 0, > 1 with auto-commit disabled), a transient failure is rethrown after the levels the operation
   opened are rolled back. Move the retry around the outermost transaction:
   `retry(fn () => $em->wrapInTransaction(...))`. After a deadlock inside your transaction the connection may have
   been closed (the server already ended the transaction): your own `rollBack()` / `commit()` then throws
   `NoActiveTransaction`, which hides the deadlock in a `catch { rollBack(); throw; }`. Guard it with
   `isTransactionActive()` or use `finally`.
4. **`rollback_on_deadlock: false` no longer retries on a leftover transaction.** The service leaves the connection
   as it is, as before, but when the failure left a transaction open it rethrows instead of replaying (2.x replayed
   inside ghost savepoints, auto-committing each attempt). Enable the `worker_guard` if you use it.
5. **`retry()` clears the entity manager before each replay.** Entities loaded before the call, or in a failed
   attempt, are detached. Load what you change inside the callable.
6. **The exception that escapes.** When retries are exhausted (or not allowed), a transient failure that only
   Doctrine wrapped is rethrown as the transient exception itself: after a nested deadlock you now get the
   `DeadlockException`, not the ORM's `DriverException` 1305 "SAVEPOINT DOCTRINE_n does not exist" that carried it.
   Your own wrapper exceptions are rethrown unchanged.
7. **Lock wait timeouts are not retried by default** (`retry_lock_wait_timeout: false`); 1205 and PostgreSQL 55P03
   are retried only when you enable it, ideally with a short lock wait timeout and a `max_total_ms` budget (a call
   can take the budget plus one attempt; see [Configuration → Lock wait retries](CONFIGURATION.md#lock-wait-retries)).
   With the worker guard on, entities a service memoised across requests become detached after each request or
   message: re-load them per request.
8. **More failures are transient:** MariaDB 1020 (snapshot isolation conflict, MariaDB ≥ 11.6.2) and PostgreSQL
   55P03 (lock wait). A 1305 lost savepoint is transient **only** through the deadlock it carries; on its own (an
   implicit commit dropped the savepoints) it is rethrown.
9. **Delays are jittered and exponential.** `sleep_ms` keeps working as the backoff base. To keep 2.x's fixed sleep,
   set `jitter: none` and `backoff_max_ms` equal to the base.

**Known issue in ≤ 2.4.0** (fixed by 3.0.0, no security advisory): after a deadlock in a nested transaction, 2.x's
blind rollback failed again with 1305 (a 500 instead of a retry) and left the worker's connection at nesting level 2,
so later requests on that worker ran inside ghost savepoints (their writes committed immediately). The worker guard
of 3.0.0 also repairs connections that other code poisoned.

Opt-in, nothing happens until you enable them:

- `worker_guard.enabled: true` for FrankenPHP worker mode, RoadRunner and other long-running workers;
- `http_mapping.enabled: true` to answer 503 + `Retry-After` / 409 instead of 500.

See [Usage → High-load readiness](USAGE.md#high-load-readiness).

## To 2.4.0

From **2.3.0**: minor, strictly additive, no configuration change. 2.3.0 was tagged by mistake without this code; this release contains the features first announced for 2.3.0.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

- New `Contract\DeadlockRetryInterface`, aliased to `DeadlockRetryService`. Type-hint the interface; a host can re-alias it to its
  own implementation. `getEntityManager()` is not part of the interface (no Doctrine type in the port); keep using the concrete
  service for it, or take the manager from your own registry inside the `retry()` callable.

## To 2.3.0

From **2.2.2**:

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.2.2

From **2.2.1**:

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.2.1

From **2.2.0**:

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.2.0

From **2.1.3** — deadlock rethrown on closed manager without registry; entity_manager option; Symfony floors aligned.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

- Requires Symfony 6.4, 7.4 or 8.x (7.0–7.3 are no longer allowed).
- Without a `ManagerRegistry`, `retry()` now rethrows the original deadlock when it closed the entity manager instead of retrying on the closed manager (which failed with "EntityManager is closed").
- Multi-EM apps can set `entity_manager: <name>`; the default (`null`) keeps using the default entity manager.

## To 2.1.3

From **2.1.2** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

- No breaking changes. No application upgrade steps.

## To 2.1.2

From **2.1.1** — dependency refresh only. No breaking changes. No application upgrade steps.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.1.1

From **2.1.0** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
php bin/console cache:clear
```

No pending upgrade notes.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 2.0.9 to 2.1.0

No configuration changes. Behaviour and public API:

- When a deadlock closes the entity manager during `flush()` (the Doctrine ORM default), `flush()` now resets the manager and rethrows the original `DeadlockException`. Before, the retry failed with `EntityManagerClosed` and the manager stayed closed until a kernel reset. Use `retry()` with a callable that redoes the unit of work to retry ORM writes, and obtain the manager inside it with `getEntityManager()`.
- A closed manager is reset through `ManagerRegistry` also when a non-deadlock exception is rethrown; entities loaded before the failure are detached from the new manager.
- If you instantiate `DeadlockRetryService` manually, pass the registry as the new optional 4th argument to enable recovery.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## From 2.0.8 to 2.0.9

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.0.8

No application upgrade steps.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.0.7

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`). Shipped demos are Symfony 8 only (Symfony 6/7 demo apps removed).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
php bin/console cache:clear
```

## To 2.0.6

Patch release; no configuration or public API changes for application code.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Maintainers / CI:

- Makefiles detect `docker compose` vs `docker-compose` automatically and invoke Compose via a shell helper (WSL-safe).
- Optional monorepo `../.scripts/Makefile*.mk` includes no longer break a standalone clone (e.g. GitHub Actions).

## To 2.0.5

Patch release; no configuration or public API changes for application code.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Maintainers:

- `make demo-smoke` boots the Symfony 8 FrankenPHP demo and asserts HTTP 200 (also `.github/workflows/demo-smoke.yml`).
- PHPUnit / CI fail on **direct** Symfony deprecations (`SYMFONY_DEPRECATIONS_HELPER=max[direct]=0`).
- CI runs a dedicated PHPStan job.

## To 2.0.4

Patch release; no configuration or public API changes for application code.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Maintainers / demo runners:

- Prefer `.env.example` over any local `.env.dev` (do not commit `.env.dev`).
- Demos use **SQLite** only; do not rely on Flex-generated Postgres `DATABASE_URL` / `compose.override.yaml`.
- `make release-check` now fails if unresolved open GitHub PRs remain (`make check-open-prs`).
- Package QA uses `nowo-tech/phpstan-frankenphp` in **require-dev** only (not a consumer dependency).

## To 2.0.3

Patch release; no configuration or public API changes. Demo-only: FrankenPHP mode is now selected with `FRANKENPHP_MODE` in each demo `.env` (default `worker`).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

If you run the bundled demos, copy the new `FRANKENPHP_MODE` keys from `.env.example` into your local `.env` and recreate containers after changing the mode (`docker compose up -d`).

## To 2.0.2

Patch release; no configuration or public API changes.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

Contributors: run `make setup-hooks` once per clone (REQ-GIT-001). See [Contributing](CONTRIBUTING.md) and [GITHUB_CI.md](GITHUB_CI.md).

## To 2.0.1

Patch release; no configuration or public API changes.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 2.0.0

Requires **PHP 8.2 or newer**. No configuration or public API changes beyond the platform requirement.

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

If you must stay on **PHP 8.1**, pin **1.0.1**:

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle:^1.0.1
```

## To 1.0.1

Patch release; no configuration or API changes (supports PHP 8.1).

```bash
composer update nowo-tech/doctrine-deadlock-retry-bundle
```

## To 1.0.0

First stable release. Install with:

```bash
composer require nowo-tech/doctrine-deadlock-retry-bundle
```

Register `Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle` in `config/bundles.php` and add `config/packages/nowo_doctrine_deadlock_retry.yaml` (see [Installation](INSTALLATION.md)).
