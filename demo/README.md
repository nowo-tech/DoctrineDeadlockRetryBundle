# Doctrine Deadlock Retry Bundle – Demos

Demos for Composer package [nowo-tech/doctrine-deadlock-retry-bundle](https://packagist.org/packages/nowo-tech/doctrine-deadlock-retry-bundle) ([source on GitHub](https://github.com/nowo-tech/DoctrineDeadlockRetryBundle)) on Symfony 7 and 8.

## Demos

| Demo        | Symfony | Port (default in `.env.example`) |
|------------|---------|----------------------------------|
| symfony7   | 7.0     | 8017                             |
| symfony8   | 8.0     | 8018                             |

## Quick start (from bundle root)

```bash
# Start Symfony 8 demo
make -C demo up-symfony8

# Start Symfony 7 demo
make -C demo up-symfony7
```

Then open `http://localhost:<PORT>` (e.g. http://localhost:8018 for Symfony 8, or the `PORT` in the demo’s `.env`).

## Path repository

Each demo mounts the bundle root at `/var/doctrine-deadlock-retry-bundle` in the container. The demo’s `composer.json` uses a path repository pointing there, so you must run `make up-*` (or `docker-compose up`) from the **bundle repository root** so that `../..` resolves to the bundle.

## Commands (from bundle root)

- `make -C demo up-symfony7` / `make -C demo up-symfony8` – start a demo
- `make -C demo down DEMO=symfony8` – stop (use `DEMO=symfony7` or `symfony8`)
- `make -C demo update-bundle DEMO=symfony8` – update the bundle from path and clear cache
- `make -C demo test DEMO=symfony8` – run that demo’s tests
- `make -C demo verify-all` – start both demos and check HTTP 200
- `make -C demo release-verify` – used by root `make release-check`: up → healthcheck → down per demo

From a demo directory (e.g. `demo/symfony8`):

- `make up` / `make down` / `make install` / `make test` / `make update-bundle` / `make shell`

## Stack

Each demo includes:

- Symfony Framework, Twig, Web Profiler, Doctrine ORM (SQLite)
- **nowo-tech/doctrine-deadlock-retry-bundle** (from path)
- **nowo-tech/twig-inspector-bundle** (dev/test)
- FrankenPHP (Caddyfile :80; **`FRANKENPHP_MODE=worker`** by default; set `classic` for per-request PHP)

## FrankenPHP mode

In each demo’s `.env` / `.env.example`:

```env
FRANKENPHP_MODE=worker
```

Use `classic` for hot-reload without workers. After changing the value, recreate the container (`docker compose up -d` / `make up`) — a plain restart does not reload env. See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).

The home page persists a `DemoNote` entity using `DeadlockRetryService::flush()` so you can verify the bundle wiring without simulating a real deadlock.
