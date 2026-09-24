# Usage

Inject `Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService` (autowired).

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

1. Detects `Doctrine\DBAL\Exception\DeadlockException` (and SQLSTATE `40001` / code `1213` in the chain).
2. If `rollback_on_deadlock` is enabled and a transaction is active, calls `EntityManager::rollback()`.
3. If the entity manager is closed, resets it with `ManagerRegistry::resetManager()` (`flush()` then rethrows, see above).
4. Sleeps for `sleep_ms` from the profile.
5. Retries until `max_retries` is exhausted, then rethrows the last exception.

A closed manager is also reset when a non-deadlock exception is rethrown, so a long-running worker (FrankenPHP, Messenger) does not keep serving a closed manager.

## Important

A rollback clears the persistence context for the current transaction. Re-apply changes (persist/merge) or repeat the full unit of work before the next flush attempt.
