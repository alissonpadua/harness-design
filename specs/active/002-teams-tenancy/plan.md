# Plan 002 — Teams, Orgs & Tenancy

## Namespaces / files (arch-compliant)
- Enums: `app/Enums/OrgType.php` (personal|team), `OrgRole.php` (owner|admin|member|viewer), `MemberStatus.php` (active|suspended)
- Models: `Organization` (SoftDeletes, slug generation on create, resolveByIdOrSlug scope), `OrganizationInvite`, `OrganizationInviteLink` (+ sha256 token pattern cloned from AuthLink — shared trait `HasHashedTokens` in app/Models/Concerns)
- `app/Models/Concerns/BelongsToOrganization.php` — trait: belongsTo + global scope `OrganizationScope` (reads `auth()->user()?->current_organization_id`; no user → `whereRaw('1 = 0')` fail-closed)
- Actions `app/Actions/Org/*`: CreateOrganization, UpdateOrganizationSettings, DeleteOrganization, SwitchOrganization, InviteMember, AcceptInvite, CreateInviteLink, JoinViaInviteLink, RevokeInvite, RevokeInviteLink, UpdateMemberRole, SuspendMember, ReactivateMember, RemoveMember, LeaveOrganization, TransferOwnership
- Listeners: `CreatePersonalWorkspaceOnRegistration` (UserRegistered→), org event classes `OrgCreated, OrgDeleted, MemberInvited, MemberJoined, OwnershipTransferred` (dispatched by Actions; M4 wires mails later — 002 sends invite mail directly via listener for the invite notification to keep S4 e2e now)
- Notifications: `OrgInviteNotification` (link token, accepted endpoint route name)
- Requests under `app/Http/Requests/Org/*`; Data DTOs `app/Data/Org/*`
- Policies per route-action via FormRequest `authorize()` calling `App\Auth\OrgAuthorizer` (single seam): `can(User, Organization|string $orgRef, string $permission): bool` reading `config/org_roles.php`; throws nothing, returns bool; 403 message `This action is unauthorized.`
- `Contracts/OrgEntitlements` + `ConfigOrgEntitlements` (bound in AppServiceProvider; 003 rebinds)
- `Exceptions/SubscriptionRequiredException` → renderer arm 402 `Subscription required.`
- `TwoFactorPolicy` rebinding: `OrgTwoFactorPolicy` decorates config-callback: active membership in org with require_2fa == user's current org → true. Replace `DefaultTwoFactorPolicy` binding (keep class for null-org users).
- Routes: extend `routes/api.php` — `v1/orgs` CRUD/switch/members/invites/invite-links/transfer/leave + public-plane `v1/invites/accept`, `v1/invite-links/join` (auth required, throttled `org-mutations`).

## Migrations
1. organizations (unique slug; owner_id FK nullable during bootstrap? no — owner_id NOT NULL after user)
2. organization_user pivot (indexes org,user unique)
3. organization_invites (unique token_hash, index email, expires_at)
4. organization_invite_links (unique token_hash, max_uses nullable, uses default 0)
5. users: FK constraint on current_organization_id → organizations (nullable, nullOnDelete — org restore admin-only anyway)

## Config files
- `config/org_roles.php` (role → permission[] matrix, spec §)
- `config/tenancy.php` (`limits.*`, `personal_workspace.name_pattern`, `invites.ttl_days=7`, `invite_links.max_ttl_days=30`)
- extend `config/permissions.php` catalog with § list (RolesSeeder auto-picks up)

## Test layout
tests/Feature/M002_Tenancy/{WorkspaceBootstrap,OrgCrud,MembersRoles,Invites,InviteLinks,Isolation,Ownership,TwoFaPolicy}Test.php + helper `orgContext()` in tests/Feature/M002_Tenancy/TenancyHelpers.php (loaded via Pest.php uses for dir) creating user+org+switched state.

## Risks / seams
- Global scope touching auth() inside factories/seeders → scope must no-op when `auth()` has no user AND running console (personal-workspace listener uses explicit org id) — cover by test.
- AcceptInvite email equality vs OAuth-created users (email case) — normalize lower.
- TransferOwnership otp recheck reuse must NOT issue a device token (challenge->verify direct).
- Entitlement seam churn in 003: keep interface minimal (2 methods) so rebind is 1 line.
- FeatureList wording S3 "plan entitlement" is satisfied via the seam; 003 re-verification not required (interface stable).

## Sequencing
T1 enums+migrations+trait/scope (+helpers) → T2 org CRUD+switch+S1 personal workspace listener → T3 authorizer+roles map+members list/role/suspend/remove/leave → T4 invites → T5 invite links → T6 transfer+2FA policy binding → T7 isolation probe suite+convergence.
