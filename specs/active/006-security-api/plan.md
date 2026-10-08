# Plan 006 — Security & API

## Approach
No new composer deps except `spatie/laravel-settings` (+ its cache wrapper already available). Everything else = own middleware/config (constitution: minimal deps, full coverage).

### Infra (T0)
- Dockerfile/app image: add `libjpeg-dev libpng-dev libwebp-dev` + `docker-php-ext-install gd`; rebuild `app` image; verify `extension_loaded('gd')` via bin/artisan tinker. No host PHP anywhere (rule #3).
- `.github/dependabot.yml` (composer + github-actions, weekly).
- `spatie/laravel-settings` install + `settings` table migration + publish config (db repo, cache store).

### Headers/CORS/request-id (T1)
- NEW `app/Http/Middleware/SecurityHeaders.php`: nosniff/XFO/Referrer-Policy always; HSTS when `$request->secure()`; CSP `default-src 'none'; frame-ancestors 'none'` unless path in `config('security.csp_exempt')` (default `['docs','docs/*','swagger-ui/*']`).
- UPGRADE `RequestId`: regex sanitize (`^[A-Za-z0-9_\-]{1,64}$` else fresh ULID) + `Log::withContext(['request_id'=>…])` via monolog processor in AppServiceProvider (append to existing logging channel).
- NEW `config/security.php` (csp exempt list + header values) + NEW `config/cors.php` from `FRONTEND_ORIGINS` (comma list; empty = block all cross-origin; same-origin unaffected). Register SecurityHeaders after RequestId in global/api groups (bootstrap/app.php).

### Throttles (T2)
- `tokens-mutations` bucket (10/min/user) in AppServiceProvider (single definition site convention).
- `plan-api` limiter: `RateLimiter::for('plan-api', …)` resolving acting org: integration token → `token->organization_id`; device → `user->current_organization_id` (fallback personal workspace `organizations.owner_id` first); key `org:{id}`; max = `PlanOrgEntitlements::effective($org)->api_rate_limit_per_min` wrapped in `Cache::remember("rl:{$org->id}", 60)`. Attach to authenticated org-scoped api group (routes/api.php — the `auth:sanctum` org surface only; auth plane keeps its own buckets).

### Integration tokens (T3+T4)
- Migration: PAT `organization_id` (nullable, FK restrict) + `kind` string default 'device' (+ index (organization_id,kind)); backfill existing rows kind=device.
- `app/Models/PersonalAccessToken.php` — extend Sanctum model? Sanctum model override already? CHECK at T3 start: if not, `PersonalAccessToken::using(new model)` in AppServiceProvider.
- `app/Actions/Org/CreateIntegrationTokenAction` (2FA-gate Q2/Q3, ability-validation vs catalog via `config('permissions.catalog')` keys, plaintext once, audit via AuditSecurityEvent `integration_token_created`), `RevokeIntegrationTokenAction` (ownership-scoped, audit).
- Controllers/requests: `TokenController` (index/store/destroy) under org plane + `StoreTokenRequest` (abilities: `array`, each `in:<catalog keys>` — built dynamically; name max 60).
- `app/Http/Middleware/EnsureTokenAbility.php` alias `ability:` — passes when token kind=device OR integration token `tokenCan($param)`; 403 `This token lacks the required ability: {ability}.` Attach to the three opted routes (members.index GET, invites store+index, org audit feed) — device-token behavior UNCHANGED (tests prove parity).
- Route model binding care: tokens scoped by org (`$org->tokens()` = new relation filtering kind+org on PAT).

### Logo (T5)
- `app/Support/ImageProcessor.php`: GD decode→truecolor→scale ≤1024→`imagewebp` quality 82; throws on undecodable. Unit-testable pure function.
- FormRequest `UploadLogoRequest`: `logo` file exists + max 2048 (KiB) + `mimes:png,jpg,jpeg,webp` (client-side hint) — AUTHORITATIVE check: `finfo_file` mime allowlist in the request `after()` or action.
- `app/Actions/Org/SetOrgLogoAction` (permission org.settings owner/admin; private disk `local` default in tests / s3 in dev; path `orgs/{id}/logo.webp`; update `logo_path`+`logo_hash` columns (migration)); `ClearOrgLogoAction` deletes + nulls.
- `PublicLogoController` route `api/v1/public/orgs/{identifier}/logo` no auth: disk s3 → `temporaryUrl` 302; local → `response()->stream` w/ Content-Type image/webp + Cache-Control 300. 404 when path null/file missing (id-or-slug resolution reuse `identifier()` scope).
- `logo_url` accessor: route URL when path set.
- Arch rule (T6): scan app/ for `UploadedFile|->file\(` excluding `UploadLogoRequest.php`, `LogoController|SetOrgLogo` allowlist.

### Kill-switch (T6)
- `app/Settings/RegistrationsSettings.php` (Spatie\Casts\AsSettings payload class w/ `public bool $open = true`).
- `app/Actions/Admin/SetRegistrationsOpenAction` (audit `settings_change` via AuditSecurityEvent; needs event registered in allow-list!).
- Admin routes: GET/PUT `/admin/v1/settings/registrations` (FormRequests).
- Gate helper used by RegisterController action + oauth-create lane in CompleteOAuthAction: when closed → shared `RegistrationsClosedException` → renderer 403 `Registrations are closed.`

### CI/arch (T7)
- Mass-assignment grep rule + fixtures (pattern: existing SourceScan).
- Tests must cover: gd-dependent paths gracefully in CI (gd installed at T0).
- bruno/security collection (tokens CRUD, logo upload multipart, public logo, settings toggle, throttle demo note); openapi regen; docs/api-conventions §Security plane.

### Convergence (T8)
- full check.sh, 100% cov, verification.md rows, feature_list flip (annotate D1 on step 4), README roadmap 007 next, journal, client-demo (Admin console tab? minimal: tokens screen + kill-switch toggle + logo upload in org settings — propose after green).

## Risks
- GD absence broke first assumption — T0 MUST land before any RED logo tests (tests would fail in container).
- sanctum PAT model override: if `PersonalAccessToken::using()` path is wrong, `kind/org` columns invisible on auth → token bypass risk. Mitigation: test integration token auth FIRST (T3 RED before wiring routes).
- plan-api limiter on a busy test suite must not 429 other modules: default plan values ≥ current test traffic; limiter attached ONLY to org group; tests set generous plan values explicitly.
- CSP exemption drift: `/docs` detection by route name, not glob string matching user input.

## File inventory (new ≈ 25 / touched ≈ 12)
New: SecurityHeaders.php, cors.php, security.php, ImageProcessor.php, RegistrationsSettings.php, SetRegistrationsOpenAction, CreateIntegrationTokenAction, RevokeIntegrationTokenAction, TokenController, StoreTokenRequest, UploadLogoRequest, LogoController, PublicLogoController, ClearOrgLogoAction, EnsureTokenAbility.php, migrations×3 (PAT, org logo cols, settings), dependabot.yml, tests M006/* (7 files), bruno/security/*.
Touched: bootstrap/app.php, AppServiceProvider (buckets, PAT model using, monolog ctx), routes/api.php, routes/admin-v1.php, RegisterController/action, CompleteOAuthAction, Organization model (logo cols/accessor), ApiErrorRenderer (RegistrationsClosedException + 403 ability msg), docs/api-conventions.md, config/permissions.php (verify names used by tokens exist), Dockerfile (gd), PlansSeeder untouched (limit comes from existing entitlement).
