# Spec 006 — Security & API (v2 scope, hardened)

Status: DRAFTED (Q1–Q6 human-answered 2026-10-07) · Depends: 001 (auth/throttle buckets/RequestId seed), 002 (tenancy `users.current_organization_id`, permission catalog), 003 (plan entitlements), 005 (`PlanOrgEntitlements::effective()` incl. overrides)

## Decisions (human answers, one at a time)
- **Q1=A** — Kill-switch ships a MINIMAL typed-settings foundation in 006: spatie/laravel-settings + one group `RegistrationsSettings{open}` + admin toggle endpoint. Full settings CRUD / `GET /settings/public` stays in module 008.
- **Q2=C** — Integration-token creation gate = ACCOUNT-level `two_factor_confirmed_at !== null` (no time-window freshness). HUMAN OVERRIDE of feature_list step 4 wording "2FA-fresh session" — recorded as deviation D1 (mirrors 005-Q5 treatment).
- **Q3=A** — A registered passkey satisfies the gate (001 precedent: passkey = 2FA-grade). Gate ⇔ `two_factor_confirmed_at !== null || webauthn_keys()->count() > 0`.
- **Q4=B** — Logo: bytes stored on the PRIVATE default disk (S3/rustfs or local in tests); the ONLY public surface is `GET /api/v1/public/orgs/{identifier}/logo` (no auth) → 302 to a short-lived S3 signed URL, or streamed bytes on the local driver. No public-read ACLs anywhere. `organizations.logo_url` = the app route URL. (Supersedes earlier tentative A.)
- **Q5=A** — Plan `api_rate_limit_per_min` is enforced PER ORGANIZATION: limiter key = acting org id (device token → `user.current_organization_id`; integration token → token's org). Value from `effective()` (005 override layer honored), cached 60 s. (Corrects earlier tentative B.)
- **Q6=A** — Integration-token abilities MUST be permission names from `config/permissions.php` catalog (422 otherwise). Enforcement middleware maps route→required permission; missing ability → 403.

## Contract

### Headers (every response, incl. errors)
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer-when-downgrade`… **locked:** `no-referrer`.
- `Strict-Transport-Security: max-age=31536000; includeSubDomains` — ONLY when request is over HTTPS (`$request->secure()`), so dev http stays clean.
- CSP minimal on JSON API/admin responses: `default-src 'none'; frame-ancestors 'none'`. EXEMPT: `/docs` + swagger UI assets (HTML needs styles/scripts) — exemption list in config; the api-plane rule is arch-tested.
- `X-Request-Id`: reuse incoming header ONLY if it matches `^[A-Za-z0-9_\-]{1,64}$` (sanitize/truncate-else-generate ULID); echo on response; pushed into log context (monolog `withProcessor` adding `request_id`) AND into `activity_log`-adjacent request logs.

### CORS
- `config/cors.php`: `allowed_origins = explode(',', env('FRONTEND_ORIGINS',''))`, `allowed_methods [GET,POST,PUT,PATCH,DELETE,OPTIONS]`, `allowed_headers [Authorization,Content-Type,Accept,X-Request-Id,X-CSRF-Token]`, `credentials false` (bearer-only API), `paths: ['api/*','admin/*','up','horizon']`… horizon exempt (cookie-based, same-origin only) — **locked: `paths ['api/*','admin/*']`**.
- Unlisted Origin → no CORS headers; OPTIONS preflight from listed origin → 204 with headers.

### Throttles (S2)
- Existing named buckets stay (001): auth-register, auth-resend, auth-verify, auth-login, auth-otp, auth-reset, admin-generic, org-mutations, billing, billing-webhook. 006 ADDS: `tokens-mutations` (10/min/user).
- All 429s: standard envelope (`message: "Too Many Requests."`) + integer `Retry-After` header (framework-provided — asserted in tests, not assumed).
- NEW plan limiter `plan-api`: applied to the authenticated `api/*` org-scope group; per-org key (Q5). Free plan default from PlansSeeder entitlement (already exists: value e.g. 60 — read from plan row, never hard-coded). 429 → same envelope + Retry-After. No metering/DB writes.

### Integration tokens (S3/S4)
- Storage: `personal_access_tokens` gains `organization_id` (nullable FK) + `kind` (`device|integration`, default device; existing rows backfilled device).
- `POST /api/v1/orgs/{org}/tokens` `{name, abilities[]}` → 201 `{data:{id,name,abilities,token}}` — plaintext shown EXACTLY ONCE (never listable again). Device tokens keep 001 replace-same-device semantics; integration tokens are long-lived (no expiry), EXEMPT from one-per-device rule.
- `GET /api/v1/orgs/{org}/tokens` → metadata only. `DELETE /api/v1/orgs/{org}/tokens/{token}` → revoke (idempotent 204).
- 2FA gate (Q2/Q3): else 403 `Two-factor authentication is required to create integration tokens.`
- Abilities: every entry MUST be a key of `config('permissions.catalog')` (422 `abilities.*`); unknown → 422 listing invalid ones.
- Enforcement: routes opt in via middleware alias `ability:` — v1 applies it to: `GET {org}/members` (`members.view`), invite create/list (`members.invite`), org audit feed (`audit.view`). Device-token requests bypass ability middleware entirely (role/permission plane unchanged). Integration token missing required ability → 403 `This token lacks the required ability: {name}.` Audit: none per-request (volume); creation/revocation audited (`integration_token_created|revoked`).
- Suspended users' integration tokens: revoked by suspend action (005 token eviction already deletes ALL tokens of user's… NO — integration tokens are org-owned, not user-owned: suspend does NOT kill org tokens; document in spec).

### Logo upload (S5)
- `PUT /api/v1/orgs/{org}/logo` multipart field `logo` (owner/admin permission `org.settings`):
  1. size ≤ 2 MiB (422),
  2. finfo-detected mime ∈ {image/png, image/jpeg, image/webp} (client-declared type ignored),
  3. decode via GD; re-encode to WEBP (quality 82) at ≤ 1024×1024 preserving aspect ratio (422 `The uploaded image could not be decoded.` on failure),
  4. store `orgs/{org_id}/logo.webp` on PRIVATE default disk, overwrite; record path+hash on organization.
- `GET /api/v1/public/orgs/{identifier}/logo` (no auth; id-or-slug; 404 when absent): s3 driver → 302 `temporaryUrl` 5 min; local → streamed response w/ correct Content-Type + immutable-ish cache headers (`Cache-Control: public, max-age=300`).
- `organizations.logo_url` returns the public route URL (null when no logo).
- `DELETE /api/v1/orgs/{org}/logo` clears.
- Arch rule: `->file(`/`UploadedFile` references allowed ONLY in logo upload FormRequest/controller (sole upload surface until module reopens).
- Infra T0: app image must ship PHP **gd** (png/jpeg/webp) — Dockerfile `docker-php-ext-install gd` + rebuild (host PHP irrelevant).

### Kill-switch (S7)
- spatie/laravel-settings, database cache-backed; group `RegistrationsSettings{ bool open = true }`.
- `PUT /admin/v1/settings/registrations` `{open: bool}` (super-admin, audited `settings_change`), `GET` returns `{data:{open}}`.
- `POST /api/v1/auth/register` when closed → 403 `Registrations are closed.` (throttle still applies FIRST; no account created; magic-link + oauth FIRST-LOGIN-CREATION lanes: oauth new-user creation also blocked when closed → 403 same message — magic-link never creates accounts).

### CI guards (S6)
- Existing arch: FormRequest on 100% write routes (keep green; 006 routes comply) + `$fillable` style rule.
- NEW arch: ban `Model::create($request->all())`, `->fill($request->all())`, `unguard(`, `Guard::unguarded(`, `$guarded = []` in `app/` (grep-test; fixture-proved checker like existing ones).
- `harness/scripts/check.sh` already runs `composer audit`; ADD dependabot config (composer + github-actions) `.github/dependabot.yml` (no JS surface in repo root — npm audit N/A, client-demo is ignored).

## Acceptance criteria (→ feature_list 006 S1–S7)
- AC-006.1 (S1) headers: every api/admin JSON response (success + 4xx/5xx) carries nosniff/XFO/Referrer-Policy/CSP+X-Request-Id; HSTS only on secure requests; `/docs` exempt from CSP.
- AC-006.2 (S1) request-id semantics: valid incoming reused verbatim; invalid (chars/length) → replaced by ULID; present in response header AND in log line context.
- AC-006.3 (S1) CORS: listed origin preflight+simple get correct headers, credentials=false; unlisted origin gets none; `FRONTEND_ORIGINS` env drives it.
- AC-006.4 (S2) buckets: hitting auth-login/auth-reset/auth-otp past limit → 429 envelope + Retry-After ≥ 1; `tokens-mutations` new bucket proven.
- AC-006.5 (S2) plan limit: two requests from same org exhaust plan `api_rate_limit_per_min`; different org unaffected (isolation proof); override (005) RAISES the limit — effective() read proven; device AND integration tokens both count into org bucket.
- AC-006.6 (S3) token lifecycle: create shows plaintext once (list has none), abilities validated vs catalog (422 with invalid names), bearer works on ability-opted routes, missing ability → 403 naming it, revoke → 401; long-lived + device-limit-exempt proven.
- AC-006.7 (S4) 2FA gate (per D1 wording): no-TOTP-no-passkey user → 403; TOTP-confirmed → ok; passkey-only → ok (Q3).
- AC-006.8 (S5) logo: png/jpeg/webp ≤2MiB accepted → re-encoded webp on private disk, dimensions capped, bytes ≠ upload (re-encode proof via finfo on stored object); non-image payload with `.png` name + wrong magic bytes → 422; oversize → 422; public route serves 302(s3)/streamed(local); DELETE clears; arch: no other upload surface.
- AC-006.9 (S6) arch greps: violation fixtures fail the checkers; `unguard` ban green on real code.
- AC-006.10 (S7) kill-switch: default open; toggle → register 403 exact message, oauth-new-user 403, toggle audited; existing users unaffected (login works while closed).

## Out of scope (locked scope)
SSO/SAML, WAF, encrypted casts/log scrubbing, Sentry, queue payload encryption, public `/settings` endpoint (008), any upload other than logo (module 9 deleted), pen-test docs.

## Deviations to carry into verification.md
- D1: Q2=C relaxes step 4 "2FA-fresh session" to account-level confirmation (no time window). Annotate feature_list step wording at flip.
- D2: Q5=A chose org-keyed plan throttle over per-token after explicit tradeoff discussion (latest human instruction wins).
- D3: CSP exemption for /docs (HTML dev surface) — API-plane rule intact and arch-tested.
