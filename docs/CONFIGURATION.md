# Configuration

Root key: `nowo_doctrine_deadlock_retry`.

| Option | Default | Description |
|--------|---------|-------------|
| `default_profile` | `default` | Profile used when `flush()` / `retry()` receive no profile name |
| `profiles` | see below | Map of named retry policies |

### Profile options

| Option | Default | Description |
|--------|---------|-------------|
| `max_retries` | `3` | Retries after the first failed attempt |
| `sleep_ms` | `100` | Milliseconds between attempts |
| `rollback_on_deadlock` | `true` | Roll back active ORM transaction before retrying |

### Example

```yaml
nowo_doctrine_deadlock_retry:
    default_profile: default
    profiles:
        default:
            max_retries: 3
            sleep_ms: 100
        import:
            max_retries: 10
            sleep_ms: 500
            rollback_on_deadlock: true
        read_only_retry:
            max_retries: 2
            sleep_ms: 50
            rollback_on_deadlock: false
```

`default_profile` must match a key under `profiles`.
