# Tasks 002 — Teams, Orgs & Tenancy

One task = one session, TDD (constitution #2). AC = spec.md ids. Definition of done: S1–S7 evidenced in verification.md.

## T1 — Foundations: enums, migrations, BelongsToOrganization scope
- [ ] T1.1 RED: SchemaContract-style test — organizations/organization_user/organization_invites/organization_invite_links tables + columns/uniques; users.current_organization_id FK
- [ ] T1.2 GREEN: migrations + enums (OrgType, OrgRole, MemberStatus) + HasHashedTokens concern
- [ ] T1.3 RED→GREEN: BelongsToOrganization trait + OrganizationScope (fail-closed for null org, no-op on console) — unit tests with two orgs

## T2 — Personal workspace + org CRUD + switch (S1, S3, S7)
- [ ] T2.1 RED: registration creates personal ws idempotently + current set; delete/leave/transfer personal 403; team create (slug, owner, org.created event); max_teams 402; PATCH settings; delete confirm_text; switch membership 403; orgs list with current flag
- [ ] T2.2 GREEN: CreatePersonalWorkspaceOnRegistration listener + actions + routes + SubscriptionRequiredException + renderer arm + tenancy config + entitlement seam

## T3 — Members & role enforcement (S5)
- [ ] T3.1 RED: config/org_roles.php matrix test; members list/role-update (self-owner guard, last-owner 409); suspend/reactivate access-loss matrix; remove; leave (owner 403, reset-current behavior); 403 'This action is unauthorized.' coverage per role
- [ ] T3.2 GREEN: OrgAuthorizer seam + requests authorize() + actions

## T4 — Email invites (S4)
- [ ] T4.1 RED: invite create/duplicate/re-invite-replaces; notification queued with token; accept happy/different-email/expired/consumed/foreign; list hides tokens; revoke; org.member_invited/joined events
- [ ] T4.2 GREEN: OrganizationInvite model/actions/routes + OrgInviteNotification + throttle

## T5 — Invite links (S4)
- [ ] T5.1 RED: create/list/revoke; join new/idempotent-member/expired/max-uses; uses counter atomic; token never listed
- [ ] T5.2 GREEN: OrganizationInviteLink + actions + endpoints

## T6 — Ownership transfer + org 2FA policy (S6, ties 001 AC-001.19)
- [ ] T6.1 RED: transfer happy (old→admin, new→owner, event), wrong password 401? (parity: password recheck) , otp required when caller 2FA, target-not-member 422, personal 403; OrgTwoFactorPolicy: require_2fa org member login blocked enroll-first 403, non-member unaffected, current-org switching unaffected while blocked
- [ ] T6.2 GREEN: TransferOwnershipAction + policy class + rebinding (default org binding)

## T7 — Isolation probe suite + convergence (S2)
- [ ] T7.1 RED→GREEN: cross-org matrix — every org-scoped endpoint/row: user B with guessed ids of org A → 404/403 per design (no existence leak: 404 for scoped reads/mutations); scope reflects switch instantly
- [ ] T7.2 openapi regeneration; bruno/orgs folder; audit named throttle buckets (reuse 010 reflection test passes automatically); flip feature_list 002 S1–S7 with evidence; convergence notes

## Definition of done (002)
S1–S7 evidenced; check.sh green; residual deferred items recorded (as 001 did).
