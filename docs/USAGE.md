# Usage

Inject `Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService` (autowired).

## flush()

Wraps `EntityManager::flush()` with deadlock retries.

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

## Behaviour on deadlock

1. Detects `Doctrine\DBAL\Exception\DeadlockException` (and SQLSTATE `40001` / code `1213` in the chain).
2. If `rollback_on_deadlock` is enabled and a transaction is active, calls `EntityManager::rollback()`.
3. Sleeps for `sleep_ms` from the profile.
4. Retries until `max_retries` is exhausted, then rethrows the last exception.

## Important

A rollback clears the persistence context for the current transaction. Re-apply changes (persist/merge) or repeat the full unit of work before the next flush attempt.
