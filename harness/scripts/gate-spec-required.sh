#!/usr/bin/env bash
# gate-spec-required.sh — constitution #1: src/ code changes need a spec diff.
# Usage: gate-spec-required.sh <base-ref>   (env BP_DIFF_FILES=... for testing without git)
set -euo pipefail
BASE="${1:-origin/main}"

if [ -n "${BP_DIFF_FILES:-}" ]; then
  CHANGED="$BP_DIFF_FILES"
else
  CHANGED=$(git diff --name-only "$BASE"...HEAD)
fi

CODE=$(echo "$CHANGED" | grep -E '^src/(app|routes|database|config)/' || true)

if [ -n "$CODE" ] && ! echo "$CHANGED" | grep -qE '^specs/|^harness/feature_list.json'; then
  echo "✗ spec required: src/ changes need a specs/ or harness/feature_list.json diff:"
  echo "$CODE" | sed 's/^/    /'
  exit 1
fi

echo "✔ spec-required gate passed"
