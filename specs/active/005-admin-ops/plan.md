# Plan 005 — Admin & Ops

## Dependencies
Installed: laravel/horizon 5.50, spatie/laravel-activitylog 5, spatie/laravel-health (+ config published). No further composer needed.

## Layout
- Migrations: PAT `impersonator_id`; users `suspended_at`/`suspend_reason`; subscriptions `admin_locked`; `organization_entitlement_overrides`; activity_log index patch.
- Models w/ LogsActivity: User, Organization, OrganizationMembership, Plan, BillingSubscription (whitelisted dirty attrs only).
- `app/Audit/` — `SecurityEvent` enum/consts + `AuditSecurityEvent` action (the sanctioned writer) + `Concerns/AuditableModel`.
- `app/Actions/Admin/*` — SuspendUser, UnsuspendUser, RestoreUser, ForceDeleteUser, RestoreOrganization, ChangeOrgPlan, SetOrgEntitlementOverride, StartImpersonation, StopImpersonation, ReplayWebhook, GenerateHorizonLink, PruneAudit, QueryAudit, SearchUsers, ShowUser, ListOrgs, ShowOrg, QueryOrgAudit.
- `app/Http/Middleware/` — EnsureNotSuspended, EnsureNotImpersonating, AuditImpersonatedRequest (terminate), HorizonGate (signed OR sanctum super-admin, NOT impersonated).
- `app/Http/Controllers/Api/Admin/*` — UserController, OrganizationController, PlanOverrideController, AuditController, ImpersonationController, OpsController (horizon-url, webhook replay).
- `app/Http/Controllers/Api/Notifications/OrgAuditController` (org-facing windowed audit).
- Requests under `app/Http/Requests/Admin/*` + `app/Http/Requests/Org/AuditIndexRequest`.
- `app/Console/Commands/AuditPruneCommand.php` (+ daily schedule); `bootstrap/app.php` — disable built-in health, add public `/up`.
- Health checks: add `Spatie\Health\Facades\Health` registration in AppServiceProvider (DatabaseCheck, RedisCache check, Queue check `PingQueue` custom to avoid 5s hang in tests via faked driver).
- config/audit.php: retention_days, impersonation.exclusions map.
- OrgEntitlements: `PlanOrgEntitlements` now layers org override (single seam, no churn to callers); ProcessWebhookEvent gains `admin_locked` → `skipped_locked`.
- /me: SessionController add impersonation block from currentAccessToken.
- Route groups in routes/admin-v1.php; middleware aliases registered.

## Impersonation token model
PAT.abilities = `['impersonation:*']`; PAT.impersonator_id = admin. `currentAccessToken()` drives /me block + EnsureNotImpersonating (checks impersonator_id !== null). One-active enforced by deleting prior impersonation PATs for (impersonator,target) on start.

## Activitylog safety
`Activitylog::useLogName('audit')`; `getSubjectName` default; explicit `auditableConfig` to NOT log `password`, `two_factor_secret`, `remember_token`, `token_hash`, `gateway_*_id` (values) — log changed KEYS only for subscription? Keep: log all whitelisted attrs' old/new EXCEPT secret set; force-delete logs snapshot of id/email only.

## Webhook replay
ReplayWebhook(force): look up WebhookEvent by id, run ProcessWebhookEvent->handle with `bypassIdempotency: $force` — need to refactor handle() to accept force (currently returns duplicate on seen id; add param). Audit.

## Health queue check in CI
Queue check pings redis; in tests we stub by registering only db+cache OR guard via app.environment; use `spatie/laravel-health` with a custom `QueueHealthCheck` that dispatches a no-op job to `testing` connection when env==='testing' to stay green without redis.

## Horizon signed route
`URL::temporarySignedRoute('horizon.entry', now()->addSeconds(60), ['_token'=>...])` — HorizonGate verifies signature OR sanctum super-admin; embeds admin id in query (signed) so we can audit causer. Path `/horizon` group uses HorizonGate (replaces Horizon default web auth via `Horizon::auth` returning true + our middleware does the gate).

## Risks
- Horizon dashboard static assets need `horizon:install` published routes — verify /horizon serves in dev with signed link.
- activitylog on User restore/force-delete double-logs; disable model events on force delete (delete hard) to avoid noise (audit the event explicitly instead).
- EnsureNotSuspended on admin plane must not deadlock suspend/unsuspend handlers (they run as admin, target suspended — fine, gate checks AUTHENTICATED user).
- Performance: impersonated.request audit per write → could flood; log ALL impersonated requests but keep properties tiny; acceptable for audit completeness (S3 requirement).

## Sequencing
T1 migrations+models+audit core → T2 user admin CRUD+suspend/restore/force-delete+login block → T3 impersonation (start/stop/claims/exclusions//me) → T4 org admin (list/detail/restore/plan-change/override) + webhook skipped_locked + PlanOrgEntitlements override layer → T5 audit query + org-facing windowed + prune → T6 health /up + Horizon gate + replay → T7 bruno/openapi/docs + arch route-matrix tests → T8 convergence+flips.
