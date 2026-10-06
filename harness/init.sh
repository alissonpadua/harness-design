#!/usr/bin/env bash
# harness/init.sh — idempotent environment boot. Safe to run blindfolded every session.
set -euo pipefail
HARNESS_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$HARNESS_DIR/../src"

# parallel worktrees: export COMPOSE_PROJECT_NAME=bp-<branch> before calling
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-bp}"

docker compose up -d --wait pgsql redis mailpit rustfs bucket-init
docker compose up -d app nginx

[ -f .env ] || { cp .env.example .env; echo "=> .env created from example — review values"; }

docker compose exec -T app bash -lc '
  set -e
  export COMPOSER_ALLOW_SUPERUSER=1
  [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
  grep -q "^APP_KEY=base64:" .env || php artisan key:generate --force
'

bash "$HARNESS_DIR/scripts/install-hooks.sh"

docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --force 2>/dev/null || true

cat <<'EOF'
✔ Boilerplate ready
  API:    http://localhost:8080/api/v1
  Docs:   http://localhost:8080/docs
  Mailpit http://localhost:8025
  S3 (RustFS) http://localhost:9001 (boilerplate/boilerplate-dev)
Next: tail harness/progress.md, git log --oneline -15, run a smoke test.
EOF
