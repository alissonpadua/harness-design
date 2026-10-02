# Spec 001 — Identity & Access

Status: DRAFT — awaiting human approval (constitution #1)
Source scope: ../../feature-scope.md § Module 1 v1.2 (LOCKED)
Maps to: `harness/feature_list.json` → features["001"].steps S1–S8.

## Problem statement
Every authenticated surface of the boilerplate (tenancy, billing, admin) consumes identity. This spec delivers registration, login (password, OAuth, passkeys, magic link), device tokens, 2FA, profile lifecycle, and the global role plane — as JSON APIs only.

## Domain model
- `users`: name, email (unique), password (Argon2id), email_verified_at, softDeletes, locale/timezone (defaults, M8 columns created here), `two_factor_*` columns, `current_organization_id` nullable FK (filled in 002; null-tolerant until then)
- `personal_access_tokens` (Sanctum) + `device_type` enum (`web|mobile|desktop|cli`) + `name`, `last_used_at`
- `oauth_accounts`: user_id, provider (`google|facebook`), provider_id, provider_email, unique(provider, provider_id)
- `passkeys` (WebAuthn credentials): user_id, credential_id, type, transports, attestation/format columns, name, last_used_at
- Standard: `password_reset_tokens`, sessions table (already in skeleton), `jobs`, `cache`
- Roles (spatie): **global plane only** — seeded roles `super-admin`, `user`; permission catalog `resource.action`; `*` wildcard = super-admin. Team-plane roles arrive in 002 (separate guard, pivot-based).

## Acceptance criteria (EARS)

### Registration & verification (S1)
- AC-001.1 WHEN POST `/api/v1/auth/register` with valid name+email+password (min 10, confirmed) THE SYSTEM SHALL create the user **unverified**, dispatch `user.registered`, issue a token for the declared `device_type`, and queue a verification email containing a signed, expiring payload (≤60 min).
- AC-001.2 WHEN GET/POST `/api/v1/auth/verify-email/{id}` with valid signature THE SYSTEM SHALL set `email_verified_at`; invalid/expired signature → 403 envelope. Re-verification request (`POST /api/v1/auth/email/verify/resend`) is rate-limited and idempotent.
- AC-001.3 IF an endpoint marked `verified`-required (email change, password change, 2FA management, token creation for integration tokens) is called by an unverified user THEN THE SYSTEM SHALL return 403 `{"message":"Your email address is not verified."}`. Ordinary authenticated reads and profile GET work unverified.
- AC-001.4 WHEN register is called with an existing email THE SYSTEM SHALL return the same generic success-neutral response shape as acceptance (no account enumeration) while sending no new email when unverified-only… (anti-enumeration: timing-normalized, generic message) — 422 on strict mode is FORBIDDEN.

### Login & device tokens (S2, S7)
- AC-001.5 WHEN POST `/api/v1/auth/login` {email, password, device_type} with valid credentials THE SYSTEM SHALL return exactly one bearer token bound to that `device_type`, revoking any previous token of the **same type only** and firing `auth.other_login.detected` when another device type had an active session.
- AC-001.6 IF credentials are invalid THEN THE SYSTEM SHALL return 401 `{"message":"These credentials do not match our records."}` identical for wrong-email and wrong-password, throttled (see AC-001.24).
- AC-001.7 IF the account is soft-deleted (or suspended, when 005 lands) THEN login/refresh/magic-link/oauth-callback SHALL all return the 401 generic-credentials envelope; no endpoint reveals the account exists. Restore is admin-only (005) — no user-facing path.
- AC-001.8 THE middleware SHALL persist `last_used_at` (throttled writes, ≥5 min between updates) and expose GET `/api/v1/auth/sessions` (id, device_type, ip, user-agent, created/last_used, `current` flag). DELETE `/api/v1/auth/sessions/{id}` revokes that token (current token → also invalidates caller). POST `/api/v1/auth/logout` revokes only the current token; POST `/api/v1/auth/logout-all` revokes all of the user's tokens.
- AC-001.9 WHEN password changes or email changes succeed THE SYSTEM SHALL revoke ALL tokens except the one performing the change, and fire the matching event (`auth.password_changed` / `auth.email_changed`).

### Password reset & magic link (S1/S6 lifecycle)
- AC-001.10 POST `/api/v1/auth/forgot-password` always returns 202 generic acceptance. When the account exists: queued reset mail with single-use signed payload (≤60 min). POST `/api/v1/auth/reset-password` {email, payload, password} rotates the password, revokes other tokens (AC-001.9), and invalidates all outstanding reset/verification payloads (via password-hash-derived signature key — Laravel default).
- AC-001.11 POST `/api/v1/auth/magic-link/request` {email, device_type} sends a single-use signed token (≤15 min); POST `/api/v1/auth/magic-link/consume` exchanges it for a device token (same revocation semantics as AC-001.5) and marks the email verified. Reuse of a consumed/expired token → 403.

### OAuth (S3)
- AC-001.12 Providers: **google, facebook only** (config-driven; any other provider name → 404 envelope). GET `/api/v1/auth/oauth/{provider}/redirect` returns `{url}` for the provider consent screen (stateless PKCE/state enforced). POST `/api/v1/auth/oauth/{provider}/exchange` {code, device_type} completes the flow and returns a device token (same semantics as AC-001.5).
- AC-001.13 WHEN an OAuth identity is exchanged THE SYSTEM SHALL: match `oauth_accounts(provider, provider_id)` → login; else match verified email → link new account; else create user (email taken from provider, `email_verified_at` set only when the provider asserts verification for Google; for Facebook treat email as unverified until confirmed by provider flag). Creation dispatches `user.registered`.
- AC-001.14 IF the matched user is soft-deleted → AC-001.7 generic denial. IF a password was never set, the user may set one via forgot-password (creates credential for the existing account, indistinguishable response).

### Passkeys (S4)
- AC-001.15 Authenticated POST `/api/v1/auth/passkeys/register/challenge` → registration options; POST `.../register` {name, attestation} stores the credential (max 10 per user → 422 beyond). Credentials are deletable via `GET/DELETE /api/v1/auth/passkeys/{id}`.
- AC-001.16 Public POST `/api/v1/auth/passkeys/assert/challenge` {email?} → assertion options (discoverable when email omitted, using allowCredentials narrowing); POST `.../assert` {device_type, assertion} verifies signature, counter, and issues a device token (AC-001.5 semantics). Failure → 401 generic.

### 2FA (S5)
- AC-001.17 Authenticated (verified email) POST `/api/v1/auth/2fa/enroll` → TOTP secret + provisioning URI + QR data URL (never persisted until confirmed). POST `/api/v1/auth/2fa/confirm` {code} activates (sets `two_factor_confirmed_at`, persists encrypted secret, returns 8 one-time recovery codes — shown once). POST `/api/v1/auth/2fa/disable` {password} removes 2FA and revokes recovery codes.
- AC-001.18 IF user has confirmed 2FA THEN login and magic-link and OAuth-exchange (when password re-auth implied) require `otp` field: valid TOTP (±1 window) → success; recovery code → success + marks that code consumed (hash-compared, single use); missing/invalid → 401 `{"message":"Two factor authentication is required."}` / 422 field error respectively — no secret material in responses.
- AC-001.19 THE 2FA enforcement SHALL expose a `force_2fa` policy hook (config callback keyed per user) that 002's org security settings can bind; when active, all sessions except the current device are revoked upon enabling, and login without enrolled 2FA redirects to an enroll-first flow (403 `{"message":"Two factor authentication is mandatory for your organization.","errors":{"two_factor":["required"]}}`).
- AC-001.20 Events fired: `auth.two_factor_enabled`, `auth.two_factor_disabled`, `auth.recovery_code_used`.

### Profile (S6)
- AC-001.21 GET/PUT `/api/v1/profile` (name, locale, timezone; validated enums for locale/timezone — timezone via `DateTimeZone::listIdentifiers`). Email change PUT `/api/v1/profile/email` {email, password}: requires verified current email + password re-confirm; queues verification to the NEW email, notifies OLD email, sets a pending-email marker — the change finalizes only on new-email verification (tokens/signature bound to old hash). Password change PUT `/api/v1/profile/password` {current, new, confirmation} enforces AC-001.9.
- AC-001.22 POST `/api/v1/profile/delete-account` {password} → soft delete + all tokens revoked; subsequent login attempts follow AC-001.7.

### Roles & admin plane (S8)
- AC-001.23 THE seeder SHALL create `super-admin` role with `*` wildcard and `user` default role assigned at registration. `/admin/v1/*` route group SHALL be gated by `role:super-admin` (verified via a protected ping route test); permission middleware uses `resource.action` names; no implicit inheritance — `user` has zero admin permissions.

### Cross-cutting (M6 carve-in)
- AC-001.24 Rate buckets (`config/rate-limiting.php`, Redis, keyed by ip+email where sensible): login 5/60s, register 3/60s, forgot/magic-request 3/60s, oauth/exchange 10/60s, passkeys/assert 10/60s — 429 standard envelope. All auth bodies exclude secrets from logs (Laravel default redaction + assertion).
- AC-001.25 All mutations use FormRequests; all Actions dispatch events for any mail (listeners send Notification classes; M4 wraps this catalog later — 001 creates the first 6 catalog-ready notification classes: verification, email-change-old, email-change-new, reset, magic-link, welcome).
- AC-001.26 Token TTL: device tokens never expire by default (revocation-driven), integration tokens (006) will set explicit expiry — schema must keep `expires_at` nullable column (Sanctum default) with comment.

## Out of scope
Personal workspace creation (002 listens to `user.registered`), org roles (002), integration tokens + abilities (006), preferences/notification catalog UI (004), suspension/restore admin endpoints (005), SSO/SAML.

## Non-functional
- Argon2id (`config/hashing`), timing-safe comparisons, all identifiers ULID/uuid for public exposure where enumerable.
- Every AC = at least one Pest test in `tests/Feature/M001_Identity/`; anti-enumeration and revocation tests are mandatory.
- Full `check.sh` green per task; arch rules stay green (mail only from listeners).

## Micro-decisions flagged for the human (defaults chosen; veto any)
1. Magic-link **marks email verified** on consume (it proves mailbox control). (proposed: yes, keep)
2. OAuth-created users get `email_verified_at` only when provider guarantees verified email (Google yes w/ `email_verified` claim, Facebook conservative-no). (proposed: keep)
3. Max 10 passkeys per user. (proposed: keep)
4. Password min length 10 chars, no complexity theater, future zxcvbn optional. (proposed: keep)
5. Unverified users CAN log in and read (only sensitive actions blocked, AC-001.3). Alternative: hard-block login until verified. (proposed: keep soft)
