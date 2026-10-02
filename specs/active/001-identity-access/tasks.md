# Tasks 001 — Identity & Access

One task = one session, TDD (constitution #2). AC = spec.md ids.

## T1 — Migration pack + packages + user factory groundwork (enables all)
- [x] T1.1 require sanctum, socialite, webauthn, google2fa+bacon, spatie/permission (versions in verification); publish + edit migrations (users columns, oauth_accounts, passkeys, tokens w/ device_type)
- [x] T1.2 RED: model/factory trait tests — User softDeletes, encrypted 2FA casts, hash driver argon2id assert
- [x] T1.3 GREEN + seeder: super-admin (`\*`) + user roles via RolesSeeder (idempotent, pgsql-verified); event dispatch itself lands in T2 with registration

## T2 — Register + verify + verified-gate (AC-001.1–.4) — S1
- [x] T2.1 RED: register success-shape; verification email queued w/ signed payload; verify ok/expired/tampered; resend idempotent+limited; unverified-can-read vs verified-only-403 matrix; enumeration parity test
- [x] T2.2 GREEN: RegisterUserAction, VerifyEmailAction, `auth_links` table+codec, EnsureEmailIsVerified middleware, 2 notifications, routes, rate bucket
- [x] T2.3 event: `user.registered` fires exactly once

## T3 — Login + device tokens + sessions + logout-all (AC-001.5–.9 core) — S2
- [x] T3.1 RED: same-type replace / other-type survives; other_login.detected only when others existed; identical 401 for wrong email vs wrong password; last_used throttle; sessions list w/ current flag; DELETE session; logout vs logout-all
- [x] T3.2 GREEN: LoginAction, SessionResource, TrackTokenUsage middleware, RevokeDeviceTokensAction
- [x] T3.3 soft-deleted login block (AC-001.7) w/ isSuspended stub

## T4 — Password forgot/reset + magic link (AC-001.10–.11)
- [x] T4.1 RED: generic 202 always; reset rotates+revokes+invalidates outstanding links; magic consume issues token+verifies email+single-use; expired/tampered 403
- [x] T4.2 GREEN: both flows (token broker + auth_links type=magic), notifications, buckets

## T5 — 2FA (AC-001.17–.20) — S5
- [x] T5.0 SPIKE (DONE EARLY with T1.1): asbiin/laravel-webauthn 6.0.0 installs+publishes clean on L13 — no fallback needed
- [ ] T5.1 RED: enroll/confirm/active/disable incl. recovery codes shown-once+single-use+hash-stored; login matrix (with/without/missing/invalid otp); force_2fa hook 403; events
- [ ] T5.2 GREEN: TOTP service, RecoveryCodeCodec, login/otp integration, config policy closure

## T6 — Passkeys (AC-001.15–.16) — S4
- [ ] T6.1 RED: register challenge/attest (+max 10, delete); assert discoverable+narrowed; token issuance equals AC-001.5 semantics; bad signature 401
- [ ] T6.2 GREEN with package ceremonies; fixture keys stored under tests/Fixtures (deterministic CBOR)

## T7 — OAuth google/facebook (AC-001.12–.14) — S3
- [ ] T7.1 RED: provider whitelist 404; redirect shape; exchange new-user/verified-email-link/deleted-deny; google-verified vs facebook-unverified flag rule; user.registered on creation; generic denial parity
- [ ] T7.2 GREEN: `App\Auth\OAuth\{Provider}Normalizer`, CompleteOAuthAction, redirect+exchange routes, state check, buckets

## T8 — Profile: update/email-change/password-change/delete-account (AC-001.21–.22, .9) — S6
- [ ] T8.1 RED: locale/timezone validation; email change pending flow + old-notify + finalize-on-verify + hash-bound signatures; password change revokes others but not self; delete-account soft-deletes+revokes all
- [ ] T8.2 GREEN: ProfileController/actions/notifications (change-password email, email-change pair)

## T9 — Roles & admin gate + integration surface prep (AC-001.23, .26) — S8
- [ ] T9.1 RED: `/admin/v1/ping` 403 for `user`, 200 for super-admin; `resource.action` middleware examples; expires_at column documented
- [ ] T9.2 GREEN: spatie config, admin route file + bootstrap registration, permission catalog config, seeder assertions

## T10 — Cross-cutting hardening (AC-001.24–.25) + convergence
- [ ] T10.1 Rate-limit config centralization test (every auth route has a bucket — reflection scan of middleware on route group)
- [ ] T10.2 Notification shape tests for all 6 classes; arch-green (no ->notify( in Actions)
- [ ] T10.3 openapi regeneration; src/docs updates (api-conventions auth header section); flip feature_list 001 S1–S8 with evidence; move nothing (001 stays active until human confirms + convergence note)

## Definition of done (001)
feature_list["001"] S1–S8 each have verification.md lines; check.sh green; PR proposed to human.
