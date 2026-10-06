# Verification 005 — Admin & Ops

Append-only evidence log. 005 S1–S7 flips only from rows here. Env: Docker PHP 8.4 / Laravel 13, sqlite in tests, activitylog + horizon + health installed.

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
|    |      |                |          |        |

## feature_list 005 step → AC map
- S1 (admin rejects non-super-admin) ← AC-005.1
- S2 (suspend/unsuspend/reason) ← AC-005.2, AC-005.12, AC-005.14
- S3 (impersonation — superseded by Q5) ← AC-005.3
- S4 (restore user/org) ← AC-005.4
- S5 (plan change + overrides) ← AC-005.5, AC-005.6
- S6 (audit append-only + filters) ← AC-005.7, AC-005.8, AC-005.13
- S7 (/up health + Horizon) ← AC-005.9, AC-005.10

## Deviations & human overrides
1. **feature_list S3 wording "15-min token … ends on expiry"** is SUPERSEDED by Q5 decision (2026-10-06): no auto-expiry; impersonation lives until explicit stop (one-active per pair, start replaces). S3 still flips, intent (claim + per-request audit + explicit exit) verified; auto-expiry intentionally removed per human.
2. **Package substitution**: scope says `spatie/laravel-auditable` (nonexistent) → `spatie/laravel-activitylog` v5 implements the audit log (same intent).
3. Q1 org-facing audit endpoint is an ADDITION beyond feature_list (human Q1=A choice) — does not relax any locked-scope boundary.

## Manual checks (human, dev)
1. Horizon: `GET /admin/v1/ops/horizon-url` → open signed link in browser (<60s) → dashboard loads, `horizon.link` audit row; expired → 403.
2. `curl -s localhost:8080/up | jq` → status ok, 3 checks; `docker compose stop redis` → 503.
3. Impersonate a user via `POST /admin/v1/users/{id}/impersonate`, use token: `/me` shows impersonation block, `/admin/v1/*` 403, billing portal reads work; `GET /admin/v1/impersonations` lists it; stop kills it.
