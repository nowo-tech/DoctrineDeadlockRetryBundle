# Configuration

Root key: `nowo_doctrine_deadlock_retry`.

| Option | Default | Description |
|--------|---------|-------------|
| `default_profile` | `default` | Profile used when `flush()` / `retry()` receive no profile name |
| `entity_manager` | `null` | Entity manager name (key of `doctrine.orm.entity_managers`) flushed and reset by the service; `null` = default entity manager |
| `profiles` | see below | Map of named retry policies |
| `retry_after_seconds` | `1` | `Retry-After` of a 503 from `http_mapping`, and hint of the adapter's `TransientStorageFailure` |
| `transaction_runner.profile` | `null` | Profile of the `TransactionRunnerInterface` adapter; `null` = `default_profile` |
| `worker_guard` | disabled | Repairs leftover transactions and closed entity managers on `kernel.request` / `kernel.terminate` (see below) |
| `http_mapping` | disabled | Maps transient failures to 503 + `Retry-After` and conflicts to 409 (see below) |

### Profile options

| Option | Default | Description |
|--------|---------|-------------|
| `max_retries` | `3` | Retries after the first failed attempt |
| `backoff_base_ms` | `100` | Backoff base: the delay before retry *n* is at most `min(backoff_max_ms, base × 2^(n-1))` |
| `sleep_ms` | `null` | Alias of `backoff_base_ms` (2.x name); setting both with different values is an error |
| `backoff_max_ms` | `2000` | Upper bound of one delay (raised to the base if lower) |
| `backoff_min_ms` | `0` | Lower bound of one delay. Must not exceed `backoff_max_ms` (or the base, when the base is larger). A bound below it is raised to it. `0` keeps the 3.0 behaviour |
| `jitter` | `full` | `full`: random delay between `backoff_min_ms` and the bound; `none`: exactly the bound (never below `backoff_min_ms`) |
| `retry_lock_wait_timeout` | `false` | Also retry lock wait timeouts (MySQL / MariaDB 1205, PostgreSQL 55P03). Each attempt can wait the full `innodb_lock_wait_timeout` (50 s by default) / `lock_timeout`: lower it first (see below) |
| `max_total_ms` | `null` | Time budget of one `flush()` / `retry()` call: no retry starts once the elapsed time plus the next delay would exceed it. It gates the start of an attempt, so the worst case is the budget **plus one attempt** (with lock-wait retries, plus one lock wait timeout). `null` = no budget |
| `rollback_on_deadlock` | `true` | Repair the connection after a failure: roll back the levels the operation left open, close only if that rollback fails. `false`: leave the connection as it is, and never retry while the failure left a transaction open |

#### Lock wait retries

Enable `retry_lock_wait_timeout` only together with a short lock wait timeout, otherwise one long-held row lock can
keep a worker busy for `(max_retries + 1) × 50 s`. Set it per connection with a DBAL middleware (or the driver's init
command), 2–5 s for web traffic, and give web profiles a `max_total_ms` (size it knowing a call can take the budget
plus one lock wait):

```yaml
# config/packages/doctrine.yaml (MySQL / MariaDB)
doctrine:
    dbal:
        options:
            1002: 'SET SESSION innodb_lock_wait_timeout = 3'   # PDO::MYSQL_ATTR_INIT_COMMAND
```

Messenger profiles, where waiting is cheap, can keep longer timeouts.

### Worker guard options (`worker_guard`)

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Register the `kernel.request` / `kernel.terminate` subscriber |
| `connections` | `[]` | Connection names to guard; empty = every Doctrine connection |
| `reset_closed_managers` | `true` | Also reset closed entity managers (only managers already initialised) |
| `clear_managers` | `true` | Clear open entity managers on `kernel.terminate` and after each Messenger message (no pending changes or identity map reach the next one) |
| `messenger` | `true` | Also run the guard after each `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` (when `symfony/messenger` is installed): on a failure, repair at priority 512 (before the retry envelope is sent), clear at -2048 |

### HTTP mapping options (`http_mapping`)

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Register the `kernel.exception` listener |
| `paths` | `[]` | Path prefixes handled, on segment boundaries (`/api` matches `/api` and `/api/x`, not `/apiary`). With `routes` empty too, **every request** is handled |
| `routes` | `[]` | Route name prefixes handled |
| `conflict_exceptions` | `OptimisticLockException`, `UniqueConstraintViolationException` | Classes or interfaces mapped to 409 (searched along the previous chain and inside Messenger's `HandlerFailedException`). A match that wraps a driver error (the ORM's `OptimisticLockException("Commit failed")`, outcome unknown) is not mapped |
| `format` | `exception` | `exception`: hand a Symfony HttpException to your error rendering; `problem_json`: answer `application/problem+json` directly |

### Example

