# API Conventions (agent contract — deviations need ADR)

- Base: `/api/v1/*` (public app), `/admin/v1/*` (super-admin only). Version in path. `Accept: application/json` required.
- Success: Laravel Resource shape. Errors: `{"message": "...", "errors": {"field": ["..."]}}` with proper status (422 validation, 401, 403, 402 `SubscriptionRequired`, 429 + `Retry-After`).
- Pagination: cursor default (`?cursor=` + `?per_page` max 100); page params on admin lists.
- Timestamps: UTC ISO-8601 always (`Z`); clients format.
- Requests carry `X-Request-Id` (echoed + logged). Org context: none — persisted `current_organization` (switch via endpoint).
- Docs: Scramble → `/docs/api` (`/docs` redirects) covering BOTH `/api/*` and `/admin/*`; gated by `DOCS_PUBLIC` (EnsureDocsVisible 404s otherwise). Every endpoint documented + covered by feature test (010 freshness gate).

## Auth plane (spec 001)
- Bearer tokens (Sanctum, DB-backed). One active token per `device_type` ∈ `web|mobile|desktop|cli`; re-login replaces same type only. Integration tokens with abilities: spec 006.
- Public login plane: register, verify-email, confirm-email, resend, forgot/reset-password, magic-link request/consume, login, oauth `/{google|facebook}` redirect+exchange, passkey authenticate(+options). All throttled by named buckets defined ONLY in `AppServiceProvider`.
- Verification gate: unverified account → 403 `Please verify your email address.` on every login surface; register/magic/oauth-create answer without tokens.
- Denial parity: wrong password / unknown email / soft-deleted / failed passkey-or-oauth assertion → byte-identical 401 `These credentials do not match our records.`
- 2FA: `otp` on login/magic/oauth-exchange when confirmed; TOTP ±1 window; recovery codes single-use. Mandatory-2FA org policy (`TwoFactorPolicy`, spec 002) → 403 enroll-first. Passkey assertion satisfies 2FA outright.
- Security events (no secret material in payloads): `user.registered`, `auth.other_login.detected`, `auth.password_changed`, `auth.email_changed`, `auth.two_factor_enabled|disabled`, `auth.recovery_code_used`.
- Admin plane `/admin/v1/*`: `auth:sanctum` + `role:super-admin` + throttle; `user` role always 403 `This action is unauthorized.` Permission names come only from `config/permissions.php` (`resource.action`) — no string literals.
