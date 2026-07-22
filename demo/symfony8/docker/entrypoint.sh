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

if [ ! -f /app/vendor/autoload_runtime.php ]; then
  i=0
  until composer install --no-interaction --prefer-dist; do
    i=$((i+1))
    if [ "$i" -ge 5 ]; then echo "composer install failed after 5 attempts" >&2; exit 1; fi
    echo "composer install failed, retry $i in 8s..." >&2
    sleep 8
  done
fi
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
