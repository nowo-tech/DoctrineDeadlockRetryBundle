#!/usr/bin/env bash
# Update Composer dependencies inside every Symfony demo of a bundle.
# Usage: update-deps-demos.sh /absolute/path/to/BundleName

set -euo pipefail

BUNDLE_ROOT="${1:?Bundle root path required}"
DEMO_DIR="${BUNDLE_ROOT}/demo"
COMPOSER_ENV=(exec -T -e COMPOSER_MEMORY_LIMIT=-1)

if [[ ! -d "${DEMO_DIR}" ]]; then
  exit 0
fi

run_demo_update() {
  local demo_path="$1"
  local demo_name
  demo_name="$(basename "${demo_path}")"

  echo ""
  echo "=== Demo ${demo_name}: ensure container ==="
  (
    cd "${demo_path}"
    if [[ ! -f .env ]] && [[ -f .env.example ]]; then
      cp .env.example .env
    fi

    if [[ -f Makefile ]] && make -n update-deps >/dev/null 2>&1; then
      make update-deps
      exit 0
    fi

    docker-compose up -d
    sleep 5
    echo "=== Demo ${demo_name}: composer update ==="
    docker-compose "${COMPOSER_ENV[@]}" php composer update --no-interaction
  )
}

if [[ -f "${DEMO_DIR}/Makefile" ]] && make -C "${DEMO_DIR}" -n update-deps-all >/dev/null 2>&1; then
  make -C "${DEMO_DIR}" update-deps-all
  exit 0
fi

for demo_path in "${DEMO_DIR}"/*; do
  if [[ -d "${demo_path}" ]] && [[ -f "${demo_path}/composer.json" ]]; then
    run_demo_update "${demo_path}"
  fi
done
