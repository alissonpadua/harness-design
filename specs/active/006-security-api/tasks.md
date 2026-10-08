# Tasks 006 — Security & API (strict TDD: RED before GREEN per task)

## T0 — Infra (no app code)
- [ ] T0.1 App image: apt libjpeg/libpng/libwebp-dev + `docker-php-ext-install gd`; rebuild; verify `bin/artisan tinker --execute='var_dump(extension_loaded("gd"));'` → bool(true)
- [ ] T0.2 Install spatie/laravel-settings + publish config + `settings` table migration runs; `php artisan migrate` green
- [ ] T0.3 `.github/dependabot.yml` (composer + github-actions weekly)
- [ ] T0.4 Confirm Sanctum PAT model override point (`PersonalAccessToken::using`) via spike test

## T1 — Headers + request-id + CORS (AC-006.1/.2/.3)
- [ ] T1.1 RED: `tests/Feature/M006_Security/HeadersTest.php` — nosniff/XFO/Referrer-Policy/CSP on api 200 AND 404 AND 429; HSTS present on `$request->secure()` (server HTTPS flag in test), absent on http; `/docs` lacks CSP but has other headers
- [ ] T1.2 RED: RequestId — valid incoming echoed verbatim; `X-Request-Id: <script>` / 300-char → replaced by ULID; response always has header; log line (test channel) contains request_id context
- [ ] T1.3 RED: CorsTest — FRONTEND_ORIGINS listed origin → ACAO on simple+preflight, `Access-Control-Allow-Credentials` absent; unlisted → none; empty env → no CORS headers
- [ ] T1.4 GREEN: SecurityHeaders middleware + config/security.php + config/cors.php + RequestId sanitize/monolog-context + register in bootstrap; AppServiceProvider log processor
- [ ] T1.5 GREEN: make T1.1–.3 pass; pint+larastan clean

## T2 — Throttle buckets + plan limit (AC-006.4/.5)
- [ ] T2.1 RED: ExhaustTest — auth-login/auth-reset/auth-otp return 429 + Retry-After≥1 + envelope "Too Many Requests."; tokens-mutations bucket 11th call → 429
- [ ] T2.2 RED: PlanLimitTest — set pro plan api_rate_limit_per_min=3 → org's 4th org-scoped api call 429; second org unaffected; 005 override raising value lifts limit; integration token counts into same org bucket
- [ ] T2.3 GREEN: plan-api RateLimiter (effective() + Cache::remember 60s) + attach to org group; tokens-mutations bucket; limiter key unit (device→current_org, integration→token org, fallback personal org)
- [ ] T2.4 GREEN: pass + no collateral 429s in existing suites

## T3 — PAT schema + integration-token creation/auth (AC-006.6 part1)
- [ ] T3.1 RED: migration test (columns/indexes), CreateTokenTest: 2FA-gate 403 no-creds, ok TOTP-only, ok passkey-only (AC-006.7 rows land here too); plaintext returned once; list omits token; ability names validated vs catalog (invalid → 422 lists bad ones)
- [ ] T3.2 GREEN: migration (PAT org_id+kind, org logo cols later), PersonalAccessToken model `using()` + `kind`/`organization_id`; CreateIntegrationTokenAction + TokenController + StoreTokenRequest + 2FA-gate helper (User::hasSecondFactor()); AuditSecurityEvent events registered + logged

## T4 — Ability enforcement + revoke (AC-006.6 part2)
- [ ] T4.1 RED: AbilityGateTest — device token unaffected on opted routes (parity); integration token WITH `members.view` → 200 on GET members; WITHOUT → 403 "This token lacks the required ability: members.view."; revoke → 204 then its bearer 401; wrong-org token id → 404
- [ ] T4.2 GREEN: EnsureTokenAbility alias `ability:` on members.index, invites store/index, org audit feed; RevokeIntegrationTokenAction + destroy route; token↔org relation scoping

## T5 — Logo upload + public serve (AC-006.8)
- [ ] T5.1 RED: ImageProcessorTest (pure): png/jpeg/webp fixtures → output webp, dims ≤1024 preserved ratio, corrupt bytes → exception
- [ ] T5.2 RED: LogoUploadTest — real upload (UploadedFile::fake()->image / raw bytes fixture) accepted; mislabeled (fake image magic-wrong) 422 via finfo; >2MiB 422; member role 403 owner ok; stored path+hash on org, `logo_url` route URL; public route: local→200 image/webp + Cache-Control, s3→302 (fake storage); DELETE clears + public → 404
- [ ] T5.3 GREEN: gd-based ImageProcessor (webp q82 scale), SetOrgLogoAction + UploadLogoRequest (finfo authoritative), routes, PublicLogoController (disk branch), org cols + accessor, ClearOrgLogoAction

## T6 — Kill-switch (AC-006.10)
- [ ] T6.1 RED: RegistrationsSettingsTest — default open; admin PUT {open:false} → 403 for non-admin, 200 for admin + audit settings_change row; register → 403 "Registrations are closed."; oauth NEW-user lane 403 when closed; EXISTING user oauth + password login unaffected; magic-link unaffected
- [ ] T6.2 GREEN: RegistrationsSettings class, SetRegistrationsOpenAction (+audit event name registered), admin GET/PUT routes+requests, RegistrationsClosedException + renderer 403 line, guards in register action + CompleteOAuth create-lane

## T7 — CI guards + docs/bruno/openapi (AC-006.9)
- [ ] T7.1 RED: arch tests — mass-assignment patterns (`create($request->all())`,`fill($request->all())`,`unguard(`,`$guarded = []`) fixtures violate→clean passes; upload-surface rule flags any new `UploadedFile|->file(` outside logo allowlist (fixture)
- [ ] T7.2 GREEN: implement rules in tests/Architecture; bruno/security collection (tokens create/list/revoke, logo upload+public+delete, settings toggle, throttle notes); scramble export openapi.json; docs/api-conventions.md §Security & API plane

## T8 — Convergence
- [ ] T8.1 harness/scripts/check.sh fully green incl. 100% coverage
- [ ] T8.2 live smoke (docker, dev): headers+request-id via curl; CORS from a fake origin; throttle 429 demo; token create+ability 403; logo upload→public URL; kill-switch toggle flips register behavior — evidence in verification.md Manual + gates sections
- [ ] T8.3 verification.md rows; feature_list 006 flip (annotate D1); README roadmap 007 next; journal; propose commit blocks
