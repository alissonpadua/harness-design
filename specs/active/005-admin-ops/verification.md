# Verification 005 — Admin & Ops

Append-only evidence log. 005 S1–S7 flips only from rows here. Env: Docker PHP 8.4 / Laravel 13, sqlite in tests, activitylog + horizon + health installed. Gates run 2026-10-07: pint ✓ · Larastan L8 [OK] ✓ · composer audit ✓ · arch (20) ✓ · **333 tests / 1762 assertions / Total: 100.0 %** ✓ · openapi regenerated (freshness pending commit, by design).

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
| T0 hotfix (audit run-1 HIGH×2) | T0 | M002 RolePlaneHardeningTest (2) + M004 MailHardeningTest | self-promote-to-owner via role surface 422; suspend of sole owner of an org 422 (evict protection); markdown link body escaped/stripped | pending |
| AC-005.1 / S1 | T2 | AdminUsersTest 'AC-005.1 non-super-admin rejected on every user route' + OpsCoverage 'suspended users blocked…' | plain user 403 on all /admin/v1/users/* lanes; arch rule 'every /admin/* route carries role:super-admin' (RouteRulesTest) + vacuity guard (>10 routes) | pending |
| AC-005.2 / S2 | T2 | AdminUsersTest 'reason mandatory, tokens revoked, login blocked, audit written' + 'suspend-then-unsuspend restores login' + OpsCoverage passkey/magic/oauth lanes | <5-char reason 422; all victim tokens dead incl. mid-session fresh token; login 403 'Account suspended.' on password+passkey+magic+oauth; unsuspend restores; user.suspend audit w/ reason | pending |
| AC-005.12 | T2 | same as AC-005.2 rows | not-suspended middleware on orgs/profile/notifications/admin groups; 403 JSON parity | pending |
| AC-005.14 | T2 | AdminUsersTest 'admin.login audited for super-admins only' | login by super-admin writes audit row; normal user login writes none | pending |
| AC-005.3 / S3 (Q5 override) | T3 | ImpersonationTest lifecycle + exclusions + 'never expire on their own' + LIVE | victim token carries impersonator_id; every impersonated request audited; BOTH /api/v1/auth/me and /api/v1/profile expose impersonation block (spec L52 gap found in live demo, fixed via User::currentImpersonation()); admin/security/destroy/transfer lanes 403 while impersonating; one-active-per-pair (start replaces); stop revokes + audits; no expiry by design (deviation #1) | pending |
| AC-005.4 / S4 | T2/T3 | AdminUsersTest 'restore: soft-deleted user back' + AdminOrgsTest 'orgs list, detail, restore' + AC-005.11 rows | user & org restore endpoints work; suspension survives restore | pending |
| AC-005.11 | T2 | AdminUsersTest 'force-delete: confirm gate, owned-team block, hard cascade, audit survives' + OpsCoverage personal-workspace test | confirm_text must equal email (else 422); orgs with other members → 422 unless trashed first; hard delete incl. tokens/prefs/notifications/personal workspace; activity_log rows survive | pending |
| AC-005.5 / S5 | T4 | AdminOrgsTest 'gateway cancel, local swap, domain event, audit' + 'admin_locked makes later webhooks skip and a fresh checkout wins' + OpsCoverage 'admin_locked skips every late event lane' | PlanChanged event + audit; Stripe sub cancelled + gateway id retained; deleted/paid/failed late webhooks all → skipped_locked and status untouched; org's own checkout clears lock | pending |
| AC-005.6 / S5 | T4 | AdminOrgsTest 'subset merge, live effect, clear, validation, audit' + OpsCoverage direct-action edges | partial override merged onto effective plan; unknown key 422; bad type 422 via DTO catch; {} clears; effective() reflects override (incl. audit_retention_days) | pending |
| AC-005.7 / S6 | T1/T5 | AuditCoreTest schema/audit-writes + AuditQueryTest 'S6 platform audit query' | LogsActivity on 4 models writes dirty-whitelisted changes only; AuditSecurityEvent sole writer (arch rule); filters subject/causer/event/date + cursor paging | pending |
| AC-005.8 / S6 (Q1 addition) | T5 | AuditQueryTest 'Q1 org audit feed' + OpsCoverage cursor test | GET /api/v1/orgs/{org}/audit owner+admin only (role matrix), window = plan audit_retention_days, free → 402, next_cursor paging | pending |
| Q2 retention/prune | T5 | AuditQueryTest 'Q2 prune' | audit:prune removes only rows older than AUDIT_RETENTION_DAYS (365d default), --dry-run reports, schedule entry 03:00 present in schedule:list | pending |
| AC-005.9 / S7 | T6 | OpsTest '/up public health' ×2 + OpsCoverage failure branches + LIVE smoke 2026-10-07 | /up 200 db+redis+queue; forced-failing check → 503; dispatch-throw + timeout branches covered. LIVE found predis coerces cached int→string (sync/array test stores hid it) → marker switched to 'consumed' string; dev /up verified ok after queue:restart | pending |
| AC-005.10 / S7 | T6 | OpsTest 'horizon: 403 anon/plain, bearer super-admin ok, signed entry mints pass, impersonated barred' + OpsCoverage gate unit | signed URL 60s temp; entry mints 8h HMAC pass cookie; expired/garbage sig 403; bearer super-admin 200; impersonated 403; cookie/expiry/tamper branches unit-covered; horizon.link audit | pending |
| AC-005.13 / S6 | T6 | OpsTest 'webhook replay' + OpsCoverage 'routes every event arm' | replay without force = duplicate-skip; force re-runs handler idempotently; unknown event 404; every match arm routed; handler exception → outcome failed; webhook.replay audit | pending |
| Gates (constitution #11) | T8 | harness/scripts/check.sh | pint/Larastan/composer-audit/pest --coverage --min=100 → Total: 100.0 %; openapi.json regenerated with all 005 endpoints (freshness diff pending commit — by design until human executes commit) | pending |

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
