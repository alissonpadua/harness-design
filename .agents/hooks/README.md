# Hooks (wire per tool: Claude Code .claude/settings.json, opencode plugin, CI mirror)
- session-start: `tail -n 80 harness/progress.md && git log --oneline -15 && harness/init.sh`
- post-edit (php): run scoped pest filter for touched file's test mirror + pint on that file
- pre-commit: **WIRED (2026-10-06)** → `.githooks/pre-commit` → `harness/scripts/security-audit.sh --scope staged`:
  Cloudflare security-audit skill gate (secret scan · skill validators · headless audit of staged diff;
  blocks on confirmed findings ≥ SECURITY_AUDIT_BLOCK). Enablement: `harness/scripts/install-hooks.sh` (init.sh does it).
  Remaining hooks below stay advisory; check.sh + CI remain the full verification:
- stop: require progress.md append + tasks.md tick in same commit
Guardrails are advisory locally, ENFORCING in CI (mirrors exist in .github/workflows). **Exception:** the security-audit pre-commit gate is real and wired via `core.hooksPath=.githooks` — bypass only `--no-verify` / `SECURITY_AUDIT=off` (both loud); CI mirrors its deterministic stages.
