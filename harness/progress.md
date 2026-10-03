# Progress Journal — append-only, never rewrite history

## 2026-10-02 — session 0 (harness bootstrap)
- Locked feature scope modules 1–11 in specs/feature-scope.md (TDD mandatory, architecture = "modern Laravel way", pgsql, Scramble, docker-only).
- Created Laravel 13.34 app in `src/` via `laravel new src --database=pgsql --pest --no-authentication`.
- Scaffolded harness: AGENTS.md, constitution.md, docs (architecture/conventions/api-conventions + ADRs 0001–0009), specs/active/001–010, harness/{init.sh, scripts/check.sh, feature_list.json}, .agents/, .github/, src/{docker-compose.yml, docker/, bin/}.
- Next session: 010-dev-platform-ci first (the rails for everything else): finish docker stack smoke-run, wire phpstan/pint configs, arch-test pack, then 001-identity-access spec.md draft for human approval.
- Unknowns: Laravel installer left default sqlite-style config? verified DB=pgsql env keys present; Scramble + spatie packages not yet installed (task 010.x).

## 2026-10-02 — session 0b (harness bootstrap, docker bring-up)
- Scaffolded full harness + Docker stack; cold boot verified: init.sh idempotent, migrate+seed OK, nginx→app 200, Redis PONG, pest 2 passed, bin wrappers work.
- Network reality forced ADR-0010: RustFS replaces MinIO (images pulled from public registries), PECL tarballs vendored, Zscaler root CA baked into php:8.4-fpm-bookworm image (Alpine unusable — TLS interception).
- KNOWN unfinished (owned by spec 010): phpstan+scramble+pint configs not installed yet → check.sh cannot be fully green yet; src/AGENTS.md is the installer's boost-default and must be replaced; .env.example lacks docker-host defaults (DB_HOST=pgsql etc. currently only via compose environment — verify forkers get identical behavior from .env alone); git repo not initialized (awaiting human).
- Next session: 010-dev-platform-ci — composer require (sanctum, cashier? no: stripe-php behind gateway contract later, spatie packages, pest plugins, larastan, scramble), pint/phpstan configs, arch test pack, CI green.

