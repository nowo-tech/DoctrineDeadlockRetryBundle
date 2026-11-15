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
| `jitter` | `full` | `full`: random delay between 0 and the bound; `none`: exactly the bound |
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
| `conflict_exceptions` | `OptimisticLockException`, `UniqueConstraintViolationException` | Classes or interfaces mapped to 409 (searched along the previous chain). A match that wraps a driver error (the ORM's `OptimisticLockException("Commit failed")`, outcome unknown) is not mapped |
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

### Multiple entity managers

With several entity managers, point the service at the one whose writes you retry:

```yaml
nowo_doctrine_deadlock_retry:
    entity_manager: orders   # uses doctrine.orm.orders_entity_manager and resets "orders" through ManagerRegistry
```
