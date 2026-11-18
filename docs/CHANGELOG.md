# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[3.2.0] - 2026-11-18](#320---2026-11-18)
- [[3.1.0] - 2026-11-17](#310---2026-11-17)
- [[3.0.0] - 2026-11-15](#300---2026-11-15)
- [[2.4.0] - 2026-11-13](#240---2026-11-13)
- [[2.3.0] - 2026-11-13](#230---2026-11-13)
- [[2.2.2] - 2026-10-28](#222---2026-10-28)
- [[2.2.1] - 2026-10-21](#221---2026-10-21)
- [[2.2.0] - 2026-10-13](#220---2026-10-13)
- [[2.1.3] - 2026-10-13](#213---2026-10-13)
- [[2.1.2] - 2026-10-09](#212---2026-10-09)
- [[2.1.1] - 2026-09-27](#211---2026-09-27)
- [[2.1.0] - 2026-09-24](#210---2026-09-24)
- [[2.0.9] - 2026-08-24](#209---2026-08-24)
- [[2.0.8] - 2026-08-19](#208---2026-08-19)
- [[2.0.7] - 2026-08-18](#207---2026-08-18)
- [[2.0.6] - 2026-07-29](#206---2026-07-29)
- [[2.0.5] - 2026-07-29](#205---2026-07-29)
- [[2.0.4] - 2026-07-27](#204---2026-07-27)
- [[2.0.3] - 2026-07-22](#203---2026-07-22)
- [[2.0.2] - 2026-07-20](#202---2026-07-20)
- [[2.0.1] - 2026-07-09](#201---2026-07-09)
- [[2.0.0] - 2026-06-11](#200---2026-06-11)
- [[1.0.1] - 2026-06-11](#101---2026-06-11)
- [[1.0.0] - 2026-05-20](#100---2026-05-20)

## [Unreleased]

### Changed

- CI: new `deps-floor` job (as in BreadcrumbKit). It checks that the demo lock installs every nowo-tech requirement of the bundle (`.scripts/check-demo-lock.py`), then downgrades nowo-tech/transaction-* to the lowest versions composer.json allows (`composer update 'nowo-tech/transaction-*' --prefer-lowest`) and runs PHPStan and the tests there, so a call to a newer API than the constraint allows fails the pull request.
- Demo: `make update-bundle` runs `composer update nowo-tech/doctrine-deadlock-retry-bundle -w`, so the bundle's new or raised requirements reach the demo lock too.

### Fixed

- Demo: `demo/symfony8/composer.lock` now installs nowo-tech/transaction-doctrine 1.6.0. The locks of 3.0.0 and 3.2.0 did not install nowo-tech/transaction-doctrine, which the bundle already required (^1.5): the demo installs from its lock, so it would fail with class not found on the first lock conflict (`ConflictCode`). The bundle's composer.json constraint was already right; hosts are not affected.

## [3.2.0] - 2026-11-18

### Added

- **`CONFLICT_001` / `CONFLICT_002` codes** (REQ-RUNTIME-003 rule 6) in the `http_mapping` answer, from
  `nowo-tech/transaction-doctrine` `^1.5` (`ConflictCode`): `CONFLICT_001` (nothing committed, with `Retry-After`) and
  `CONFLICT_002` (part may be committed: a `ConcurrencyConflict` flagged committed, or a lost savepoint after a failed
  commit; **no** `Retry-After`). The `problem_json` body gets an additive `code` key; the `exception` format throws
  `Http\ConflictResponseException` / `Http\UnavailableResponseException`, which implement `Http\HasErrorCodeInterface`.
- **`X-Conflict-Code` response header** (`CONFLICT_001` / `CONFLICT_002`, never the domain code) in both formats at 409 and 503.
- **`http_mapping.conflict_status`** (`409` default, or `503`).
- **`docs/CONCURRENCY.md`**: write-path table, codes, host options and residual risks.
- New dependency `nowo-tech/transaction-doctrine: ^1.5`.
- Tests: the exhausted real deadlock (two sessions, MySQL 8.4 and MariaDB 11.4 / 11.8) answers 409 + `CONFLICT_001`;
  conflict-mapping tests for `001`, `002`, the 503 option and the non-conflicts.

### Changed

- **Lock conflicts now answer 409 instead of 503** (set `conflict_status: 503` to keep the old status). See UPGRADING.
- The default `conflict_exceptions` is now `OptimisticLockException` only, and its 409 carries the domain code
  `RESOURCE_CHANGED` (never `CONFLICT_*`).

### Fixed

- A `DelayedMessageHandlingException` (the outer dispatch already committed) and a `TransientStorageFailure` over a non-storage error answer `CONFLICT_002` without `Retry-After`; a lock conflict behind a non-replayable wrapper (completed handler, delayed dispatch) is answered `CONFLICT_002` instead of a 500.
- A `HandlerFailedException` with a completed handler (`HandledStamp`), or wrapping a committed `ConcurrencyConflict`, now answers `CONFLICT_002` without `Retry-After` instead of `CONFLICT_001`.
- A unique-constraint violation is no longer mapped to 409 by default: it is not a lock conflict (it is a real error,
  or the host's own domain answer). Hosts that still list it in `conflict_exceptions` get `RESOURCE_CHANGED`.

[3.2.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v3.2.0

## [3.1.0] - 2026-11-17

### Added

- **Injectable sleeper**: `Sleep\SleeperInterface::sleepMilliseconds(int)` with the default `Sleep\UsleepSleeper`,
  aliased in the container and passed to `DeadlockRetryService` as a new optional last constructor argument. Hosts
  alias the interface to replace how the service waits (a recording sleeper in tests). Chosen over
  `symfony/clock`'s `ClockInterface::sleep()` so the bundle needs no new runtime dependency and the port stays in
  the profile's integer milliseconds; a clock-backed implementation is a few lines.
- **Messenger-wrapped failures**: the classifier, the retry decision, the `TransactionRunnerInterface` adapter and
  `http_mapping` (503 and 409) look inside `HandlerFailedException::getWrappedExceptions()` as well as
  `getPrevious()`, recursively, through `Failure\ThrowableChain` (at most 64 levels and 256 exceptions; only when
  `symfony/messenger` is installed). A `HandlerFailedException` is transient only when **every** wrapped exception is
  transient and its envelope carries **no `HandledStamp`** (no other handler completed in that attempt); its kind is
  the strongest one (deadlock or serialization failure before lock wait timeout, whatever the order). A
  `DelayedMessageHandlingException` is never transient. `http_mapping` answers 409 only when every wrapped exception
  is a conflict. The adapter keeps the `Retry-After` hint of a wrapped `TransientStorageFailure`
  (`TransientFailureClassifier::storageFailure()`).
- **Minimum backoff**: profile option `backoff_min_ms` (default `0`, unchanged behaviour). With full jitter each delay
  is random between it and the exponential bound; it must not exceed `backoff_max_ms`, and `max_total_ms` must not be
  below it (no retry could start). `RetryProfile` gains the
  optional argument `$backoffMinMs`. See [CONFIGURATION](CONFIGURATION.md) for a 3-retry, 20–200 ms full-jitter
  profile on 1213 and 1205.

### Changed

- Failure chains are walked at most 64 levels deep; 3.0.0 followed `getPrevious()` without a bound.

### Fixed

- **Messenger dispatches that partly succeeded are no longer replayed.** Up to 3.0.0 the previous chain of a
  multi-handler `HandlerFailedException` is its first wrapped exception: when a deadlock came first, `retry()` or
  the adapter replayed the whole dispatch, including handlers that had already succeeded (their writes or side
  effects applied twice). Known issue for 3.0.0 and earlier; no security advisory. See
  [CONFIGURATION](CONFIGURATION.md#failures-wrapped-by-messenger) for when a dispatch is safe to replay.

- `rector.php` targets PHP 8.2, the `require.php` floor (REQ-CS-003).
- Demo: `demo/symfony8/composer.lock` locks the bundle at 3.0.0 with its new dependency `nowo-tech/transaction-contract`, so the demo boots again and `make demo-smoke` / the demo smoke workflow pass (REQ-TEST-011).

### Documentation

- `docs/SECURITY.md` updated for 3.0: attack surface, threat model and mitigations, logging table, dependencies, permissions; AI audit row points to the 3.0.0 audit (REQ-SEC-001).
- `docs/PSR.md`: PSR-3 Logger is **Adopted** (REQ-CS-007).
- Spec Kit baseline maps all 18 `src/` files and `spec.md` gains the 3.0 FRs (REQ-SPECKIT-001/003).
- README: plain links in the Documentation lists, TS/JS and Python N/A (REQ-DOCS-002, REQ-TEST-007); `FRANKENPHP-WORKER-AUDIT.md` TOC complete (REQ-DOCS-005).

[3.1.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v3.1.0

## [3.0.0] - 2026-11-15

Target 3.0.0: the connection is repaired (rolled back, closed only when that fails) after any failure, nested calls
and leftover transactions are no longer retried, `retry()` clears the entity manager before a replay, backoff is
jittered, DBAL 4 / ORM 3 are required. See [UPGRADING](UPGRADING.md#to-300).

### Fixed

- **Nested deadlocks on MySQL / MariaDB no longer surface as 1305 or poison the worker's connection.** A deadlock
  (1213) inside a nested transaction drops every savepoint; the ORM's `ROLLBACK TO SAVEPOINT` then fails with 1305,
  and 2.x's blind `EntityManager::rollback()` before the retry failed with 1305 again and escaped. The connection
  stayed at nesting level 2, so the next request ran inside a ghost savepoint (its writes committed immediately).
  After any failure the service now rolls back the levels the operation left open and closes the connection only
  when that rollback fails (`Connection::close()` resets the nesting level). Ordinary failures keep session state
  (variables, `GET_LOCK`, advisory locks). Covered by real MySQL 8.4 / MariaDB 11.4 tests.
- Inside a caller's transaction a transient failure is no longer retried. The old rollback undid the caller's own
  transaction and replayed only the inner operation in auto-commit (PostgreSQL), or failed with 1305 (MySQL).
- `retry()` clears an open entity manager before replaying: a raw DBAL failure left the failed attempt's entities
  scheduled, and the replay inserted them twice (QA-DDRB-M2).
- `rollback_on_deadlock: false` never replays on a connection the failure left in a transaction (QA-DDRB-M3).
- A 1305 lost savepoint is transient only through the deadlock in its chain: after an implicit commit (DDL inside the
  transaction) it was retried and the committed work replayed (QA-DDRB-M1).
- Connections with auto-commit disabled are retried at their implicit transaction level (QA-DDRB-L1).

### Added

- **Detection of transient failures**: DBAL `RetryableException`, MySQL / MariaDB `1213`, `1205`, MariaDB `1020`,
  SQLSTATE `40001` / `40P01` / `55P03` (`Failure\TransientFailureClassifier`, `Failure\TransientFailureKind`).
  Profile option `retry_lock_wait_timeout` (default `false`) and time budget `max_total_ms` (default none).
- **Exponential backoff with full jitter**: profile options `backoff_base_ms` (default 100), `backoff_max_ms` (default
  2000) and `jitter` (`full` | `none`). `sleep_ms` stays as an alias of `backoff_base_ms`.
- **Worker guard** (opt-in, `worker_guard.enabled`): on `kernel.request` (main request, priority 2048) and
  `kernel.terminate` (priority -2048) a connection left in a transaction is rolled back (one level) or closed
  (deeper, or a failed rollback); closed entity managers are reset; open ones are cleared on terminate
  (`clear_managers`). With `messenger` (default on) the same runs after every Messenger message: on a failed message
  the connections are repaired at priority 512, before `SendFailedMessageForRetryListener` writes the retry envelope,
  and managers are cleared at -2048. Only connections and managers the container already initialised are touched.
  One `warning` per repair, without SQL.
- **HTTP mapping** (opt-in, `http_mapping.enabled`): a `kernel.exception` listener (priority -64) maps transient
  failures (and `Nowo\TransactionContract\TransientStorageFailure`) to 503 with `Retry-After`
  (`retry_after_seconds`, or the exception's hint), and `conflict_exceptions` (default `OptimisticLockException`,
  `UniqueConstraintViolationException`, not when they wrap a driver error such as "Commit failed") to 409. Scoped by
  `paths` (segment boundaries) / `routes` prefixes or global; `format: exception` keeps the host's own rendering,
  `format: problem_json` answers RFC 9457 directly; the client never sees SQL.
- **`nowo-tech/transaction-contract` adapter**: `Transaction\DeadlockRetryTransactionRunner` implements
  `TransactionRunnerInterface` (service id `nowo_doctrine_deadlock_retry.transaction_runner`): `wrapInTransaction()`
  through `retry()`, transient failures rethrown as `TransientStorageFailure` with `retry_after_seconds`; option
  `transaction_runner.profile`. The interface is aliased to it only when nothing defines it (compiler pass, priority
  10, so it wins over bundles' one-attempt defaults that follow the recipe; a host's own alias always wins). A
  `TransientStorageFailure` from a nested runner is classified by the storage error it carries. New requirement
  `nowo-tech/transaction-contract ^1.0`.
- `Connection\ConnectionRepairer`, a public service for bundles that clean up after a failed unit of work.
- Optional PSR-3 logger on `DeadlockRetryService`: retries at `info`, repairs at `notice`, never SQL.

### Changed

- Requires `doctrine/dbal ^4.0` and `doctrine/orm ^3.0` (DBAL 3 / ORM 2 were never tested).
- When the service gives up on a transient failure that only Doctrine wrapped, it rethrows the transient exception
  itself (the `DeadlockException`, not the ORM's 1305 `DriverException`).
- `retry()` always makes at least one attempt (a negative `max_retries` on a hand-built `RetryProfile` used to throw).
- The internal "Deadlock retry loop ended without result" `RuntimeException` is gone.

### Documentation

- USAGE: "High-load readiness" (what goes wrong, worker guard, HTTP mapping, standard Symfony and DDD host examples,
  migration guide for bundles with their own reset helpers); "Behaviour on transient failures" replaces "Behaviour on
  deadlock". CONFIGURATION: new profile, `worker_guard` and `http_mapping` options.

### Documentation

- USAGE: "Using it from a DDD / hexagonal application" with a standard Symfony host section and a DDD / hexagonal
  host section (own `TransactionRunner` port, adapter on `DeadlockRetryInterface`, events after commit), and a
  statement that the bundle never flushes or commits on its own (REQ-ARCH-001).

### Tests

- Real-database suite (`tests/Database`, `make test-db`, CI job "Real databases"): two sessions cause a real deadlock
  in a nested flush on MySQL 8.4 and MariaDB 11.4 (clean retry, nesting level 0, next request commits), a real lock
  wait timeout (retried; exhausted → 503 + `Retry-After`), the worker guard repairing a poisoned connection and
  clearing pending changes, a PostgreSQL 16 serialization failure (40001) and lock timeout (55P03), a MariaDB 11.8
  snapshot conflict (1020), the Messenger repair before the retry envelope, the `max_total_ms` bound (budget plus
  one attempt), and the QA audit regressions (implicit commit not retried, dirty manager cleared, no
  replay on a leftover transaction, auto-commit off retried, session locks kept after a domain failure).
- DDD host case: plain-PHP aggregate, host `TransactionRunner` port and an adapter on the container's
  `DeadlockRetryInterface` alias; a deadlock closes the manager, the unit of work is replayed on the reset manager and
  the domain event is published once, after commit.

[3.0.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v3.0.0

## [2.4.0] - 2026-11-13

### Added

- `Contract\DeadlockRetryInterface` (`flush()`, `retry()`, `getProfileNames()`, `getDefaultProfileName()`), implemented by `DeadlockRetryService` and aliased in the container (REQ-ARCH-001 rule 1). It carries no Doctrine type; `getEntityManager()` stays on the concrete service.

[2.4.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.4.0

## [2.3.0] - 2026-11-13

### Changed

- Demo uses the UiKit base style (REQ-DEMO-014)

### Tests

- Kept-alive container test (1,200 calls, two tenants, memory bound) and refreshed `docs/FRANKENPHP-WORKER-AUDIT.md` (REQ-RUNTIME-002).

[2.3.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.3.0

## [2.2.2] - 2026-10-28

DoctrineDeadlockRetry: only driver-level deadlocks (SQLSTATE 40001 / MySQL 1213) are retried. See [UPGRADING](UPGRADING.md#to-222).

### Fixed

- **Deadlock detection is limited to driver errors.** Code `1213` matched on any exception in the chain, so an
  application exception using that code replayed the operation. Only `DeadlockException`, DBAL driver exceptions and
  `PDOException` (SQLSTATE `40001` or MySQL `1213`) are retried now.

### Documentation

- USAGE: call `retry()` / `flush()` outside your own transactions; a deadlock rollback undoes the outer transaction.

[2.2.2]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.2.2

## [2.2.1] - 2026-10-21

Wave-2 REQ compliance: demo DebugBundle and update-bundle in demo release-check, fixed igor target. See [UPGRADING](UPGRADING.md#to-221).

### Changed

- Makefile `igor` target fixed (it called an undefined `$(COMPOSE)` and failed in `release-check`; REQ-CS-008 / REQ-MAKE-002).
- Demo: `symfony/debug-bundle` installed and registered for dev/test (REQ-DEMO-001); `make -C demo release-check` runs `update-bundle` in every demo before the tests (new `update-bundle-all`, REQ-DEMO-007).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001); Rector capped at 2 parallel workers.
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order (PSR under Additional documentation) and canonical FrankenPHP claim line, CONTRIBUTING Cursor Agent settings and post-release-commit trailer check, canonical bug-report template, doc TOCs, demo `DATABASE_URL` comment.

[2.2.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.2.1

## [2.2.0] - 2026-10-13

### Added

- `entity_manager` option (default `null` = default entity manager): name of the Doctrine entity manager the service flushes and resets, for multi-EM apps. Wires `doctrine.orm.<name>_entity_manager` and passes the name to `ManagerRegistry`.

### Fixed

- `retry()` without a `ManagerRegistry` kept retrying after a deadlock closed the entity manager, so the next attempt failed with "EntityManager is closed" and the original deadlock was hidden. The original `DeadlockException` is now rethrown when the closed manager cannot be reset.

### Changed

- Symfony constraints normalised to `^6.4 || ^7.4 || ^8.0` (7.0–7.3 were allowed but never tested: the `7.0` CI cell resolved to 7.4 and is dropped). README / INSTALLATION / badge and the CI header comment now match the matrix (6.4, 7.4, 8.0, 8.1).

[2.2.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.2.0

## [2.1.3] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #52).

[2.1.3]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.1.3

## [2.1.2] - 2026-10-09

### Dependencies

- Dependabot: `doctrine/dbal` 4.5.0, `doctrine/orm` 3.7.3, dev `igor-php/igor-php` 0.10, `nowo-tech/phpstan-frankenphp` 1.2.1, `phpstan/phpstan-phpunit`.
- Lock refresh: `doctrine/orm` 3.7.4; dev PHPStan 2.3.1 (+ phpunit/symfony extensions 2.1), PHPUnit 10.5.66, Rector 2.7.0.
- Demo (Symfony 8): `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, Twig 3.30.0, HotReloadBundle 1.5.5, TwigInspectorBundle 1.1.7.

[2.1.2]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.1.2

## [2.1.1] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[2.1.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.1.1

## [2.1.0] - 2026-09-24

### Fixed

- **FrankenPHP worker mode / long-running processes:** a failed flush (deadlock or other error) no longer leaves the entity manager closed for later attempts and requests. `DeadlockRetryService` resets a closed manager through `Doctrine\Persistence\ManagerRegistry` (wired to `doctrine` when available) before retrying or rethrowing. See `docs/FRANKENPHP-WORKER-AUDIT.md`.
- `flush()` rethrows the original deadlock when the manager was closed by it, instead of failing on the retry with `EntityManagerClosed` (the unit of work cannot be replayed on a new manager).

### Added

- `DeadlockRetryService::getEntityManager()`: returns the current (open) entity manager, for use inside `retry()` callables.
- Optional constructor arguments `?ManagerRegistry $managerRegistry = null` and `?string $entityManagerName = null`.

### Changed

- **QA:** PHPStan FrankenPHP rulesets upgraded to `ruleset-classic.neon` + `ruleset-worker-strict.neon` (worker-strict includes worker).

### Documentation

- FrankenPHP worker audit (`docs/FRANKENPHP-WORKER-AUDIT.md`), USAGE / UPGRADING for closed-manager recovery, Spec Kit baseline updated for FR-SVC-003/004 and FR-DI-001.

## [2.0.9] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

## [2.0.8] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [2.0.7] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).
- **Demos:** Symfony 8 only; Symfony 6/7 demo apps removed.

[2.0.7]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.7

## [2.0.6] - 2026-07-29

### Changed

- Makefiles prefer **`docker compose`** (V2) and fall back to `docker-compose` (V1) (REQ-MAKE-010).
- Monorepo `update-deps` Makefile includes are optional (`-include`) so standalone CI checkouts do not fail (REQ-MAKE-009).
- Compose invocations use `$(call dc,...)` so Make routes through the shell (avoids WSL Docker Desktop `Permission denied` on direct `execve`).

### Documentation

- UPGRADING notes for 2.0.6.

## [2.0.5] - 2026-07-29

### Added

- **`make demo-smoke`** + `.github/workflows/demo-smoke.yml`: boot `demo/symfony8` and assert HTTP 200 (REQ-TEST-011).
- **REQ-SF-005**: `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` in `phpunit.xml.dist` and CI.
- **CI**: PHPStan job in `.github/workflows/ci.yml` (REQ-CS-006).
- `check-open-prs.sh` resolves `origin` to `--repo owner/name` for `gh` over SSH remotes.

### Changed

- PHPStan: explicit `ignoreErrors: []` (REQ-CS-006).

### Documentation

- README `## Documentation` canonical order (REQ-DOCS-002); GITHUB_CI under Additional.
- Table of contents on long docs (REQ-DOCS-005).
- `docs/SECURITY.md`: OBS logging policy; AI audit Pass (good) (REQ-SEC-004); full 12.4.1 checklist.
- DEMO-FRANKENPHP: document `make demo-smoke`.
- UPGRADING notes for 2.0.5.

## [2.0.4] - 2026-07-27

### Fixed

- Symfony 8 demo: WebProfiler routing resources use `.php` instead of removed `.xml` files.
- Demos pin `DATABASE_URL` to SQLite in Compose (avoid Flex postgres override without `pdo_pgsql`), drop unused Postgres service, and remove `compose.override.yaml` port publish (REQ-DEMO-006).

### Added

- FrankenPHP Friendly Worker Mode banner in README and `docs/images/frankenphp-friendly.png` (REQ-DOCS-017), gated on PHPStan FrankenPHP rules.
- Dev dependency `nowo-tech/phpstan-frankenphp` with classic + worker rulesets in `phpstan.neon.dist` (REQ-CS-005).
- `make down-dev` and `make check-open-prs` (wired into `release-check`) for REQ-MAKE-007 / REQ-REL-003.

### Changed

- `Configuration` and `NowoDoctrineDeadlockRetryExtension` are `final`.
- Demo `.env.example` files document each variable; demo `.gitignore` ignores `/.pnpm-store`.
- Removed committed demo `.env.dev`; demos rely on `.env.example` only (REQ-DEMO-003 / REQ-ENV-001).
- Dev and runtime dependency updates in `composer.lock` (phpstan, php-cs-fixer, rector, doctrine/dbal).

### Documentation

- UPGRADING notes for 2.0.4.
- RELEASE checklist mentions `check-open-prs` before tagging.

## [2.0.3] - 2026-07-22

### Added

- Demo `FRANKENPHP_MODE` (`classic` \| `worker`, default **worker**) via `.env.example`, Compose, and `docker/entrypoint.sh` (REQ-DEMO-010).

### Changed

- Demo Dockerfiles copy `docker/entrypoint.sh` instead of an inline entrypoint script.

### Documentation

- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md): document `FRANKENPHP_MODE` switching (recreate container, no rebuild).
- Demo README: FrankenPHP mode section.

## [2.0.2] - 2026-07-20

### Added

- Code of Conduct ([CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md)).
- Git hygiene tooling (REQ-GIT-001): `.githooks/commit-msg`, `make setup-hooks`, `make check-no-cursor-coauthor`, and CI job `git-hygiene`.
- [GITHUB_CI.md](GITHUB_CI.md) documenting CI requirements for commit history.

### Changed

- Demo dependency locks and Makefile paths for update-deps targets.
- Dev dependency updates in `composer.lock`.

### Documentation

- Contributing: Code of Conduct and git hooks setup.
- Release: re-run `make check-no-cursor-coauthor` after tagging, before push.
- README links to GITHUB_CI and Code of Conduct.

## [2.0.1] - 2026-07-09

### Changed

- Internal exception-chain detection in `DeadlockRetryService` (no public API change).
- Dev dependency updates in `composer.lock`.

### Added

- CodeRabbit configuration and GitHub Actions workflow.
- GitHub Spec Kit documentation and spec-driven development tooling.

### Documentation

- Expanded release security checklist in [SECURITY.md](SECURITY.md).
- README badges (GitHub stars, coverage).
- [SPEC-KIT.md](SPEC-KIT.md) guide.

## [2.0.0] - 2026-06-11

### Changed

- Minimum PHP version is **8.2** (`composer.json`, CI, and documentation).
- `RetryProfile` uses `readonly class` again (PHP 8.2+).
- CI matrix runs PHP **8.2–8.5** (PHP 8.1 removed).

### Removed

- PHP **8.1** support. Remain on **1.0.1** if you cannot upgrade PHP.

## [1.0.1] - 2026-06-11

### Fixed

- `RetryProfile` PHP 8.1 compatibility: promoted `readonly` properties instead of `readonly class`.

## [1.0.0] - 2026-05-20

### Added

- `DeadlockRetryService` with `flush(?string $profile)` and `retry(callable $operation, ?string $profile)`.
- Configuration key `nowo_doctrine_deadlock_retry` with named profiles (`max_retries`, `sleep_ms`, `rollback_on_deadlock`).
- Default profile when no profile name is passed.
- Detection of `DeadlockException`, SQLSTATE `40001`, and MySQL error `1213` in the exception chain.
- Symfony Flex recipe at `.symfony/recipe/nowo-tech/doctrine-deadlock-retry-bundle/1.0/`.
- FrankenPHP demos for Symfony 7.4 and Symfony 8.1.
- CI matrix for PHP 8.1–8.5 and Symfony 6.4, 7.0, 7.4, 8.0, and 8.1.

### Documentation

- Installation, configuration, usage, security, upgrading, and FrankenPHP demo guides.

[2.1.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.1.0
[2.0.9]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.9
[2.0.8]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.8
[2.0.7]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.7
[2.0.6]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.6
[2.0.5]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.5
[2.0.4]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.4
[2.0.3]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.3
[2.0.2]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.2
[2.0.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.1
[2.0.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v2.0.0
[1.0.1]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v1.0.1
[1.0.0]: https://github.com/nowo-tech/DoctrineDeadlockRetryBundle/releases/tag/v1.0.0
