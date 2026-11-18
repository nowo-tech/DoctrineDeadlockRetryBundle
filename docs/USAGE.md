# Usage

Inject `Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface` (autowired; aliased to `Service\DeadlockRetryService`, which still works as a type hint). Re-alias the interface to replace the implementation. `getEntityManager()` exists only on the concrete service.

## Table of contents

- [flush()](#flush)
- [retry()](#retry)
- [Behaviour on transient failures](#behaviour-on-transient-failures)
- [Important](#important)
- [High-load readiness](#high-load-readiness)
  - [What goes wrong without it](#what-goes-wrong-without-it)
  - [Worker guard](#worker-guard)
  - [HTTP mapping: 409 + `CONFLICT_*` instead of 500](#http-mapping-409--conflict_-instead-of-500)
  - [Standard Symfony host example](#standard-symfony-host-example)
  - [DDD / hexagonal host example](#ddd--hexagonal-host-example)
  - [Migration guide for bundles with their own reset helpers](#migration-guide-for-bundles-with-their-own-reset-helpers)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - [Known limitations](#known-limitations)

## flush()

Wraps `EntityManager::flush()` with retries on transient failures (deadlocks, lock wait timeouts, serialization failures).

The flush is only retried while the entity manager stays open. Doctrine ORM closes the manager on every failed flush, so after a deadlock the pending changes are gone: the bundle resets the closed manager (through `ManagerRegistry`) and rethrows the deadlock. For ORM writes prefer `retry()`.

```php
$this->deadlockRetry->flush();           // default profile
$this->deadlockRetry->flush('import');   // named profile
```

## retry()

Runs any callable with the same retry policy:

```php
$result = $this->deadlockRetry->retry(
    fn () => $this->doTransactionalWork(),
    'import',
);
```

The callable should redo the whole unit of work (load entities, modify, flush) on each attempt and obtain the manager per attempt, because a closed manager is reset between attempts:

```php
$this->deadlockRetry->retry(function (): void {
    $em    = $this->deadlockRetry->getEntityManager();
    $order = $em->find(Order::class, $id);
    $order->markPaid();
    $em->flush();
});
```

## Behaviour on transient failures

1. **Detection.** A failure is transient when its `getPrevious()` chain contains:
   - a DBAL `RetryableException` (`DeadlockException`, `LockWaitTimeoutException`, …);
   - a driver exception (`Doctrine\DBAL\Driver\Exception`, `PDOException`) with MySQL / MariaDB code `1213`
     (deadlock), `1205` (lock wait timeout) or `1020` (MariaDB ≥ 11.6 snapshot isolation conflict), or SQLSTATE
     `40001` / `40P01` / `55P03` (PostgreSQL serialization failure, deadlock, `lock_timeout` / `NOWAIT`).

   Other exceptions are never retried, whatever their code. A MySQL / MariaDB `1305` "SAVEPOINT DOCTRINE_n does not
   exist" is never transient **on its own**: savepoints also vanish on an implicit commit (DDL inside the
   transaction), and replaying would repeat committed work. After a nested deadlock the ORM's 1305 carries the
   deadlock in its chain, and that deadlock is what counts (see [below](#what-goes-wrong-without-it)). A connection
   lost during a commit is not transient either: its outcome is unknown. Lock wait timeouts are retried only with
   `retry_lock_wait_timeout: true` (default `false`).
2. **Connection repair** (after any failure, when `rollback_on_deadlock` is on). The levels the operation left open
   are **rolled back** down to the level the call started at; the connection is **closed** only when that rollback
   fails (after a nested deadlock the server has dropped the savepoints, so `ROLLBACK TO SAVEPOINT` fails with 1305;
   `Connection::close()` resets the nesting level). An ordinary failure therefore keeps session state: variables,
   MySQL `GET_LOCK()` and PostgreSQL advisory locks survive.
3. **Entity manager.** A closed entity manager is reset with `ManagerRegistry::resetManager()` (`flush()` then
   rethrows, see above). Before `retry()` replays, an **open** manager is **cleared**, so entities the failed
   attempt persisted or changed are not flushed again. Without a `ManagerRegistry` a closed manager cannot be reset,
   so `retry()` rethrows the original failure instead of retrying on a closed manager.
4. **When nothing is retried.**
   - Inside a caller's transaction (nesting level > 0 when the call starts; > 1 with auto-commit disabled): the
     database rolled back (MySQL / MariaDB) or aborted (PostgreSQL) the whole transaction, so only its owner can
     replay it. Put the retry around the outermost transaction.
   - With `rollback_on_deadlock: false`, when the failure left a transaction open: the service did not repair the
     connection, and a replay would run inside ghost savepoints and auto-commit.
   - When `max_total_ms` would be exceeded by the next delay, or after `max_retries`. The budget gates the **start**
     of each attempt: an attempt that starts inside it runs to its end, so a call can take up to `max_total_ms` plus
     one attempt (for a lock wait, plus one `innodb_lock_wait_timeout`).
5. **Auto-commit disabled.** The connection's implicit transaction (level 1) is the outermost level: it is rolled
   back before each replay (closed if that rollback fails). Call `retry()` right after a commit.
6. **Backoff.** Before retry *n* the service waits a random time between 0 and
   `min(backoff_max_ms, backoff_base_ms × 2^(n-1))` (full jitter), so concurrent workers do not retry in lockstep.
   `jitter: none` waits exactly the bound.
7. **What escapes.** When the service gives up on a transient failure that only Doctrine wrapped (the ORM's 1305 after
   a nested deadlock, `OptimisticLockException("Commit failed")`), it rethrows the transient exception itself
   (`DeadlockException`, `LockWaitTimeoutException`, …), so `catch (RetryableException)` works. An exception of your
   own that wraps it is rethrown as is. With [HTTP mapping](#http-mapping-409--conflict_-instead-of-500) enabled it
   becomes a 409 with `Retry-After` and `CONFLICT_001` (503 with `conflict_status: 503`).

Retries are logged at `info` (kind, profile, attempt, delay) and repairs at `notice` (nesting level, action). Logs
never contain SQL, parameters or driver messages.

## Important

Each attempt must redo the full unit of work: load, modify, flush. The service clears an open entity manager before
a replay and resets a closed one, so do not keep entities loaded before `retry()` (or in a previous attempt) and use
them afterwards as managed objects. Prefer `wrapInTransaction()` inside the callable: the ORM then also closes the
manager on failure.

Call `retry()` / `flush()` **outside** any transaction you opened yourself and open the transaction inside the
callable (for example `retry(fn () => $em->wrapInTransaction(...))`). Inside your transaction nothing is retried
(see step 4 above), and after a nested deadlock your transaction may already be gone: the service closed the
connection, so your own `rollBack()` / `commit()` throws `NoActiveTransaction`. Guard it with
`isTransactionActive()`, or let the failure reach the outermost `retry()`.

## High-load readiness

Three pieces, all built for long-running workers (FrankenPHP worker mode with `kernel.reset` disabled, RoadRunner,
Messenger consumers). The services keep no state between requests.

### What goes wrong without it

In DBAL 4 a nested `Connection::rollBack()` runs `ROLLBACK TO SAVEPOINT` and only then lowers the nesting level. On
MySQL / MariaDB a deadlock (1213) rolls back the whole transaction and drops every savepoint, so:

1. the ORM's `UnitOfWork::commit()` (nested flush) and `EntityManager::wrapInTransaction()` both call `rollBack()`
   from a `finally`; each fails with **1305** `SAVEPOINT DOCTRINE_2 does not exist`, and the caller sees the 1305;
2. the nesting level **stays at 2**. The next request of that worker opens `SAVEPOINT DOCTRINE_3` instead of a
   transaction: its writes are committed immediately and its rollback fails. Only `Connection::close()` resets it.

The bundle's real-database tests reproduce this with two sessions on MySQL 8.4 and MariaDB 11.4.

### Worker guard

```yaml
nowo_doctrine_deadlock_retry:
    worker_guard:
        enabled: true
        connections: []             # empty = every Doctrine connection
        reset_closed_managers: true
        clear_managers: true        # clear open entity managers on kernel.terminate and after each message
        messenger: true             # also run after each Messenger message (when symfony/messenger is installed)
```

On every main request (`kernel.request`, priority 2048, before the router and the firewall) and on
`kernel.terminate` (priority -2048), every guarded connection left with a transaction is repaired: one level open is
rolled back, deeper (or a failed rollback) is closed. Connections with auto-commit disabled are expected at level 1.
Closed entity managers are reset. On `kernel.terminate`, open entity managers are cleared (`clear_managers`), so
changes a failed unit of work left pending, and the identity map, never reach the next request. Entities a service
memoised across requests therefore become **detached** after each request or message (as with kernel reset on):
re-load them per request; `kernel.terminate` listeners below -2048 must not rely on managed entities. Each repair logs one
`warning` with the connection or manager name, the nesting level, the action and the phase, never SQL.

With `messenger: true` the same guard runs after every `WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`, so a
message never inherits the previous one's connection state. On a failed message the connections are repaired at
priority 512, before Messenger's `SendFailedMessageForRetryListener` (100) writes the retry envelope (a transaction
the failed handler left open would otherwise swallow it); entity managers are cleared at -2048 on both events.

The guard only looks at connections and managers the container has already initialised: it never opens a
connection or initialises a lazy manager a request did not use. That makes it cheap in classic PHP-FPM too, but it is
built for long-running workers. Outside HTTP and Messenger (a custom loop) call
`Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard::repair('loop', true)` yourself after each unit.

### HTTP mapping: 409 + `CONFLICT_*` instead of 500

```yaml
nowo_doctrine_deadlock_retry:
    retry_after_seconds: 1          # Retry-After of a retry-safe conflict (CONFLICT_001)
    http_mapping:
        enabled: true
        paths: ['/api']             # path prefixes; with routes empty too: every request
        routes: []                  # route name prefixes, e.g. ['api_']
        conflict_status: 409        # or 503, for hosts that keep the old answer
        conflict_exceptions:        # domain conflicts mapped to 409 RESOURCE_CHANGED (default shown)
            - Doctrine\ORM\OptimisticLockException
        format: exception           # or problem_json
```

A `kernel.exception` listener (priority -64: after Symfony's `logKernelException`, so the failure is always logged,
and before rendering at -128) maps a transient failure that reaches the kernel, retried or not, to
**409 Conflict** with `Retry-After` and `CONFLICT_001` (nothing committed; `CONFLICT_002` without `Retry-After` when part
was committed; `conflict_status: 503` keeps the old status), and a configured domain conflict to **409** with
`RESOURCE_CHANGED`. Unique-constraint violations and lost connections are **not** lock conflicts: they are not mapped
unless you list them in `conflict_exceptions` yourself (then they answer `RESOURCE_CHANGED`, never `CONFLICT_*`).
See [CONCURRENCY](CONCURRENCY.md). Path prefixes match on segment
boundaries (`/api` matches `/api` and `/api/orders`, not `/apiary`); with `paths` and `routes` both empty the
mapping applies to **every** request. A conflict class that wraps a driver error, such as the ORM's
`OptimisticLockException("Commit failed")` around a failed `COMMIT` whose outcome is unknown, is not mapped. An exception that already is an
`HttpExceptionInterface` is left alone. `Nowo\TransactionContract\TransientStorageFailure` (the framework-free
exception of `nowo-tech/transaction-contract`) is mapped like a lock conflict too, with its own `retryAfterSeconds()` hint.

- `format: exception` (default) replaces the exception with a `ConflictHttpException` subclass
  (`Http\ConflictResponseException`) or, with `conflict_status: 503`, a `ServiceUnavailableHttpException` subclass
  (`Http\UnavailableResponseException`), both implementing `Http\HasErrorCodeInterface::errorCode()` (the original as `previous`). Your own error rendering produces the response, so a host
  that answers `application/problem+json` (API Platform, a custom renderer) keeps doing so.
- `format: problem_json` answers directly with an RFC 9457 `application/problem+json` body
  (`type`, `title`, `status`, `detail`).

Either way the client gets a fixed, generic `detail`: SQL, parameters and driver messages stay in the previous
exception, for your logs.

### Standard Symfony host example

```yaml
# config/packages/nowo_doctrine_deadlock_retry.yaml
nowo_doctrine_deadlock_retry:
    profiles:
        default:
            max_retries: 3
            backoff_base_ms: 50
            backoff_max_ms: 1000
    worker_guard:
        enabled: '%env(bool:APP_WORKER_MODE)%'
    http_mapping:
        enabled: true
        paths: ['/api']
```

```php
final class OrderController
{
    public function __construct(private readonly DeadlockRetryService $retry)
    {
    }

    #[Route('/api/orders/{id}/pay', methods: ['POST'])]
    public function pay(int $id): JsonResponse
    {
        $order = $this->retry->retry(function () use ($id): Order {
            return $this->retry->getEntityManager()->wrapInTransaction(static function (EntityManagerInterface $em) use ($id): Order {
                $order = $em->find(Order::class, $id, LockMode::PESSIMISTIC_WRITE);
                $order->markPaid();

                return $order;
            });
        });

        return new JsonResponse(['id' => $order->getId()]);   // a deadlock after 4 attempts is a 409, not a 500
    }
}
```

### DDD / hexagonal host example

The application layer depends on [`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract):
framework-free `TransactionRunnerInterface::run(callable): mixed` and `TransientStorageFailure` with
`retryAfterSeconds()`. It contains no Doctrine or Symfony type.

```php
use Nowo\TransactionContract\TransactionRunnerInterface;

final readonly class PayOrderHandler
{
    public function __construct(private TransactionRunnerInterface $transactions, private OrderRepository $orders)
    {
    }

    public function __invoke(PayOrder $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $this->orders->get($command->orderId)->pay($command->amount);   // loaded per attempt
        });
        // side effects (mail, Messenger dispatch, domain events) only here, after run() returned
    }
}
```

The bundle ships the retrying Doctrine adapter, `Transaction\DeadlockRetryTransactionRunner` (service id
`nowo_doctrine_deadlock_retry.transaction_runner`). It runs the operation in `wrapInTransaction()` (flush + commit)
through `retry()` with `transaction_runner.profile` (the default profile when null). When retries are exhausted, or
the call runs inside a caller's transaction, a transient failure becomes
`TransientStorageFailure::fromPrevious($failure, retry_after_seconds)`; `http_mapping` answers it with 409 +
`Retry-After`. A `TransientStorageFailure` thrown by a nested runner is classified by the storage error it carries, so
the outermost runner replays the whole unit of work (a lock wait only with `retry_lock_wait_timeout`).

`TransactionRunnerInterface` is aliased to the adapter by a compiler pass **only when nothing defines it**: alias it
yourself to choose another runner. Bundles that follow the [recipe](#migration-guide-for-bundles-with-their-own-reset-helpers)
alias their one-attempt default only when absent, at the default pass priority, so this adapter (priority 10) wins.
Inside a caller's transaction `wrapInTransaction()` closes, and the bundle resets, the shared entity manager in the
middle of the caller's transaction: the caller must not keep using entities it loaded before.

```yaml
nowo_doctrine_deadlock_retry:
    retry_after_seconds: 2
    transaction_runner:
        profile: null
```

### Migration guide for bundles with their own reset helpers

Many bundles carry a copy of "if the entity manager is closed, reset it" and some call `rollBack()` again in a
`catch`. The adoption recipe:

**R1 — depend on the port; your services bind to the interface.** Require `nowo-tech/transaction-contract` for the
application layer; do not require this bundle. Your services type-hint and are wired to
**`TransactionRunnerInterface`** (the interface id), never to your default's id: otherwise they bypass the host's
retrying adapter. Register your default runner (for example `nowo-tech/transaction-doctrine`'s
`DoctrineTransactionRunner`) under **your own service id** (`acme_foo.transaction_runner`), and alias
`TransactionRunnerInterface` to it **only when the interface is not already defined**, in a compiler pass at the
default priority (never in your extension's `load()`). A host's alias, or this bundle's adapter (pass priority 10),
then wins; without them your default is used:

```php
final class TransactionRunnerAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(TransactionRunnerInterface::class)) {
            $container->setAlias(TransactionRunnerInterface::class, 'acme_foo.transaction_runner');
        }
    }
}
```

**R2 — what the default runner guarantees after a failure.** The levels it opened are rolled back to the entry
level, and the connection is closed only if that rollback fails. The entity manager has **no pending changes**
(cleared, or closed and reset). Transient failures surface as `TransientStorageFailure`.

**R3 — callers' rules.**
- Never `catch (\Throwable $e) { $connection->rollBack(); throw $e; }`: after a nested deadlock the level is already
  0 (the connection was closed), so that `rollBack()` throws `NoActiveTransaction` and hides the real failure. Guard
  with `isTransactionActive()`, use `finally`, or let the runner handle it.
- Leave no pending changes behind a failure, and keep side effects (mail, Messenger dispatch, events) until `run()`
  or `retry()` has returned: the operation can run several times.
- Retry only at the outermost level.

**R4 — Messenger.** `worker_guard.messenger` repairs connections and clears managers after every message. A host
without this bundle needs DoctrineBundle's `doctrine_ping_connection` / `doctrine_clear_entity_manager` middlewares
(or its own listener calling the same repair).

**R5 — DDD hosts** point at `nowo-tech/transaction-contract` (see
[DDD / hexagonal host example](#ddd--hexagonal-host-example)), not at a port of their own.

| Before (in the bundle) | After |
|------------------------|-------|
| `if (!$em->isOpen()) { $registry->resetManager(); }` before each write | Keep it only where the bundle must work without a guard; hosts that enable `worker_guard` get it on every request and message. |
| `try { … } catch (\Throwable $e) { $conn->rollBack(); throw $e; }` | Remove the extra `rollBack()` (R3). If you must clean up, use `ConnectionRepairer::afterFailure($connection, $entryLevel)`. |
| `wrapInTransaction()` + flush inside, no retry | Run the whole closure through the runner (or `DeadlockRetryService::retry()`), outermost only. |
| Catch `DeadlockException` only | Classify with `TransientFailureClassifier` (also 1205, 1020, 40001, 40P01, 55P03). |
| Let the 500 through | Throw `TransientStorageFailure`, or let the host's `http_mapping` turn it into 409 + `Retry-After` + `CONFLICT_001`. |

Add a test that a failed unit of work leaves the connection at its entry nesting level, the manager with no pending
changes, and session state (a session variable) intact.

## Using it from a DDD / hexagonal application

DoctrineDeadlockRetryBundle is an **infrastructure** helper: one service that replays a Doctrine unit of work on
deadlock. It has no entities, mapped superclasses, traits, listeners or events, works with any mapping driver
(attributes, XML, PHP) and never touches your domain classes. It **never flushes or commits on its own**: it flushes
only when you call `flush()`, and inside `retry()` it only runs your callable. Configuration comes from the bundle
config tree (`nowo_doctrine_deadlock_retry`); there is no provider port to implement and no InstanceSettings
dependency.

Both paths below are supported; the standard one is the zero-config default.

### Standard Symfony host

Inject `DeadlockRetryInterface` (or the concrete `DeadlockRetryService` when you need `getEntityManager()`) in your
services and controllers and use [`flush()`](#flush) and [`retry()`](#retry) as shown above. Nothing else to do.

### DDD / hexagonal host

Keep the bundle out of the domain and application layers. Use the shared, framework-free port of
[`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract) (`TransactionRunnerInterface`)
rather than a port of your own, and implement it in infrastructure with `DeadlockRetryInterface`, which carries no
Doctrine or Symfony type (REQ-ARCH-001 rules 1 and 3). The example below shows the shape with a host-declared port;
replace `TransactionRunner` by `Nowo\TransactionContract\TransactionRunnerInterface`:

```php
// Application layer: your port, framework-free
interface TransactionRunner
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}

// Infrastructure adapter
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface;

final readonly class DeadlockRetryTransactionRunner implements TransactionRunner
{
    public function __construct(
        private DeadlockRetryInterface $retry,
        private ManagerRegistry $registry,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->retry->retry(function () use ($work): mixed {
            /** @var EntityManagerInterface $em fetched per attempt: a closed manager is reset between attempts */
            $em = $this->registry->getManager();

            return $em->wrapInTransaction(static fn (): mixed => $work());
        });
    }
}
```

```yaml
# config/services.yaml
services:
    App\Application\TransactionRunner: '@App\Infrastructure\Persistence\DeadlockRetryTransactionRunner'
```

An application service then depends only on `TransactionRunner`, and its tests use an in-memory runner. The adapter
itself can be tested with a double of `DeadlockRetryInterface`.

Rules that matter for the adapter:

- The callable must redo the **whole** unit of work (load aggregates, apply the command, save): a deadlock closes the
  entity manager, which the bundle resets between attempts. Repositories must obtain the manager per attempt
  (`ManagerRegistry::getManager()`), not keep a stale reference to a closed one.
- Call it **outside** any transaction you opened yourself; open the transaction inside the callable (see
  [Important](#important)). Inside your transaction the bundle repairs the connection but does not retry.
- Release domain events recorded by aggregates only after `run()` returns (after commit), so a replayed attempt
  does not publish them twice.
- When every attempt fails, the original Doctrine exception is rethrown; translate it in the adapter if your
  application layer expects its own exception type.
- To use another entity manager than the configured `entity_manager`, pass its name to `getManager()` in the adapter
  and set the same name in the bundle config, so the bundle resets that manager.

The test suite covers this setup with a plain-PHP aggregate, a `TransactionRunner` port and the adapter above
(`tests/Integration/DddHost/`), next to the standard-host tests.

### Known limitations

- `getEntityManager()` is only on the concrete `DeadlockRetryService` (it returns a Doctrine type, so it stays out of
  the port).
- One entity manager per configuration (`entity_manager`); the worker guard covers every manager.
- The `TransactionRunnerInterface` adapter works on the configured `entity_manager` only.
