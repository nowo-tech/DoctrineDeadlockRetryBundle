# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/doctrine-deadlock-retry-bundle` (`symfony-bundle`) |
| Audited revision | `v2.1.0` (closed-manager recovery + PHPStan worker-strict) |
| Audit date | 2026-09-24 |
| Method | Manual review of every file under `src/` (service, value object, exception, DI extension, configuration, `services.yaml`) plus the Doctrine ORM / DoctrineBundle code it relies on |
| **Verdict** | ✅ **Viable under scenario B** — the service is stateless and resets a closed EntityManager through `ManagerRegistry` before retrying or rethrowing, so a failed flush does not leave later requests with a closed manager |
| Remediation (2026-09-23 / 2026-09-24) | W-01 resolved (`ManagerRegistry` reset, `flush()` rethrows when the unit of work cannot be replayed, `getEntityManager()` for `retry()` callables); W-02 accepted. Regression tests in `tests/Unit/Service/DeadlockRetryServiceTest.php`. PHPStan `ruleset-classic` + `ruleset-worker-strict` clean. |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `DeadlockRetryService` only has `readonly` properties; `RetryProfile` is `final readonly` |
| Static properties / `static` locals | ✅ | Only the static factory `UnknownRetryProfileException::forName()`; no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ | Nothing to reset in the bundle itself; a closed EntityManager is reset by the bundle (W-01) and still by DoctrineBundle's `kernel.reset` under scenario A |
| Request / user / locale captured in services | ✅ | None |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; profiles are compiled into container services |
| Doctrine / EntityManager | ✅ Resolved | Closed manager reset via `ManagerRegistry::resetManager()`; the bundle never calls `clear()` on the application's manager (W-01) |
| Output, headers, `exit`, shutdown functions | ✅ | None |
| Resources (files, sockets, cURL) held open | ✅ | None; the DBAL connection is owned by Doctrine |
| Memory growth across requests | ✅ | No caches or accumulating arrays; `$lastError` is a local variable |
| Blocking I/O and timeouts | ⚠️ Low | `usleep()` between attempts, bounded by configuration (W-02) |
| Third-party static state | ✅ | Only Doctrine DBAL/ORM exceptions and Symfony DI/Config at compile time |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker-strict.neon` in `phpstan.neon.dist` (worker-strict includes worker) |

A worker demo exists: `demo/symfony8/docker/frankenphp/Caddyfile` (and `demo/symfony8/docker/Caddyfile`) declare a `worker` block (line 15).

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService` | yes (private, autowirable) | none (`readonly` EntityManager, optional `ManagerRegistry` and manager name, profile map, default name) | ✅ | ✅ |
| `nowo_doctrine_deadlock_retry.profile.<name>` (`Config\RetryProfile`) | yes (private) | none (`final readonly`) | ✅ | ✅ |

When a `ManagerRegistry` is available (the `doctrine` service, wired as an optional reference), the service obtains the manager from the registry on each attempt instead of relying on the injected reference. The injected `EntityManagerInterface` is the container's default entity manager service. When DoctrineBundle resets a closed manager it resets that lazy service in place, so the reference held by `DeadlockRetryService` stays valid after a scenario A reset.

## Findings

### W-01 — A deadlock in `flush()` closes the EntityManager; retries and later requests hit a closed manager (Medium)