## 2026-10-02 — session 1 (spec 010 implementation, T1–T6)
- Spec 010 approved by human; implemented T1–T6 with TDD (RED confirmed before each GREEN).
- Installed: scramble 0.13.47, larastan 3.12.2, spatie/laravel-data 4.23, php-structure-discoverer 2.4.4, pest-plugin-arch 5.0 (bundled). Added `openapi.json` export committed.
- Built: pint/phpstan configs (L8 clean); Architecture test pack (SourceScan helper, both-direction fixtures); API skeleton (`/api/v1/ping`, RequestId+ApiEnvelope middleware, ApiErrorRenderer unified envelope 401/403/404/405/422/429); docs gate (`app.docs_public`/DOCS_PUBLIC → EnsureDocsVisible replaces Scramble default); PR gates extracted to harness/scripts/gate-*.sh + selftest.sh (4/4 both-direction green); rewritten `.env.example`; replaced `src/AGENTS.md`.
- check.sh now fully green: pint→larastan→composer audit→pest(26, 52 assertions)→openapi freshness. == CI.
- Bugs caught by the rails: non-idempotent DatabaseSeeder (fixed → updateOrCreate); arch rule false-positive on framework storage PUT route (scoped to api/admin); throttle off-by-one (unique limiter per run).
- DEVIATIONS to ratify (see verification.md): (a) `.env` is now the single connectivity source — compose `environment:` block removed from app service (stronger parity than spec text); (b) docs gate uses unified DOCS_PUBLIC config, spec-005 super-admin prod path still pending.
- AC-010.6 (<90s suite) + GitHub remote fire of PR gates = deferred to convergence (need 002/003 + a pushed branch). feature_list 010 stays passes:false until then.
- Next: human ratifies deviations + commits session 1; then spec 001-identity-access → human approval → implement (sanctum, spatie/permission, auth scaffold), OR draft 002 tenancy first if you want current_organization in place before auth screens.
- Convention added + enforced: `final readonly` classes wherever possible (docs/conventions.md #readonly-by-default; new arch test covers app/Actions + app/Http/Middleware with both-direction fixture; retrofitted all 010-era classes; framework-inheriting classes use final + promoted readonly props).

## 2026-10-02 — session 2 (spec 001 drafted)
- Drafted specs/active/001-identity-access/{spec,plan,tasks,verification}.md from locked Module 1 v1.2. 26 EARS ACs; 10 TDD tasks; package set: sanctum, socialite, asbiin/laravel-webauthn, pragmarx/google2fa, spatie/permission; anti-enumeration + revocation semantics specified; org-plane hooks left null until 002.
- Awaiting HUMAN approval of spec.md (constitution #1) + micro-decisions 1–5. No code until then.
- Constitution #11 added (100% line coverage of src/app/ enforced in check.sh via XDEBUG_MODE=coverage + pest --min=100; source scope: app/ only). Coverage gate caught+fixed a latent bug: AuthorizationException arrives pre-converted as AccessDeniedHttpException, so 403 rendered "Forbidden" instead of the promised envelope message; 403/404 now mapped by status. 37 tests, app/ at 100.0%.

## 2026-10-02 — session 3 (spec 001 approved w/ amendments → T1 done)
- Human decisions: passkeys max 5 · password complexity enforced (config auth.password.rules) · HARD login block for unverified (register returns 202, no token) · magic-link verifies · FB email unverified. spec.md updated + APPROVED.
- T1 green: identity packages on L13 (webauthn 6.0.0 spike passed early), migrations (users 2FA/softdelete/locale/tz/current_org_id, PAT device_type/ip/agent, oauth_accounts, auth_links), RolesSeeder (super-admin `*` + user, idempotent on pgsql), config/hashing argon2id, auth.password.rules. RED 8-fail→GREEN 46-test suite @100% coverage; config:cache+route:cache verified.
- Gotchas banked (verification.md): Pest5 API deltas; hashed-cast vs pre-hashed seeder bug fixed.
- Next: T2 — Register + email verification + login-block gate (AC-001.1/.2/.3/.4), incl. user.registered event + first notification classes.

## 2026-10-02 — session 4 (spec 001 T2: register + verification)
- T2 GREEN: full vertical slice live — RegisterRequest→RegisterUserData→RegisterUserAction→UserRegistered/EmailVerificationRequested→listener (auth-link issue + mail)→VerifyEmailAction/ResendVerificationAction; single-use sha256-hashed tokens in auth_links; anti-enumeration (identical 202s, cost-parity hash); 3 named throttle buckets; #[Response] docs; bruno/auth added.
- 62 tests, coverage 100.0%, pint/phpstan/audit green; openapi freshness pending the commit (by design).
- Rails paid off again: arch FormRequest-checker fixed for invokable controllers (checker bug, not weakened); L13 NotificationFake has assertSentTo (no assertQueued*); refresh() wipes transient attributes (test lesson); MailMessage line()-after-action → outroLines.
- Next: T3 — login/device-tokens/sessions + EnsureEmailVerified login-plane gate + isSuspended stub (test-first).

## 2026-10-02 — session 5 (spec 001 T3: login plane)
- T3 GREEN: login/device-tokens/sessions complete — LoginAction (one-token-per-device-type, other_login.detected), EnsureVerified gate (403), denial parity incl. soft-deleted (S7), me/logout/logout-all/sessions list+revoke (IDOR-scoped 404), TrackTokenUsage 5-min throttle, auth-login bucket, spatie #[Response] docs, 6 bruno/auth requests w/ token chaining.
- Catches: spatie Data POST=201 default; test-container guard persistence (forgetGuards needed after revocation asserts); stale config:cache produced bogus 500 on real stack earlier.
- 77 tests, 100.0% coverage, check.sh green (openapi regenerated for 6 new routes).
- Next: T4 — forgot/reset + magic link (auth_links reuse), then T5 2FA.
- Banked: class docblock with @property MUST sit ABOVE the #[Attributes] group (php-parser association) or Larastan ignores it; pint rewrites FQCNs in docblocks into imports (fully_qualified_strict_types).

## 2026-10-02 — session 6 (spec 001 T4: password reset + magic link)
- T4 GREEN: forgot/reset (Password broker, User.sendPasswordResetNotification override→own mail, reset revokes tokens+outstanding verify/magic links, PasswordChanged event, byte-identical 422/202 anti-enumeration) + magic request/consume (IssueDeviceTokenAction shared w/ login, verifies email per decision #1, single-use).
- Arch rule "no mailer in Actions" correctly caught RequestMagicLinkAction ->notify() mid-build → refactored to MagicLinkRequested event + SendMagicLinkNotification listener. LoginAction refactored to delegate token issuance to IssueDeviceTokenAction (no duplication).
- 87 tests, 100.0% coverage, phpstan L8 clean. openapi.json regenerated (freshness gate will pass post-commit).
- L13 gotchas banked (see verification). Next: T5 2FA (T5.0 spike already done).

## 2026-10-02 — session 7 (spec 001 T5: 2FA)
- T5 GREEN: enroll/confirm/disable endpoints (auth:sanctum, throttle auth-2fa), TOTP (pragmarx google2fa, window 1) + 8 sha256-hashed recovery codes (single-use), mandatory policy contract (TwoFactorPolicy; default allow-all, 403 enroll-first wired into login AND magic consume — challenge before link consumption keeps links reusable), login challenge matrix, events Enabled/Disabled/RecoveryCodeUsed, QR via Bacon SVG data-url, provisioning otpauth URI. bruno/auth 2fa-* added.
- Gotchas banked: PragmaRX casing (PSR-4 case-sensitive), Data-on-POST defaults 201 (5 controllers now set 200 explicitly), my own script truncated a controller file via open(w) before read (rewritten — beware).
- 96 tests, 100.0% coverage, all rails green (openapi freshness = pending commit).
- Next: T6 passkeys (asbiin webauthn 6.0 ceremonies with deterministic test fixtures — hardest remaining).

## 2026-10-02 — session 8 (spec 001 T6: passkeys)
- T6 GREEN: full WebAuthn surface on asbiin/laravel-webauthn 6.0 headless API (prepare/validate attestation+assertion, cache-based single-use challenges keyed user/host|ip): register options/register(201)/index/destroy(auth:sanctum, cap 5) + authenticate/options + authenticate(public, throttled, generic-401 parity, unverified 403 gate, satisfies mandatory 2FA by design — phishing-resistant factor).
- Built tests/Support/PasskeyFixture.php: real software authenticator (OpenSSL P-256, packed self-attestation, hand-rolled CBOR, COSE DER→raw sig conversion, server-provided rp/challenge/userHandle).
- Rails fixed 3 real integration bugs (RS1 fatal via container override; https origin enforcement; padded credentialId lookup) + 1 API mismatch (decodeUnpadded absent).
- 106 tests, 446 assertions, app/ coverage 100.0%, phpstan L8 clean. bruno/auth passkey requests added.
- Next: T7 OAuth (socialite google/facebook, oauth_accounts, exchange flow — network-blocked providers mocked at Socialite facade).

## 2026-10-02 — session 9 (spec 001 T7: OAuth)
- T7 GREEN: CompleteOAuthAction (whitelist→Socialite stateless exchange→resolve: account match / verified-google email link / create w/ provider-claim-trusted verified flag (google only) / soft-deleted+no-email generic 401) + redirect endpoint + throttle + env block + bruno. Gates unified with login (verify-gate 403, mandatory-2FA 403, otp challenge).
- Catches: Socialite Contracts::Provider lacks stateless()/getRaw() → assert-narrowing for Larastan (broke stdClass mocks → now mock AbstractProvider); mockery one-expectation-per-driver gotcha (split tests); openapi freshness fired as designed.
- 119 tests, 100.0% coverage. M001: T1–T7 done. Next T8 profile lifecycle (email change finalize, password change revoke-others, delete-account) then T9 admin gate, T10 hardening.

## 2026-10-02 — session 10 (spec 001 T8: profile lifecycle)
- T8 GREEN: GET/PUT profile (name/locale/timezone), staged email change (auth_links confirm_email_change + dual notifications, newest-wins, apply-on-confirm with full session revoke + EmailChanged), password change (current-check, complexity, keep-own-session revoke-others, PasswordChanged), delete-account (pw-gated soft delete + revoke-all + generic-denial parity with unknown email). Reset-password now also kills staged email-change links.
- Gotchas: patch-inserted route block landed inside wrong prefix (route:list caught it) → routes/api.php rewritten as full registry; duplicate revokeOutstanding from earlier re-add; Password::broker()->create gone in L13 (Password::createToken); sticky-guard pattern applied x2.
- 130 tests, 100.0% coverage, L8 clean. M001: T1–T8 done. Next: T9 role plane + /admin/v1 gate; T10 cross-cutting hardening (bucket reflection, notification audit, convergence notes).
