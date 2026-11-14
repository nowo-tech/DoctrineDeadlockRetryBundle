# Usage

Inject `Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface` (autowired; aliased to `Service\DeadlockRetryService`, which still works as a type hint). Re-alias the interface to replace the implementation. `getEntityManager()` exists only on the concrete service.

## Table of contents

- [flush()](#flush)
- [retry()](#retry)
- [Behaviour on deadlock](#behaviour-on-deadlock)
- [Important](#important)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
  - [Standard Symfony host](#standard-symfony-host)
  - [DDD / hexagonal host](#ddd--hexagonal-host)
  - [Known limitations](#known-limitations)

## flush()

Wraps `EntityManager::flush()` with deadlock retries.

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

## Behaviour on deadlock

1. Detects `Doctrine\DBAL\Exception\DeadlockException`, and SQLSTATE `40001` / MySQL code `1213` on a driver exception (`Doctrine\DBAL\Driver\Exception`, `PDOException`) anywhere in the `getPrevious()` chain. Other exceptions are never retried, whatever their code.
2. If `rollback_on_deadlock` is enabled and a transaction is active, calls `EntityManager::rollback()`.
3. If the entity manager is closed, resets it with `ManagerRegistry::resetManager()` (`flush()` then rethrows, see above). Without a `ManagerRegistry` (no DoctrineBundle `doctrine` service, manual instantiation) a closed manager cannot be reset, so `retry()` also rethrows the original deadlock instead of retrying on a closed manager.
4. Sleeps for `sleep_ms` from the profile.
5. Retries until `max_retries` is exhausted, then rethrows the last exception.

A closed manager is also reset when a non-deadlock exception is rethrown, so a long-running worker (FrankenPHP, Messenger) does not keep serving a closed manager.

## Important

A rollback clears the persistence context for the current transaction. Re-apply changes (persist/merge) or repeat the full unit of work before the next flush attempt.

Call `retry()` / `flush()` **outside** any transaction you opened yourself. A deadlock aborts the whole InnoDB transaction,
and with `rollback_on_deadlock` the rollback also undoes the work of an outer transaction (or savepoint nesting): the
retry then replays only the inner operation. Put the whole transaction inside the callable instead (for example
`retry(fn () => $em->wrapInTransaction(...))`).

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
App\Application\TransactionRunner: '@App\Infrastructure\Persistence\DeadlockRetryTransactionRunner'
```

An application service then depends only on `TransactionRunner`, and its tests use an in-memory runner. The adapter
itself can be tested with a double of `DeadlockRetryInterface`.

Rules that matter for the adapter:

- The callable must redo the **whole** unit of work (load aggregates, apply the command, save): a deadlock closes the
  entity manager, which the bundle resets between attempts. Repositories must obtain the manager per attempt
  (`ManagerRegistry::getManager()`), not keep a stale reference to a closed one.
- Call it **outside** any transaction you opened yourself; open the transaction inside the callable (see
  [Important](#important)).
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
- No event or log entry per retry; no jitter or backoff (fixed `sleep_ms`).
- One entity manager per configuration (`entity_manager`).
