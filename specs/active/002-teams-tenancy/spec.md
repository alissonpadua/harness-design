# Spec 002 — Teams, Orgs & Tenancy

Status: DRAFT — awaiting human approval (constitution #1)
Source scope: ../../feature-scope.md § Module 2 v2 (LOCKED) + ADR-0001/0002/0004
Maps to: `harness/feature_list.json` → features["002"].steps S1–S7.

## Problem statement
Everything multi-tenant hangs off this: organizations, memberships with pivot roles, invitations, the single-DB isolation model, and the persisted `current_organization` switch. Also closes spec 001's open loop: personal workspace created on registration.

## Model
- `organizations`: name, slug (internal, unique, generated), type `personal|team`, owner_id, logo_url nullable (file upload deferred to 006), domain nullable, settings: `default_member_role` (enum), `require_2fa` bool, `invite_only` bool, softDeletes, timestamps
- `organization_user` pivot: id, organization_id, user_id, role (OrgRole enum), status (`active|suspended`), invited_by nullable, timestamps; unique(org,user)
- `organization_invites` (email invites): organization_id, email, role, token_hash (sha256, unique), invited_by, expires_at (7d), accepted_at nullable
- `organization_invite_links` (shareable links): organization_id, role, token_hash unique, created_by, expires_at, max_uses nullable, uses int
- Org-scoped domain models use `BelongsToOrganization` trait + global scope resolving `auth()->user()->current_organization_id` (fail-closed: null current org → no rows; super-admin scope bypass arrives with 005)
- Entitlement seam (until 003): `Contracts\OrgEntitlements` → default impl reads `config('tenancy.limits')` (`max_teams`=3, `max_members`=10). 003 rebinds to plan-backed. Violation → 402 `{"message":"Subscription required."}` via new `SubscriptionRequiredException` (003 reuses).
- Role→permission map in `config/org_roles.php` (`resource.action` names); catalog entries added to `config/permissions.php`: `org.view, org.update, org.delete, org.transfer, members.view, members.invite, members.update-role, members.suspend, members.remove, invites.view, invites.revoke, invite-links.manage, billing.view` (billing.* consumed by 003). Viewer: org.view+members.view only. Member adds org-internal read defaults. Admin = everything except transfer/delete. Owner = all. **No implicit inheritance — explicit sets.**

## Acceptance criteria (EARS)

### Workspace bootstrap (S1)
- AC-002.1 WHEN `user.registered` fires THE SYSTEM SHALL create a `personal` workspace named `"{name}'s workspace"`, add the user as `owner` member, and set `users.current_organization_id` to it. Re-running the listener (event replay) SHALL NOT duplicate (idempotent by owner+type).
- AC-002.2 THE personal workspace SHALL refuse: DELETE (403 `Personal workspaces cannot be deleted.`), leave (403), ownership transfer (403), and SHALL not count toward the team entitlement.
- AC-002.3 WHEN a user joins/creates another org THE SYSTEM SHALL NOT change `current_organization_id` automatically; `POST /api/v1/orgs/{id}/switch` sets it (403 `You are not a member of this organization.` otherwise). `GET /api/v1/orgs` lists memberships (role, current flag, org type).

### Org CRUD (S7)
- AC-002.4 WHEN POST `/api/v1/orgs` {name 2..120} THE SYSTEM SHALL create a `team` org (slug from name, collision-suffixed), creator as owner member, and dispatch `org.created` (spec 007 event). IF team count ≥ entitlement `max_teams` THEN 402.
- AC-002.5 GET/PATCH `/api/v1/orgs/{id}` (org.view / org.update): name, logo_url, domain, default_member_role, require_2fa, invite_only. `id` = org id OR slug (both resolve).
- AC-002.6 WHEN DELETE `/api/v1/orgs/{id}` {confirm_text} by owner (org.delete) IF confirm_text !== org name THEN 422 `errors.confirm_text`. Success → soft delete + dispatch `org.deleted`. Soft-deleted org: any access → 404; restore is admin-only (005; no user-facing route).

### Members & roles (S5, S6)
- AC-002.7 GET `/api/v1/orgs/{id}/members` (members.view): user (id, name, email), role, status, joined_at. Role changes: PATCH `.../members/{userId}` {role} (members.update-role): valid enum, cannot change own owner role, cannot demote last owner (409 `The organization must keep an owner.`).
- AC-002.8 WHEN a membership has status `suspended` (members.suspend) THE MEMBER SHALL lose all org access (switch → 403, org-scoped queries → empty, 403 on mutations) while remaining listed; reactivation returns `active`.
- AC-002.9 POST `/api/v1/orgs/{id}/transfer-ownership` {to_user_id, current_password, otp?}: members? no — org.transfer = owner only. `current_password` verified; IF caller has 2FA confirmed THEN `otp` required (reuses TwoFactorChallenge) else 401/422 parity with login. Success: old owner→admin, target→owner, both membership rows updated, dispatch `org.ownership_transferred`. Target must be active member (422). Personal orgs: 403 (AC-002.2).
- AC-002.10 POST `/api/v1/orgs/{id}/leave` (self): 403 `Owners must transfer ownership before leaving.` for last/any owner of team org; members/multi-owner cases: personal org always 403; success removes pivot; if the leaver's `current_organization_id` pointed here → reset to their personal workspace.
- AC-002.11 Role enforcement: every write under an org resolves caller's OrgRole → permission set from `config/org_roles.php`; missing permission → 403 `This action is unauthorized.` (same message as admin plane).

### Invitations (S4)
- AC-002.12 POST `.../invites` {email, role} (members.invite): creates pending invite (7d), sends `OrgInviteNotification` (link carries raw token), dispatches `org.member_invited`. Duplicate email with ACTIVE membership → 422 `errors.email`; re-invite over pending replaces (old token dead). Invite-only-off orgs still accept direct owner/admin adds? NO — direct add without invite is not offered in v1 (invites are the only join path; note in out-of-scope).
- AC-002.13 POST `/api/v1/invites/accept` {token} (authenticated): valid+unexpired+unaccepted → membership with invite's role, invite marked accepted, dispatch `org.member_joined`; email MUST equal the authenticated user's (403 `This invite was issued to a different email address.`); expired/consumed/foreign token → identical generic 403 `This invitation link is no longer valid.`
- AC-002.14 Invite links: POST `.../invite-links` {role, expires_in_days?≤30, max_uses?} (invite-links.manage) → token+URL in response; GET list; DELETE revoke. POST `/api/v1/invite-links/join` {token}: active member → 200 idempotent; else joins with link role; expired/max-uses → generic 403; `uses` increments atomically per NEW member only.
- AC-002.15 GET `.../invites` (invites.view) lists pending (email, role, expires_at — never the token). DELETE `.../invites/{id}` (invites.revoke).

### Org-scoped isolation (S2)
- AC-002.16 THE `BelongsToOrganization` global scope SHALL constrain every query on scoped models to the authenticated user's current organization. IF a request references a scoped row id from another org (even guessed) → 404 (via scoped binding), never 403 (no existence leak).
- AC-002.17 WHEN the same user's `current_organization_id` changes ALL org-scoped reads reflect the new context immediately (no token change needed).

### Security policies (ties 001 AC-001.19)
- AC-002.18 WHEN `organization.require_2fa` is true THE system's `TwoFactorPolicy` binding SHALL require 2FA enrollment for every ACTIVE member against that org's login (403 enroll-first) — enforcement evaluated on the user's CURRENT org at login; unenrolled member of a require-2fa current org cannot log in; switching out is not possible while blocked (no token issued).
- AC-002.19 `invite_only` orgs: invites (email + link) remain the join path; direct membership creation is impossible regardless (AC-002.12 note) — so `invite_only` v1 only suppresses *future* alternative join mechanisms; behavior locked by test asserting no route adds members without invite token.

## Out of scope
Plan/entitlement storage + 402 upgrade (003), logo file upload + signed URLs (006), org-scoped resources beyond membership/invites (later modules add their own scoped tables), SSO, org deletion cascade purges, audit trail (005), webhook fan-out (009 — events are emitted and that's the contract).

## Non-functional
- Every AC ≥ one Pest test in `tests/Feature/M002_Tenancy/`; isolation AC-002.16 gets a dedicated cross-org probe test.
- `check.sh` green per task: strict TDD, 100% app/ coverage, arch rules (Actions write DB directly; events for notifications; named throttle buckets: `org-mutations`).
- feature_list 002 S1–S7 flip only with evidence lines here.

## Micro-decisions flagged for the human (defaults; veto any)
1. Team cap until 003: config `max_teams=3`, `max_members=10`. (propose keep)
2. Slug accepted as org identifier in routes alongside id. (propose keep — nice for clients)
3. Suspended members stay visible in member list (audit-friendly) vs hidden. (propose visible)
4. Leaving resets `current_organization` to personal workspace automatically. (propose yes)
5. Accepting an invite does NOT switch current org (client calls switch explicitly). (propose yes)
