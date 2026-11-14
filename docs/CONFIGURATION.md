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
| `retry_lock_wait_timeout` | `true` | Also retry lock wait timeouts (1205); each attempt may wait `innodb_lock_wait_timeout` |
| `rollback_on_deadlock` | `true` | Repair the connection after a failure: close it when a transaction is left active at level 0, roll back inside your transaction (close if that fails). `false` leaves the connection as it is (not recommended) |

### Worker guard options (`worker_guard`)

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Register the `kernel.request` / `kernel.terminate` subscriber |
| `connections` | `[]` | Connection names to guard; empty = every Doctrine connection |
| `reset_closed_managers` | `true` | Also reset closed entity managers of every manager in the registry |

### HTTP mapping options (`http_mapping`)

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `false` | Register the `kernel.exception` listener |
| `paths` | `[]` | Path prefixes handled; with `routes` empty too, every request |
| `routes` | `[]` | Route name prefixes handled |
| `conflict_exceptions` | `OptimisticLockException`, `UniqueConstraintViolationException` | Classes or interfaces mapped to 409 (searched along the previous chain) |
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
            retry_lock_wait_timeout: false
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
