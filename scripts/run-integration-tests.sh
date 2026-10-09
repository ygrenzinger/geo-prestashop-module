#!/usr/bin/env bash
set -euo pipefail

# Boot the PrestaShop 9 test shop (Docker) and run the PHPUnit suite inside the
# container, where both the shop and the database are reachable.
#
# Unit tests can also run on the host without Docker:
#   composer install && vendor/bin/phpunit --testsuite unit
#
# Integration tests need the shop, so use this script:
#   scripts/run-integration-tests.sh [phpunit args...]

cd "$(dirname "$0")/.."

PHPUNIT_VERSION="${PHPUNIT_VERSION:-12}"
TOOLS_DIR=".tools"
PHPUNIT_PHAR="$TOOLS_DIR/phpunit.phar"
SHOP_URL="${SHOP_URL:-http://localhost:8080/}"

if [[ ! -f "$PHPUNIT_PHAR" ]]; then
  mkdir -p "$TOOLS_DIR"
  printf 'Downloading PHPUnit %s to %s...\n' "$PHPUNIT_VERSION" "$PHPUNIT_PHAR"
  curl -fsSL -o "$PHPUNIT_PHAR" "https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar"
fi

printf 'Starting the PrestaShop test shop (the first run installs the shop, this can take a few minutes)...\n'
docker compose up -d

printf 'Waiting for the shop at %s ...\n' "$SHOP_URL"
for _ in $(seq 1 60); do
  if curl -fsS -o /dev/null "$SHOP_URL" 2>/dev/null; then
    printf 'Shop is up.\n'
    break
  fi
  sleep 5
done

printf 'Running PHPUnit inside the container...\n'
docker compose exec -T prestashop bash -lc \
  'cd /workspace && PS_TEST_SHOP_ROOT=/var/www/html php .tools/phpunit.phar -c phpunit.xml.dist "$@"' \
  bash "$@"
