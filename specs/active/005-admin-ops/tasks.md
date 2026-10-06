# Tasks 005 — Admin & Ops

TDD (constitution #2); AC = spec.md. Done = S1–S7 evidenced + 100% coverage.

## T1 — Schema + audit core
- [ ] T1.1 RED: migrations (PAT impersonator_id FK, users suspended_at/reason, subscriptions admin_locked, organization_entitlement_overrides, activity_log composite indexes); LogsActivity on 5 models with secret-attr exclusion; AuditSecurityEvent writes activity row w/ causer+event+properties
- [ ] T1.2 GREEN: migrations + models + app/Audit + config/audit.php

## T2 — User admin (AC-005.1/.2/.4/.11/.12/.14, S1/S2/S4)
- [ ] T2.1 RED: full admin-route 403 matrix (non-super-admin incl. suspended-admin-after-revoke); suspend reason-required/422-already; token revoke; login 403 Account suspended.; EnsureNotSuspended both orders; unsuspend; restore preserves suspended_at; force-delete (confirm mismatch 422, owned-team 422, hard-cascade gone, audit survives, suspended user deletable); admin.login audit super-admin only
- [ ] T2.2 GREEN: SearchUsers/ShowUser/SuspendUser/UnsuspendUser/RestoreUser/ForceDeleteUser/RestoreOrganization + controllers + middleware + AuthenticateUser suspend check + RouteRules admin-matrix test

## T3 — Impersonation (AC-005.3, S5-corrected/Q5)
- [ ] T3.1 RED: start returns bearer w/ impersonator_id; /me block; replace-on-restart; stop deletes+audit; exclusions (admin plane/security/destroy/transfer 403) vs allowed (org reads, billing portal); impersonated.request audit per allowed request (method/path/status); impersonator cannot suspend own token path
- [ ] T3.2 GREEN: StartImpersonation/StopImpersonation + EnsureNotImpersonating + AuditImpersonatedRequest + SessionController impersonation block + routes

## T4 — Org admin + plan change + overrides (AC-005.5/.6, S5)
- [ ] T4.1 RED: orgs list/detail/restore; plan change (FakeGateway cancel assert, admin_locked, SubscriptionPlanChanged→004 inbox, audit prev-plan+gateway-id, same-plan 422, unknown 404); post-change webhook skipped_locked w/ mirror untouched; entitlement override subset merge + `{}` clear + invalid 422 + PlanOrgEntitlements reflects override (maxMembers/Teams) + audit before/after
- [ ] T4.2 GREEN: ListOrgs/ShowOrg/RestoreOrganization/ChangeOrgPlan/SetOrgEntitlementOverride + ProcessWebhookEvent force/admin_locked + PlanOrgEntitlements override layer + routes

## T5 — Audit query + org window + prune (AC-005.7/.8, S6)
- [ ] T5.1 RED: filters subject/causer/event/date correct + newest-first cursor; no update/delete route (405 matrix + arch); prune older-than + dry-run + only-retention-rows; org endpoint admin 200 windowed, member 403, free 402, pro≤90/business≤365 boundary
- [ ] T5.2 GREEN: QueryAudit/QueryOrgAudit + AuditController + OrgAuditController + AuditPruneCommand + daily schedule + audit.view perm + org_roles

## T6 — Health + Horizon + replay (AC-005.9/.10/.13, S7)
- [ ] T6.1 RED: /up public ok 3 checks + 503 when a check down (stub); /horizon signed≤60s grants+audit horizon.link, expired/invalid 403, super-admin bearer 200, impersonated 403, non-admin 403; replay force=false duplicate, true re-runs idempotent + audit webhook.replay, unknown 404
- [ ] T6.2 GREEN: health registration + /up route (built-in disabled) + HorizonGate + GenerateHorizonLink + ReplayWebhook + OpsController

## T7 — Surfaces + arch
- [ ] T7.1 bruno/admin (users suspend/impersonate/audit/plan-change/override/horizon-url/replay), openapi regen, docs/api-conventions admin note
- [ ] T7.2 arch: admin-plane role:super-admin on every route; AuditSecurityEvent sole activity writer; LogsActivity whitelists have no secret attrs

## T8 — Convergence
- [ ] T8.1 full gates (check.sh green, 100% cov), suite timing note
- [ ] T8.2 verification.md ACs + feature_list 005 S1–S7 flips + README roadmap (006 next)
