# Tasks 005 — Admin & Ops

TDD (constitution #2); AC = spec.md. Done = S1–S7 evidenced + 100% coverage.

## T0 — Audit hot-fixes folded in (audit run-1, human-approved "go 005")
- [x] T0.1 RED: RolePlaneHardeningTest (T0-1..4) + MailHardeningTest (T0-5) — 4 failed/1 control green
- [x] T0.2 GREEN: UpdateMemberRequest except(Owner); UpdateMemberRoleAction owner-in/out 422 (sole keeps 409); SetMemberStatusAction owner-suspend 403; RemoveMemberAction owner-unremovable regardless of status; BuildsCatalogMail defangs [ ] ( ) ` in headings/lines. 294 green, Larastan clean.

## T1 — Schema + audit core
- [x] T1.1 RED: migrations (PAT impersonator_id FK, users suspended_at/reason, subscriptions admin_locked, organization_entitlement_overrides, activity_log composite indexes); LogsActivity on 5 models with secret-attr exclusion; AuditSecurityEvent writes activity row w/ causer+event+properties
- [x] T1.2 GREEN: migrations + models + app/Audit + config/audit.php

_T1 notes: v5 LogsActivity on the User model leaked sanctum identity across requests (bisected; trait removed — user-state changes audit via explicit events; spec amended). v5 stores model diffs in `attribute_changes` (not v4 `properties`). Trait namespace = Models\Concerns\LogsActivity; options = Support\LogOptions; logger = inLog()/event(). 298 green._

## T2 — User admin (AC-005.1/.2/.4/.11/.12/.14, S1/S2/S4)
- [x] T2.1 RED: full admin-route 403 matrix (non-super-admin incl. suspended-admin-after-revoke); suspend reason-required/422-already; token revoke; login 403 Account suspended.; EnsureNotSuspended both orders; unsuspend; restore preserves suspended_at; force-delete (confirm mismatch 422, owned-team 422, hard-cascade gone, audit survives, suspended user deletable); admin.login audit super-admin only
- [x] T2.2 GREEN: SearchUsers/ShowUser/SuspendUser/UnsuspendUser/RestoreUser/ForceDeleteUser/RestoreOrganization + controllers + middleware + AuthenticateUser suspend check + RouteRules admin-matrix test

## T3 — Impersonation (AC-005.3, S5-corrected/Q5)
- [x] T3.1 RED: start returns bearer w/ impersonator_id; /me block; replace-on-restart; stop deletes+audit; exclusions (admin plane/security/destroy/transfer 403) vs allowed (org reads, billing portal); impersonated.request audit per allowed request (method/path/status); impersonator cannot suspend own token path
- [x] T3.2 GREEN: StartImpersonation/StopImpersonation + EnsureNotImpersonating + AuditImpersonatedRequest + SessionController impersonation block + routes

## T4 — Org admin + plan change + overrides (AC-005.5/.6, S5)
- [x] T4.1 RED: orgs list/detail/restore; plan change (FakeGateway cancel assert, admin_locked, SubscriptionPlanChanged→004 inbox, audit prev-plan+gateway-id, same-plan 422, unknown 404); post-change webhook skipped_locked w/ mirror untouched; entitlement override subset merge + `{}` clear + invalid 422 + PlanOrgEntitlements reflects override (maxMembers/Teams) + audit before/after
- [x] T4.2 GREEN: ListOrgs/ShowOrg/RestoreOrganization/ChangeOrgPlan/SetOrgEntitlementOverride + ProcessWebhookEvent force/admin_locked + PlanOrgEntitlements override layer + routes

## T5 — Audit query + org window + prune (AC-005.7/.8, S6)
- [x] T5.1 RED: filters subject/causer/event/date correct + newest-first cursor; no update/delete route (405 matrix + arch); prune older-than + dry-run + only-retention-rows; org endpoint admin 200 windowed, member 403, free 402, pro≤90/business≤365 boundary
- [x] T5.2 GREEN: QueryAudit/QueryOrgAudit + AuditController + OrgAuditController + AuditPruneCommand + daily schedule + audit.view perm + org_roles

## T6 — Health + Horizon + replay (AC-005.9/.10/.13, S7)
- [x] T6.1 RED: /up public ok 3 checks + 503 when a check down (stub); /horizon signed≤60s grants+audit horizon.link, expired/invalid 403, super-admin bearer 200, impersonated 403, non-admin 403; replay force=false duplicate, true re-runs idempotent + audit webhook.replay, unknown 404
- [x] T6.2 GREEN: health registration + /up route (built-in disabled) + HorizonGate + GenerateHorizonLink + ReplayWebhook + OpsController

## T7 — Surfaces + arch
T7.1 REDT7.1 bruno/admin (users suspend/impersonate/audit/plan-change/override/horizon-url/replay), openapi regen, docs/api-conventions admin note
T7.1 REDT7.2 arch: admin-plane role:super-admin on every route; AuditSecurityEvent sole activity writer; LogsActivity whitelists have no secret attrs

## T8 — Convergence
T7.1 REDT8.1 full gates (check.sh green, 100% cov), suite timing note
T7.1 REDT8.2 verification.md ACs + feature_list 005 S1–S7 flips + README roadmap (006 next)
