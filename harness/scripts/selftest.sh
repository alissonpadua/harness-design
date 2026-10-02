#!/usr/bin/env bash
# selftest.sh — both-direction checks for the PR gate scripts (runs on any bash+git host, incl. CI).
# Constitution #2/#3: this file is part of the verification rails; failing here blocks merges.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

fail() { echo "✗ selftest: $1"; exit 1; }
ok()   { echo "✔ selftest: $1"; }

git init -q "$TMP/repo"
cd "$TMP/repo"
git config user.email t@t && git config user.name t && git config commit.gpgsign false
mkdir -p src/app specs harness
echo x > README.md && git add -A && git commit -qm base
git branch -M main
REMOTE="$TMP/remote.git"; git init -q --bare "$REMOTE"; git remote add origin "$REMOTE"
git push -q origin main

# --- gate-spec-required: POSITIVE (should fail) ---
echo y > src/app/Rogue.php && git add -A && git commit -qm "impl without spec"
if "$ROOT/harness/scripts/gate-spec-required.sh" origin/main >/dev/null 2>&1; then
  fail "spec-required accepted src change without spec"
fi
ok "spec-required rejects impl-only diff"

# --- gate-spec-required: NEGATIVE (should pass) ---
touch specs/active.md && git add -A && git commit -qm "with spec"
"$ROOT/harness/scripts/gate-spec-required.sh" origin/main >/dev/null || fail "spec-required rejected a compliant diff"
ok "spec-required accepts impl+spec diff"

# --- gate-tdd-order: POSITIVE (should fail) ---
rm -rf src/app && mkdir -p src/app && echo y > src/app/Rogue2.php && git add -A && git commit -qm "impl no tests"
if "$ROOT/harness/scripts/gate-tdd-order.sh" origin/main >/dev/null 2>&1; then
  fail "tdd-order accepted impl commit without tests"
fi
ok "tdd-order rejects testless impl commit"

# --- gate-tdd-order: NEGATIVE (should pass) ---
# Reset so the offending testless-impl commit is out of range; then a single commit that
# bundles implementation + its test (the per-commit contract AC-010.4 demands).
git reset -q --hard origin/main
mkdir -p src/tests src/app && echo y > src/app/Final.php && echo y > src/tests/FinalTest.php && git add -A && git commit -qm "impl with test"
"$ROOT/harness/scripts/gate-tdd-order.sh" origin/main >/dev/null || fail "tdd-order rejected compliant history"
ok "tdd-order accepts tests-with-impl history"

ok "all gate self-tests passed"
