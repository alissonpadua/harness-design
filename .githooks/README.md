# Git hooks (tracked; activated via `core.hooksPath = .githooks`)
Installed by `harness/init.sh` or manually `harness/scripts/install-hooks.sh`.

**pre-commit** → `harness/scripts/security-audit.sh --scope staged`:
1. deterministic secret scan of staged added lines (hard block),
2. Cloudflare skill validators on `harness/audits/current/*` (hard block),
3. headless **security-audit skill** run scoped to the staged diff (block on confirmed findings ≥ `SECURITY_AUDIT_BLOCK`, default `high`).

Env toggles: `SECURITY_AUDIT=auto|agent|off`, `SECURITY_AUDIT_BLOCK=critical|high|medium|off`, `SECURITY_AUDIT_TIMEOUT`, `SECURITY_AUDIT_ALLOW_AGENT=0`. Native bypass: `git commit --no-verify`. CI mirrors stages 1–2 always.
