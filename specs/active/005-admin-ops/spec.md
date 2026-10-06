# Spec 005 — Admin & Ops

Status: DRAFT — awaiting human approval (constitution #1)
Source: ../../feature-scope.md § Module 5 v2 (LOCKED) + Q1–Q6 decisions 2026-10-06
Maps to: feature_list 005 S1–S7.

## Locked decisions (Q&A 2026-10-06)
1. **Q1=A** — platform audit super-admin only; PLUS org-facing `GET /api/v1/orgs/{org}/audit` for owner/admin gated by the 003 `audit_retention_days` entitlement (free → 402; 90/365d windows)
2. **Q2=A** — platform audit retention 365 days (`AUDIT_RETENTION_DAYS`), prune job is the only deleter
3. **Q3** — force-delete = TRUE hard delete (confirm_text=email); owned orgs must be transferred first (422); audit rows survive (morph refs stay valid)
4. **Q4** — `GET /up` (public): database · redis · queue roundtrip only
5. **Q5 (corrected)** — impersonation has **no 15-min auto-expiry**: lives until admin explicitly stops it; exclusions per proposal (admin plane, security-reauth, destroy, transfer); every impersonated request audited; one active per (admin,target), start replaces; `GET /me` exposes impersonation block *(supersedes feature_list S3 wording — see verification deviations)*
6. **Q6=B** — admin org plan change **cancels the remote gateway subscription immediately** + `admin_locked` flag so late webhooks cannot clobber the grant

## Package notes
- Scope's `spatie/laravel-auditable` does not exist → **spatie/laravel-activitylog v5** implements it (recorded as substitution, ADR-0011 not required — pure tooling choice)
- `laravel/horizon` (installed, provider+config published), `spatie/laravel-health` (installed), `laravel/sentinel` skipped (unused dependency)

## Data model (migrations)
- `personal_access_tokens`: add `impersonator_id` nullable FK users nullOnDelete + index
- `users`: add `suspended_at` timestamp null, `suspend_reason` string nullable
- `subscriptions`: add `admin_locked` boolean default false
- `organization_entitlement_overrides`: organization_id unique FK, `overrides` JSON (subset of the 5 entitlement keys), timestamps
- `activity_log`: published (subject/causer morphs, properties JSON, batch, index on (subject_type,subject_id,created_at) + (event))
- audit config: `config/audit.php` → retention_days (365), events whitelist

## Audit design
- **Model audits** (activitylog `LogsActivity` with `updatedAttributes` only, no passwords/secrets/tokens): User (status-relevant fields), Organization, OrganizationMembership (role/status), Plan, PlanPrice, BillingSubscription, permission assignments (explicit log from OrgAuthorizer/role map consumers + RolesSeeder untouched).
- **Security events** explicit via `AuditSecurityEvent` action (causer = acting admin): `admin.login`, `user.suspend`, `user.unsuspend`, `user.restore`, `user.force_delete`, `impersonation.start`, `impersonation.stop`, `impersonated.request` (per-request, method/path/token_id), `org.plan_change`, `org.entitlement_override`, `webhook.replay`, `horizon.link`.
- **Append-only enforcement (S6):** zero update/delete routes + Policy-free read API; arch test asserts `activityLog()` never called with `withoutLogging` hacks and no `Activity::` write outside the audit action/models; prune via `audit:prune` command (daily schedule, `AUDIT_RETENTION_DAYS`, org-window rows untouched — same table, window applied at READ time).
- Org-facing read window: `created_at >= now() - planEntitlement.audit_retention_days` (override applied), 402 when 0.

## API — admin plane (all `/admin/v1/*`, role:super-admin + NOT impersonating + throttle:admin-generic)
- `GET users?q=&status=active|suspended|deleted|all&page=` → id, name, email, suspended_at, orgs_count, last_activity
- `GET users/{user}` → profile + orgs + tokens summary + recent own audit entries
- `POST users/{user}/suspend` {reason required 5..255} → suspended_at+reason, revoke ALL tokens, audit, 422 already-suspended
- `POST users/{user}/unsuspend` → clears, audit, 422 not-suspended
- `POST users/{user}/restore` (soft-deleted) → restores (NOT unsuspend — orthogonal), audit
- `DELETE users/{user}` {confirm_text = email} → Q3 hard delete; 422 `Transfer or delete owned organizations first.` when owning teams
- `GET orgs?q=&state=active|deleted|suspended?` (orgs have no suspend — list+detail+restore only) `GET orgs/{org}`, `POST orgs/{org}/restore`
- `POST orgs/{org}/plan` {plan_code} → Q6: gateway cancel (remote dies), local swap (status active, admin_locked=true, period unchanged), over-limit evaluation, `SubscriptionPlanChanged` dispatched (004 notifies), audit w/ previous plan+gateway id; 404 unknown/inactive plan; same-plan 422
- `PUT orgs/{org}/entitlements` {overrides: {…}|{}} → merge on top of plan (keys validated against PlanEntitlementsData shape), effective values returned; audit
- `GET audit?subject_type=&subject_id=&causer_id=&event=&from=&to=&cursor=` → activity rows newest-first (properties included)
- `POST billing/webhooks/{event}/replay` {force:true} → re-run ProcessWebhookEvent on stored payload (idempotency bypassed ONLY when force + audit; default false → duplicate outcome), audit `webhook.replay`
- `GET impersonations` → live impersonation tokens (admin, target, started_at, last_used)
- `POST impersonations/{tokenId}/stop` → delete token, audit stop
- `POST users/{user}/impersonate` → start/replace, returns `{data:{token, target:{id,name,email}, started_at}}`
- `GET ops/horizon-url` → temporarySignedRoute('/horizon', now+60s) — audit `horizon.link`

## API — user plane additions
- `GET /api/v1/orgs/{org}/audit?cursor=` (permission `audit.view` → org_roles owner+admin; 402 Subscription required when entitlement 0; windowed per Q1)
- `GET /me` adds `impersonation: {impersonator_id, impersonator_name, started_at} | null` (from currentAccessToken()->impersonator_id)
- `POST /auth/logout` while impersonating = fine (kills that token only)

## Behaviors
- **Suspend enforcement:** (a) login → 403 `Account suspended.` (AuthenticateUser action check before password verify? AFTER verify — anti-oracle: same as deleted-user flow, but must not tell WHY beyond generic suspension message) ; (b) new middleware `EnsureNotSuspended` on authenticated groups (defense-in-depth for tokens created before suspend — but suspend revokes all tokens; covers restore-then-partial cases); (c) admin plane treats suspended users' sessions as revoked.
- **Impersonation exclusions** — middleware `EnsureNotImpersonating` on: all `/admin/v1/*`, profile password/email/2FA/passkey routes, org destroy + transfer endpoints → 403 `Impersonated sessions cannot perform this action.`
- **Per-request audit while impersonating:** middleware after auth (when token->impersonator_id) → AuditSecurityEvent 'impersonated.request' properties {method, path, status deferred→terminating middleware logs response code} — batched once per request, NOT per route hit.
- **admin_locked subscriptions:** ProcessWebhookEvent outcomes — subscription.updated/deleted/invoice.* targeting an admin_locked sub → row stored, processed_at set, outcome `skipped_locked`, no mutation; cleared by fresh checkout/SwitchPlan by the org itself.
- **Health (S7):** built-in `/up` replaced: public GET /up → `{status:'ok'|'problem', checks:[{name, result}]}` (200/503). Checks: DatabaseCheck, Cache Redis ping, Queue depth roundtrip (dispatch-verify via Health check's queue probe w/ 5s timeout).
- **Horizon (S7):** default path /horizon; custom gate middleware chain: signed temporary URL (query `signature+expires` → resolves causer from token embedded in signed params) OR authenticated super-admin session-less bearer through sanctum; impersonated tokens rejected; every dashboard API pass-through audited? NO — link-creation audited once (volume).

## Permissions & roles
- Org plane: add `audit.view` to catalog; owner wildcard covers; admin gains `audit.view`; member/viewer no.

## Acceptance criteria (EARS) — maps to S-steps
- AC-005.1 (S1) every /admin/v1 route 403s non-super-admin (incl. authenticated user, suspended admin token revoked at suspend time) — extend existing ping test to full-route matrix.
- AC-005.2 (S2) suspend: reason mandatory (422), revokes ALL PAT tokens immediately, login 403 `Account suspended.`, in-flight bearer 401; unsuspend restores login; double-suspend/unsuspend 422; both audited with causer+reason.
- AC-005.3 (S5 wording superseded by Q5) impersonate returns working bearer of target with impersonator_id stored on PAT row; /me exposes block; second start to same pair revokes first token; stop endpoint deletes + audit; impersonated session: admin plane + security + destroy + transfer = 403 `Impersonated sessions cannot perform this action.`, everything else (incl. org reads, billing portal reads/writes) works; every allowed request appends `impersonated.request` audit row.
- AC-005.4 (S4) restore soft-deleted user AND org endpoints work; restored user can log in; restore ≠ unsuspend (suspended_at survives restore).
- AC-005.5 (S5) admin plan change: gateway cancel invoked (FakeGateway assert), local swap + admin_locked, SubscriptionPlanChanged dispatched → 004 inbox rows for owner+admins, audit contains previous plan code + detached gateway id; webhook for detached sub afterward = `skipped_locked`, mirror untouched.
- AC-005.6 (S5) entitlement override: PUT subset merge → maxMembers/maxTeams etc. reflect override; `{}` clears; invalid key/type 422; org audit + billing limits follow override; audit event org.entitlement_override w/ before/after.
- AC-005.7 (S6) audit query filters (subject/causer/event/date) correct; NO update/delete capability (arch test + 404/405 matrix); prune deletes only older-than-retention rows, dry-run flag.
- AC-005.8 (S6/Q1) org audit endpoint: admin 200 windowed rows; member 403; free-plan org 402; business sees ≤365d, pro ≤90d boundary rows excluded.
- AC-005.9 (S7) GET /up returns ok + 3 checks with services healthy; stops answering 200 when queue probe configured fail (test stub check); public (no auth).
- AC-005.10 (S7) /horizon: signed URL grants access ≤60s and logs horizon.link; expired/invalid signature 403; super-admin bearer works; impersonated 403; non-admin 403.
- AC-005.11 (S3/Q3) force delete: hard row gone incl. PATs/memberships/oauth/passkeys/auth_links + prefs; owned-team org → 422 with count in message; after transfer → succeeds; audit rows for the user remain readable and the actor audit survives; suspended-user force-delete allowed.
- AC-005.12 (S2) EnsureNotSuspended middleware blocks even freshly-minted token of a suspended user (edge: admin re-mints before suspend? suspend revokes — test order both ways).
- AC-005.13 webhook replay endpoint: force=false → duplicate outcome, force=true → handler re-runs effects idempotently + audit webhook.replay; unknown event 404.
- AC-005.14 admin login audit on successful sanctum login by super-admin only (`admin.login` event, no spam on normal logins).

## Non-functional
100% coverage; arch tests: admin plane group all have role:super-admin middleware (RouteRules addition), no Activity::/activityLog writes outside allow-list, LogsActivity models declare `getLogName/getDirtyAttributes` safety (no secret attrs — explicit whitelist config); bruno/admin; openapi; horizon signed-link flow documented; feature_list 005 flips at convergence.

## Out of scope (per locked M5)
Staff roles, feature flags, Filament, GDPR export, org-level suspend, per-page admin UI beyond what 003 already ships (plans CRUD done), notification templates admin.

## Micro-decisions baked
1. Activitylog `logs_activity` uses default table; we add composite indexes via our migration patch.
2. `impersonated.request` audit logs on TERMINATE (response code captured).
3. Org restore does not resurrect cascade-deleted children (soft deletes never cascade).
4. Suspend does NOT soft-delete; `status` filters map suspended separately from deleted.
5. Horizon dashboard JS + sanctum: signed-URL is the primary supported path; bearer-session path via existing sanctum guard kept for API parity.
6. `audit.view` added to admin org role (owner wildcard already covers).
