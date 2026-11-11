#!/usr/bin/env bash
# Readies the E2E shop once its containers are up: waits for the OpenCart
# install, installs and enables the extension with dev/configure.php (sandbox
# environment; the stub's key, TWO_API_STUB_KEY in tests/e2e/compose.yml, or
# E2E_SANDBOX_API_KEY), then sets the shop up for the specs
# (tests/e2e/bin/seed.php).
set -euo pipefail

cd "$(dirname "$0")/../../.."
compose=(docker compose -f tests/e2e/compose.yml)
shop() { "${compose[@]}" exec -T -u www-data "$@"; }

for _ in $(seq 1 90); do
  shop opencart grep -q "define('DB_DATABASE'" /var/www/html/config.php 2>/dev/null && break
  sleep 2
done
shop opencart grep -q "define('DB_DATABASE'" /var/www/html/config.php \
  || { echo "prepare: OpenCart did not install"; "${compose[@]}" logs --no-color opencart | tail -50; exit 1; }

shop -e OPENCART_DIR=/var/www/html -e TWO_ENVIRONMENT=sandbox -e "TWO_API_KEY=${E2E_SANDBOX_API_KEY:-e2e-stub-key}" \
  opencart php /var/www/html/extension/two/dev/configure.php
# From this checkout, so the upgrade smoke can seed a release that predates it.
shop -e OPENCART_DIR=/var/www/html opencart php < tests/e2e/bin/seed.php
echo "prepare: shop ready on http://localhost:${E2E_PORT:-1240}/"
