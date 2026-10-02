#!/usr/bin/env bash
# gate-tdd-order.sh — constitution #2: implementation commits must carry test changes.
# Usage: gate-tdd-order.sh <base-ref> [max-commit-count]
set -euo pipefail
BASE="${1:-origin/main}"

for c in $(git rev-list --reverse "$BASE"..HEAD); do
  FILES=$(git show --name-only --format= "$c" | sed '/^$/d')
  IMPL=$(echo "$FILES" | grep -E '^src/(app|routes)/' || true)
  TEST=$(echo "$FILES" | grep -E '^src/tests/' || true)

  if [ -n "$IMPL" ] && [ -z "$TEST" ]; then
    echo "✗ tdd-order: commit $(git log -1 --format='%h %s' "$c") touches implementation without tests"
    exit 1
  fi
done

echo "✔ tdd-order gate passed"