```yaml
nowo_doctrine_deadlock_retry:
    default_profile: default
    profiles:
        default:
            max_retries: 3
            backoff_base_ms: 100
            backoff_max_ms: 2000
        import:
            max_retries: 10
            backoff_base_ms: 500
            backoff_max_ms: 5000
            retry_lock_wait_timeout: true   # with a short innodb_lock_wait_timeout, see above
            max_total_ms: 30000
        legacy_fixed_sleep:
            max_retries: 2
            sleep_ms: 50          # alias of backoff_base_ms
            backoff_max_ms: 50    # with jitter none: a fixed 50 ms, as in 2.x
            jitter: none
    worker_guard:
        enabled: true
    http_mapping:
        enabled: true
        paths: ['/api']
```

`default_profile` must match a key under `profiles`.

### Example: a write unit on `TransactionRunnerInterface`

Three retries on deadlocks (1213) and lock wait timeouts (1205), each delay random between 20 and 200 ms:

```yaml
nowo_doctrine_deadlock_retry:
    default_profile: write_unit             # required when write_unit is the only profile
    profiles:
        write_unit:
            max_retries: 3                  # up to 4 attempts
            backoff_base_ms: 200            # bound = min(200, 200 × 2^(n-1)) = 200 for every retry
            backoff_max_ms: 200
            backoff_min_ms: 20
            jitter: full                    # every delay uniform in [20, 200] ms
            retry_lock_wait_timeout: true   # 1205 too (1213 is always retried); keep innodb_lock_wait_timeout short
    transaction_runner:
        profile: write_unit                 # TransactionRunnerInterface → DeadlockRetryTransactionRunner
```

For a growing window instead, set `backoff_base_ms: 50`: the bounds become 50, 100 and 200 ms, so the delays are
random in [20, 50], [20, 100] and [20, 200] ms.

### Failures wrapped by Messenger

The transient classifier, the retry decision, the `TransactionRunnerInterface` adapter and `http_mapping` look for a
transient failure along `getPrevious()` **and** inside the exceptions a Messenger `HandlerFailedException` wraps
(`getWrappedExceptions()`), recursively (at most 64 levels deep and 256 exceptions; without `symfony/messenger` only
`getPrevious()` is walked):

- A `HandlerFailedException` is transient only when **every** wrapped exception is transient **and** its envelope
  carries no `HandledStamp` (no other handler completed in that attempt). Its kind is the strongest one: deadlock or
  serialization failure, then a plain retryable failure, then lock wait timeout, whatever the handler order.
- A `DelayedMessageHandlingException` (handlers that ran after the outer dispatch completed) is never transient.
- A `TransientStorageFailure` without a previous error is retryable; one over a recognised storage error is classified
  by that error; one over anything else (a cache or HTTP error, after which the work may already be committed) is not
  retried. `http_mapping` answers 503 for any `TransientStorageFailure` in the chain, with its `Retry-After` hint.
- `http_mapping` answers 409 for a `HandlerFailedException` only when every wrapped exception is a conflict; a domain
  error next to a deadlock or a conflict stays a 500.

Even then, a dispatch is safe to replay only when the whole dispatch runs in **one transaction** (inside
`TransactionRunnerInterface::run()`), **every handler writes through the shared, transaction-bound entity manager or
connection**, and **side effects** (mail, HTTP calls, events to other systems) are deferred until after the commit. A handler that writes through raw DBAL on another connection, or calls another system, is
replayed with its effect already applied.

Mind the **ghost transaction**: after a deadlock the database has already rolled the transaction back, while DBAL
still counts it as open until the bundle repairs the connection. A handler that runs after the deadlocked one, in the
same dispatch, and writes through raw DBAL (or through another connection) is executed **outside any transaction and
auto-committed**; such a dispatch is never replayed (its `HandledStamp` stops it), but that write stays even though
the rest of the unit of work was rolled back. When a host replays a message itself, it must dispatch the original
envelope again, not the failed one.

### Sleeper (`SleeperInterface`)

`DeadlockRetryService` waits between attempts through
`Nowo\DoctrineDeadlockRetryBundle\Sleep\SleeperInterface::sleepMilliseconds(int)`, aliased to `UsleepSleeper`
(`usleep()`). Alias the interface to your own service to replace it, for instance a recording sleeper in tests:

```yaml
# config/services_test.yaml
services:
    Nowo\DoctrineDeadlockRetryBundle\Sleep\SleeperInterface: '@App\Tests\RecordingSleeper'
```

A host on `symfony/clock` can bridge it in a few lines (`$this->clock->sleep($milliseconds / 1000)`). Keep the
implementation stateless: the service is shared by every request of a long-running worker.

### Multiple entity managers

With several entity managers, point the service at the one whose writes you retry:

```yaml
nowo_doctrine_deadlock_retry:
    entity_manager: orders   # uses doctrine.orm.orders_entity_manager and resets "orders" through ManagerRegistry
```
