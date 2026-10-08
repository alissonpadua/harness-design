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

## Admin & Ops plane (spec 005)
- All `/admin/v1/*` routes carry, in order: `auth:sanctum`, `not-impersonating` (admins can't act while impersonating), `role:super-admin`, `not-suspended`, throttle. Enforced by an arch test (`tests/Architecture/RouteRulesTest.php`).
- Destructive lanes: force-delete requires typed `confirm_text` equal to the target email; suspend/unsuspend/plan-change/override/replay/impersonation all write `activity_log` through `AuditSecurityEvent` (the sole writer channel — also arch-guarded).
- Org-facing audit: `GET /api/v1/orgs/{org}/audit` (owner+admin via `audit.view` permission), windowed by plan `audit_retention_days`; free plan → 402. Platform trail `/admin/v1/audit` is filtered + cursor-paged; pruned by `audit:prune` (365d default, `AUDIT_RETENTION_DAYS`).
- Horizon: `GET /admin/v1/ops/horizon-url` → 60s signed `/horizon` entry; entry sets an 8h HMAC pass cookie. Bearer super-admin works directly. All revoked while impersonating.
- Admin plan changes cancel + lock the remote subscription (`admin_locked`): late webhooks record but skip (`skipped_locked`); the org's own billing actions clear the lock. `POST /admin/v1/billing/webhooks/{event}/replay` force re-runs a recorded event's idempotent handler.

## Security & API plane (spec 006)
- Response headers on every endpoint: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, CSP `default-src 'none'; frame-ancestors 'none'` (docs/* exempt — deviation D3), `X-Request-Id` (sanitized ULID fallback, also injected into every log record's extra). HSTS only over HTTPS. CORS: `FRONTEND_ORIGINS` comma allowlist on `api/*` + `admin/*`, credentials always off.
- Throttle buckets are named, defined ONLY in `AppServiceProvider`: tight auth buckets + `tokens-mutations` (10/min/user) + `plan-api` — per-ORGANIZATION ceiling from `PlanOrgEntitlements::effective()` (cached 60 s, key `plan-rl:{org}`; override/plan writes invalidate). All 429s: standard envelope + `Retry-After`.
- Integration tokens: org-scoped PATs (`kind=integration`) with abilities that MUST be permission names from `config/permissions.php`. Plaintext shown once at `POST /api/v1/orgs/{org}/tokens` (2FA-on-account gate; passkey counts). Enforcement is central: every `OrgScopedRequest` permission check additionally requires the acting integration token to carry that ability (device tokens unchanged). Missing → 403 naming the ability.
- Uploads: the sole surface is `PUT /api/v1/orgs/{org}/logo` (finfo-sniffed png/jpeg/webp ≤2 MiB, GD re-encoded to webp ≤1024px, private disk). Public read ONLY via `GET /api/v1/public/orgs/{identifier}/logo` (302 signed URL on s3, streamed elsewhere). Arch tests ban other upload surfaces + mass-assignment shortcuts.
- Kill-switch: `PUT /admin/v1/settings/registrations` `{open}` (audited `settings_change`); when closed, `POST /auth/register` + oauth NEW-user creation → 403 `Registrations are closed.`; existing identities unaffected. Backed by spatie/laravel-settings (first typed settings group; module 008 builds the rest).
