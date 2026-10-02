#!/usr/bin/env bash
# harness/scripts/check.sh — THE verification. Local == CI. Must stay byte-identical in output semantics.
# Order: format → static → deps audit → arch → tests → openapi diff
set -euo pipefail
cd "$(dirname "$0")/../../src"

echo "== pint"
docker compose exec -T app ./vendor/bin/pint --test

echo "== larastan (level 8)"
docker compose exec -T app ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G

echo "== composer audit"
docker compose exec -T app composer audit --no-interaction

echo "== pest (incl. architecture tests)"
docker compose exec -T app ./vendor/bin/pest

echo "== openapi freshness"
docker compose exec -T app php artisan scramble:export --path=openapi.json
git -C .. diff --quiet -- src/openapi.json || {
  echo "✗ openapi.json is stale — run: src/bin/scramble && commit the diff"
  exit 1
}

echo "✔ check.sh green"
