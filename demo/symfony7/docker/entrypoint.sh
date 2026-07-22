#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
# Set via .env / Compose only — not baked into the image ENV.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		[ -f /etc/frankenphp/Caddyfile.dev ] && cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		;;
	worker)
		# Image default Caddyfile is worker (Dockerfile COPY). After changing
		# FRANKENPHP_MODE, recreate the container (`docker compose up -d`).
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/doctrine-deadlock-retry-bundle 2>/dev/null || true
mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var 2>/dev/null || true

if [ ! -f /app/vendor/autoload_runtime.php ]; then composer install --no-interaction; fi
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
