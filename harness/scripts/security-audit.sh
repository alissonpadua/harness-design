#!/usr/bin/env bash
# harness/scripts/security-audit.sh — Cloudflare security-audit skill gate.
# Single entrypoint used by: .githooks/pre-commit, CI (.github/workflows), humans.
#
# Stages
#   1) secret scan over staged/changed lines            — deterministic, ALWAYS hard-fails
#   2) audit artifacts validator (skill's own cjs)      — deterministic, hard-fails on invalid
#   3) the audit itself (headless `opencode run` using .agents/skills/security-audit)
#        scope=staged  → audits `git diff --cached` (pre-commit default)
#        scope=full    → six-phase audit of src/ (CI dispatch / manual)
#      confirmed findings ≥ SECURITY_AUDIT_BLOCK block the run.
#
# Env switches
#   SECURITY_AUDIT=auto|agent|off          default auto (agent if opencode present)
#   SECURITY_AUDIT_SCOPE=staged|full       default staged
#   SECURITY_AUDIT_BLOCK=critical|high|medium|off   default high
#   SECURITY_AUDIT_TIMEOUT=<secs>          default 900 (staged) / 3600 (full)
#
# Bypass: `git commit --no-verify` (visible, last-resort) or SECURITY_AUDIT=off for one run.
set -uo pipefail

ROOT=$(git rev-parse --show-toplevel 2>/dev/null || pwd)
cd "$ROOT"

SKILL_DIR=".agents/skills/security-audit"
AUDIT_DIR="harness/audits/current"
MODE="${SECURITY_AUDIT:-auto}"
SCOPE="${SECURITY_AUDIT_SCOPE:-staged}"
BLOCK="${SECURITY_AUDIT_BLOCK:-high}"
TIMEOUT_SECS="${SECURITY_AUDIT_TIMEOUT:-}"
FAILURES=0
NODE="${NODE_BIN:-$(command -v node || true)}"

rank() { case "$1" in informational) echo 0;; low) echo 1;; medium) echo 2;; high) echo 3;; critical) echo 4;; *) echo -1;; esac; }

log() { printf 'security-audit: %s\n' "$*"; }
fail() { log "✗ $*"; FAILURES=$((FAILURES + 1)); }

usage() {
  sed -n '2,22p' "$0"
  exit "${1:-0}"
}

# ── CLI ─────────────────────────────────────────────────────────────────────
SECRETS_ONLY=0
VALIDATE_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --secret-scan)   SECRETS_ONLY=1 ;;
    --validate)      VALIDATE_ONLY=1 ;;
    --scope)         shift; SCOPE="${1:-staged}" ;;
    --mode)          shift; MODE="${1:-auto}" ;;
    -h|--help)       usage 0 ;;
    *) log "unknown flag: $1"; usage 1 ;;
  esac
  shift
done

# ── Stage 1: deterministic secret scan (hard gate, fail-closed) ─────────────
secret_scan() {
  local diff
  if [ -n "${SECURITY_AUDIT_BASE:-}" ]; then
    if ! git rev-parse --verify -q "origin/${SECURITY_AUDIT_BASE}" >/dev/null 2>&1 && ! git rev-parse --verify -q "${SECURITY_AUDIT_BASE}" >/dev/null 2>&1; then
      if [ "${CI:-}" = "true" ] || [ "${SECURITY_AUDIT_STRICT:-0}" = "1" ]; then
        fail "secret scan: base ref '${SECURITY_AUDIT_BASE}' unresolvable — nothing scanned (refusing to claim green)"
        return
      fi
      log "⚠ secret scan: base ref unresolvable — nothing scanned"
      return
    fi
    diff=$(git diff -U0 --diff-filter=ACMR "origin/${SECURITY_AUDIT_BASE}...HEAD" 2>/dev/null         || git diff -U0 --diff-filter=ACMR "${SECURITY_AUDIT_BASE}...HEAD" 2>/dev/null || true)
    if [ -z "$diff" ]; then
      if [ "${CI:-}" = "true" ] || [ "${SECURITY_AUDIT_STRICT:-0}" = "1" ]; then
        fail "secret scan: base range resolved EMPTY — nothing scanned (refusing to claim green; on push events set an event-aware range)"
        return
      fi
      log "⚠ secret scan: base range empty — nothing scanned"
      return
    fi
  elif [ -n "$(git diff --cached --name-only)" ]; then
    diff=$(git diff --cached -U0 --diff-filter=ACMR)
  else
    diff=$(git diff -U0 --diff-filter=ACMR HEAD~1 2>/dev/null || true)
  fi

  if [ -z "$NODE" ]; then
    if [ "${CI:-}" = "true" ] || [ "${SECURITY_AUDIT_STRICT:-0}" = "1" ]; then
      fail "secret scan needs node but it is missing — install node or use --no-verify (loud bypass)"
    else
      log "⚠ node not found — secret scan SKIPPED (set SECURITY_AUDIT_STRICT=1 to hard-fail; CI always enforces)"
    fi
    return
  fi

  local scanner="$ROOT/harness/scripts/security-audit-lib/secret-scan.cjs"
  local out rc
  out=$(printf '%s\n' "$diff" | "$NODE" "$scanner"); rc=$?
  if [ "$rc" -eq 2 ]; then
    fail "secret scanner errored (exit 2) — refusing to pass unverified"
    return
  fi
  if [ -n "$out" ]; then
    while IFS=$'\t' read -r f kind; do
      [ -n "$f" ] && fail "possible secret in $f ($kind) — rotate, purge history, keep secrets in untracked .env"
    done <<< "$out"
  else
    log "secret scan ✔"
  fi
}

