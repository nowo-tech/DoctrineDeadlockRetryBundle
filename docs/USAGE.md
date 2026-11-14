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
  - [HTTP mapping: 503 and 409 instead of 500](#http-mapping-503-and-409-instead-of-500)
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
     (deadlock) or `1205` (lock wait timeout), or SQLSTATE `40001` / `40P01`;
   - MySQL / MariaDB `1305` on a savepoint Doctrine created (`SAVEPOINT DOCTRINE_2 does not exist`): what a deadlock
     in a nested transaction turns into (see [below](#what-goes-wrong-without-it)).

   Other exceptions are never retried, whatever their code. Lock wait timeouts are retried unless the profile sets
   `retry_lock_wait_timeout: false`.
2. **Connection repair** (after any failure, when `rollback_on_deadlock` is on). Called at nesting level 0 and a
   transaction is still active: the connection is **closed** (`Connection::close()` resets the nesting level and the
   server discards the transaction); it is never rolled back, because the rollback is what fails after a deadlock.
   Called inside a transaction of yours (level > 0): the levels the operation left open are rolled back, and the
   connection is closed if that rollback fails.
3. **Entity manager.** A closed entity manager is reset with `ManagerRegistry::resetManager()` (`flush()` then
   rethrows, see above). Without a `ManagerRegistry` a closed manager cannot be reset, so `retry()` rethrows the
   original failure instead of retrying on a closed manager.
4. **Nested calls are not retried.** When the call starts inside a transaction (nesting level > 0), the database
   has rolled back (MySQL / MariaDB) or aborted (PostgreSQL) the whole transaction, so only its owner can replay it:
   the failure is rethrown after the repair. Put the retry around the outermost transaction.
5. **Backoff.** Before retry *n* the service waits a random time between 0 and
   `min(backoff_max_ms, backoff_base_ms × 2^(n-1))` (full jitter), so concurrent workers do not retry in lockstep.
   `jitter: none` waits exactly the bound.
6. **Exhaustion.** After `max_retries` the last failure is rethrown as is. With [HTTP mapping](#http-mapping-503-and-409-instead-of-500)
   enabled it becomes a 503 with `Retry-After`.

Retries are logged at `info` (kind, profile, attempt, delay) and repairs at `notice` (nesting level, action). Logs
never contain SQL, parameters or driver messages.

## Important

A failed attempt clears the persistence context. Repeat the full unit of work (load, modify, flush) in each attempt.

Call `retry()` / `flush()` **outside** any transaction you opened yourself and open the transaction inside the
callable (for example `retry(fn () => $em->wrapInTransaction(...))`). Inside your transaction nothing is retried
(see step 4 above).

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
```

On every main request (`kernel.request`, priority 2048, before the router and the firewall) and on
`kernel.terminate` (priority -2048), every guarded connection left with a transaction is repaired: one level open is
rolled back, deeper (or a failed rollback) is closed. Connections with auto-commit disabled are expected at level 1.
Closed entity managers of every manager in the registry are reset. Each repair logs one `warning` with the connection
or manager name, the nesting level, the action and the phase, never SQL.

The guard fetches every connection and manager from the registry, so enable it where it pays: long-running workers.
Outside HTTP (a Messenger worker, a custom loop) call `Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard::repair('messenger')`
yourself after each message.

### HTTP mapping: 503 and 409 instead of 500

```yaml
nowo_doctrine_deadlock_retry:
    retry_after_seconds: 1          # Retry-After of the 503
    http_mapping:
        enabled: true
        paths: ['/api']             # path prefixes; with routes empty too: every request
        routes: []                  # route name prefixes, e.g. ['api_']
        conflict_exceptions:        # mapped to 409 (default shown)
            - Doctrine\ORM\OptimisticLockException
            - Doctrine\DBAL\Exception\UniqueConstraintViolationException
        format: exception           # or problem_json
```

A `kernel.exception` listener (priority 0) maps a transient failure that reaches the kernel, retried or not, to
**503 Service Unavailable** with `Retry-After`, and a conflict to **409 Conflict**. An exception that already is an
`HttpExceptionInterface` is left alone. `Nowo\TransactionContract\TransientStorageFailure` (the framework-free
exception of `nowo-tech/transaction-contract`) is mapped to 503 too, with its own `retryAfterSeconds()` hint.

- `format: exception` (default) replaces the exception with a Symfony `ServiceUnavailableHttpException` /
  `ConflictHttpException` (the original as `previous`). Your own error rendering produces the response, so a host
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

        return new JsonResponse(['id' => $order->getId()]);   // a deadlock after 4 attempts is a 503, not a 500
    }
}
```

### DDD / hexagonal host example

The application layer depends on `nowo-tech/transaction-contract` (framework-free:
`TransactionRunnerInterface::run(callable): mixed` and `TransientStorageFailure` with `retryAfterSeconds()`). The
bundle aliases `TransactionRunnerInterface` to its adapter, `Transaction\DeadlockRetryTransactionRunner`:

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
    }
}
```

The adapter runs the operation in `wrapInTransaction()` (flush + commit) through `retry()` with the
`transaction_runner.profile` (default profile when null). When retries are exhausted, or the call runs inside a
caller's transaction, a transient failure becomes `TransientStorageFailure::fromPrevious($failure,
retry_after_seconds)`; `http_mapping` answers it with 503 + `Retry-After`. A `TransientStorageFailure` thrown by a
nested runner is itself transient, so the outermost runner replays the whole unit of work.

```yaml
nowo_doctrine_deadlock_retry:
    retry_after_seconds: 2
    transaction_runner:
        profile: null
```

`TransientFailureClassifier` and `ConnectionRepairer` are public services; inject them.

### Migration guide for bundles with their own reset helpers

Many bundles carry a copy of "if the entity manager is closed, reset it" and some call `rollBack()` again in a
`catch`. With this bundle:

| Before (in the bundle) | After |
|------------------------|-------|
| `if (!$em->isOpen()) { $registry->resetManager(); }` before each write | Keep it only where the bundle must work without this bundle; hosts that enable `worker_guard` get it on every request. |
| `try { … } catch (\Throwable $e) { $conn->rollBack(); throw $e; }` | Remove the extra `rollBack()`: after a nested deadlock it throws 1305 and hides the deadlock. If you must clean up, use `ConnectionRepairer::afterFailure($connection, $entryLevel)`, or close the connection when a transaction is still active. |
| `wrapInTransaction()` + flush inside, no retry | Run the whole closure through `DeadlockRetryService::retry()` or a `TransactionRunnerInterface` adapter, outermost only. |
| Catch `DeadlockException` only | Classify with `TransientFailureClassifier` (also 1205, 40001, 1305 after a deadlock). |
| Let the 500 through | Throw `TransientStorageFailure` from the application layer, or let the host's `http_mapping` turn it into 503 + `Retry-After`. |
| Side effects (mail, Messenger dispatch) inside the retried closure | Move them after `retry()` / `run()` returns: the closure can run several times. |

Steps for a bundle:

1. Require `nowo-tech/transaction-contract` (framework-free) for the application layer; type-hint
   `TransactionRunnerInterface`. Do not require this bundle.
2. Ship a safe default runner for hosts that wire nothing: one attempt, transaction opened and committed by the
   runner, connection closed when a transaction is still active after a failure, closed entity manager reset,
   transient failures rethrown as `TransientStorageFailure`.
3. Let the host alias `TransactionRunnerInterface` to the retrying adapter.
4. Delete the copy-pasted reset helper once its callers go through the runner, and document `worker_guard` for
   worker-mode hosts.
5. Add a test that a failed unit of work leaves the connection at nesting level 0 and the manager open.

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

Keep the bundle out of the domain and application layers. Declare your own transaction port in the application
layer and implement it in infrastructure with `DeadlockRetryInterface`, which carries no Doctrine or Symfony type
(REQ-ARCH-001 rules 1 and 3):

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