- **Where:** `src/Service/DeadlockRetryService.php:38-43` (`flush()` wraps `$this->entityManager->flush()` in `retry()`), `src/Service/DeadlockRetryService.php:55-78` (retry loop), `src/Service/DeadlockRetryService.php:110-121` (`prepareForRetry()` only rolls back the connection transaction). In Doctrine ORM 3.7.1 a failed commit always calls `$this->em->close()` (`vendor/doctrine/orm/src/UnitOfWork.php:470-479`), and `EntityManager::flush()` throws `EntityManagerClosed` when closed (`vendor/doctrine/orm/src/EntityManager.php:267-271`, `550-555`).
- **Worker impact:** after the first deadlock the EntityManager is closed. The second attempt throws `EntityManagerClosed`, which is not recognised as a deadlock by `isDeadlock()` (lines 132-163), so it is rethrown: the `flush()` retry cannot succeed with ORM. The closed state belongs to a shared service. Under **A**, DoctrineBundle's `doctrine` registry is tagged `kernel.reset` (`vendor/doctrine/doctrine-bundle/config/dbal.php:90`) and `Registry::reset()` resets closed managers (`vendor/doctrine/doctrine-bundle/src/Registry.php:71-111`), so the next request gets a fresh manager. Under **B**, every later request served by that worker fails with "EntityManager is closed" until the worker restarts. No data leaks between users.
- **Status:** Resolved — `src/Service/DeadlockRetryService.php` takes an optional `ManagerRegistry` (wired to `@?doctrine` in `src/Resources/config/services.yaml`) and an optional manager name. After a deadlock it rolls back (if configured), then resets the manager with `resetManager()` when it is closed. `retry(callable)` continues with the fresh manager (the callable obtains it with the new `getEntityManager()`); `flush()` rethrows the original deadlock when the manager was closed, because the unit of work cannot be replayed on a new manager (a retry would be a silent no-op). A closed manager is also reset before rethrowing a non-deadlock exception. Tests: `testFlushResetsClosedManagerAndRethrowsDeadlock`, `testRetryResetsClosedManagerBeforeNextAttempt`, `testClosedManagerDoesNotLeakIntoNextRequestWithoutKernelReset`, `testFlushWithoutRegistryRethrowsDeadlockWhenManagerIsClosed`. The bundle does not call `clear()` on the application's manager: clearing the identity map between requests remains the application's responsibility under scenario B. Without a registry (manual instantiation) a closed manager still cannot be recovered.
- **Original recommendation:** keep `services_resetter` enabled. In the bundle, inject `ManagerRegistry` and, when the manager is closed after a deadlock, call `resetManager()` before retrying; document that `flush()` retries require re-applying the changes on the new manager, and prefer `retry(callable)` where the callable rebuilds its work (load entities, modify, flush) on each attempt. Integrators should not hold entities loaded before the failure across the retry.

### W-02 — Retry backoff blocks the worker thread with `usleep()` (Low)

- **Where:** `src/Service/DeadlockRetryService.php:123-130`, driven by `sleep_ms` and `max_retries` (`src/DependencyInjection/Configuration.php:39-48`, defaults 3 retries × 100 ms).
- **Worker impact:** each retry pins one FrankenPHP thread for `sleep_ms`. The wait is bounded and configurable (default worst case 300 ms of sleep plus the query time); there is no jitter, so concurrent requests that deadlocked together retry in lockstep. No state is kept.
- **Status:** Accepted — the wait is bounded and configurable per profile and no state is kept; jitter is a possible future improvement.
- **Recommendation:** keep `sleep_ms` × `max_retries` small for HTTP profiles and use a separate, longer profile for CLI / Messenger work. Adding random jitter would reduce repeated collisions.

No other findings. The profile map is built at compile time and never modified at runtime.

## Usage recommendations in worker mode

- A closed EntityManager is reset by the bundle; `services_resetter` (scenario A) is still recommended for the rest of the application.
- Prefer `retry(fn () => …)` with a callable that redoes the whole unit of work and calls `getEntityManager()` over `flush()` for ORM writes.
- Do not capture entities or the EntityManager state in your own shared services around a retry.
- Use short HTTP profiles (for example `max_retries: 2`, `sleep_ms: 50`) to limit thread blocking.
- Under scenario B, clearing the identity map between requests (`EntityManager::clear()`) remains the application's responsibility.

## Re-audit triggers

Re-run this audit when a change adds: mutable properties to `DeadlockRetryService`, runtime-mutable profiles, retry statistics or logging buffers, event listeners (e.g. an automatic `onFlush` retry), or changes how `ManagerRegistry` / closed-manager recovery works.