# ── Stage 2: validators from the skill itself (deterministic) ───────────────
run_validators() {
  if [ ! -d "$SKILL_DIR" ]; then
    fail "skill missing at $SKILL_DIR"
    return
  fi
  if [ -z "$NODE" ]; then
    if [ "${CI:-}" = "true" ]; then fail "node required in CI to run skill validators"; else log "node not found — validators skipped (install node to enforce)"; fi
    return
  fi

  local artifact
  for artifact in "$AUDIT_DIR/findings.json" "$AUDIT_DIR/coverage-ledger.json"; do
    if [ -f "$artifact" ]; then
      local vscript="$SKILL_DIR/validate-$(basename "$artifact" .json | sed 's/findings/findings/;s/coverage-ledger/coverage-ledger/').cjs"
      [ -f "$vscript" ] || continue
      if "$NODE" "$vscript" "$artifact" >/dev/null 2>&1; then
        log "$artifact valid ✔"
      else
        fail "$artifact failed the Cloudflare validator (see node $vscript $artifact)"
      fi
    fi
  done
}

# ── Stage 3: the audit itself via headless opencode ─────────────────────────
have() { command -v "$1" >/dev/null 2>&1; }

with_timeout() {
  local secs="$1"; shift
  if have timeout; then timeout "$secs" "$@"
  elif have gtimeout; then gtimeout "$secs" "$@"
  else "$@"; fi
}

collect_staged_diff() {
  local out="$1" limit=400000
  {
    echo '# staged files'
    git diff --cached --name-status --diff-filter=ACMR
    echo '# unified diff'
    git diff --cached -U5
  } > "$out"
  local size
  size=$(wc -c < "$out" | tr -d ' ')
  if [ "$size" -gt "$limit" ]; then
    log "diff is ${size}B > ${limit}B — agent will inspect repo directly (file list kept)"
    { head -c "$limit" "$out"; echo; echo '[... diff truncated: audit the actual files, do not rely on this patch ...]'; } > "$out.tmp" && mv "$out.tmp" "$out"
  fi
}

