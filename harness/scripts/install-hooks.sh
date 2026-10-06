#!/usr/bin/env bash
# Point git at the tracked hooks dir (idempotent, safe to re-run).
set -euo pipefail
root=$(git rev-parse --show-toplevel)
git -C "$root" config core.hooksPath .githooks
chmod +x "$root/.githooks/pre-commit" "$root/harness/scripts/security-audit.sh" 2>/dev/null || true
echo "✔ git core.hooksPath → .githooks (security-audit pre-commit active)"