agent_audit() {
  local prompt diff
  mkdir -p "$AUDIT_DIR"
  diff="$AUDIT_DIR/diff.patch"

  if [ "$SCOPE" = "staged" ]; then
    if [ -z "$(git diff --cached --name-only --diff-filter=ACMR)" ]; then
      log "no staged code changes — agent audit skipped"
      return 0
    fi
    collect_staged_diff "$diff"
  fi

  prompt="$AUDIT_DIR/prompt.txt"
  cat > "$prompt" <<PROMPT
SECURITY GATE RUN (scope: ${SCOPE}).
Load and follow .agents/skills/security-audit/SKILL.md exactly.
${SCOPE:+Scope: audit ONLY these staged changes ($(basename "$diff")) — full six-phase workflow, bounded to the diff's files plus the trust boundaries they touch.}
Hard rules for this run:
- You are READ-ONLY outside $AUDIT_DIR/: never run git (any subcommand that writes), never edit/create/delete project files, never commit.
- Write ONLY these files, overwriting them: $AUDIT_DIR/findings.json (array conforming to $SKILL_DIR/report-schema.json, verdicts: confirmed/needs_validation/rejected) and $AUDIT_DIR/coverage-ledger.json, plus REPORT.md summary.
- Validate your own output with: node $SKILL_DIR/validate-findings.cjs $AUDIT_DIR/findings.json — iterate until PASS.
- If you cannot execute target code safely, keep those leads as needs_validation (never confirmed without observed result).
PROMPT

  local secs="${TIMEOUT_SECS:-$([ "$SCOPE" = full ] && echo 3600 || echo 900)}"
  log "running headless security audit (scope=$SCOPE, timeout ${secs}s)…"
  local rc=0
  if [ "$SCOPE" = staged ]; then
    with_timeout "$secs" opencode run --title "security-audit-staged" -f "$diff" "$(cat "$prompt")" || rc=$?
  else
    with_timeout "$secs" opencode run --title "security-audit-full" "$(printf '%s\nAudit the src/ application tree in full six-phase mode.' "$prompt")" || rc=$?
  fi
  if [ "$rc" -ne 0 ]; then
    log "⚠ agent run exited rc=$rc (timeout/crash?) — validating whatever artifacts it left"
  fi
}

severity_gate() {
  [ "$BLOCK" = "off" ] && { log "severity gate off (SECURITY_AUDIT_BLOCK=off)"; return 0; }
  [ -f "$AUDIT_DIR/findings.json" ] || { log "no findings.json — nothing to gate"; return 0; }
  if [ -z "$NODE" ]; then fail "cannot gate severities without node"; return; fi

  local hits
  hits=$("$NODE" -e '
    const fs = require("fs");
    const rank = { informational: 0, low: 1, medium: 2, high: 3, critical: 4 };
    const min = rank[process.argv[2]] ?? 3;
    let f;
    try { f = JSON.parse(fs.readFileSync(process.argv[1], "utf8")); } catch (e) { console.log("PARSE:" + e.message); process.exit(0); }
    const bad = (Array.isArray(f) ? f : []).filter(x => x && x.verdict === "confirmed" && rank[x.severity?.overall_severity] >= min);
    for (const b of bad) console.log(`CONFIRMED [${b.severity.overall_severity}] ${b.title ?? b.id ?? "?"} → ${b.file ?? b.location ?? "?"}`);
  ' "$AUDIT_DIR/findings.json" "$BLOCK" 2>/dev/null || true)

  if printf '%s' "$hits" | grep -q '^PARSE:'; then
    fail "findings.json unreadable/invalid"
  elif [ -n "$hits" ]; then
    while IFS= read -r h; do [ -n "$h" ] && fail "$h"; done <<< "$hits"
    log "full detail: $AUDIT_DIR/findings.json + REPORT.md"
  else
    log "severity gate ✔ (no confirmed findings ≥ $BLOCK)"
  fi
}

# ── orchestration ────────────────────────────────────────────────────────────
secret_scan
if [ "$SECRETS_ONLY" = "1" ]; then
  [ "$FAILURES" -eq 0 ] && { log "✔ secret scan green"; exit 0; } || { log "✗ blocked"; exit 1; }
fi

run_validators
if [ "$VALIDATE_ONLY" = "1" ]; then
  [ "$FAILURES" -eq 0 ] && { log "✔ validators green"; exit 0; } || { log "✗ blocked"; exit 1; }
fi

sensitive_staged() {
  git diff --cached --name-only --diff-filter=ACMR \
    | grep -qE '^src/|^config/|^\.github/|^docker|^src/docker|composer\.(json|lock)$|\.env'
}

case "$MODE" in
  off)
    log "agent audit disabled by SECURITY_AUDIT=off (secrets+validators above still enforced unless --no-verify)"
    ;;
  agent)
    if have opencode; then agent_audit; run_validators; severity_gate
    else fail "SECURITY_AUDIT=agent but opencode CLI not found"; fi
    ;;
  auto|*)
    if [ "$SCOPE" = staged ] && ! sensitive_staged; then
      log "no security-sensitive paths staged — agent phase skipped (docs/spec-only change)"
    elif have opencode && [ "${SECURITY_AUDIT_ALLOW_AGENT:-1}" = "1" ]; then
      agent_audit; run_validators; severity_gate
    else
      log "⚠ opencode not available — deterministic gates only (secret scan + validators of $AUDIT_DIR/*)"
    fi
    ;;
esac

if [ "$FAILURES" -gt 0 ]; then
  echo
  log "✗ COMMIT BLOCKED ($FAILURES issue(s)). Fix or justify: git commit --no-verify (visible bypass). CI mirrors the deterministic stages."
  exit 1
fi
log "✔ green"
