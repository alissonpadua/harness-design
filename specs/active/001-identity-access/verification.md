# Verification 001 — Identity & Access

Append-only evidence log. Each line: `AC-id | task | command/test name | observed result | commit`.
`feature_list.json` S-steps flip only with evidence here (constitution #10).

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
|    |      |                |          |        |

## Notes / pending human micro-decisions
- spec.md § Micro-decisions 1–5 carry defaults; flip any before T2 starts.

## Package versions (T1.1 evidence)
- pending
| AC-001.23(partial) T9 seeds early; AC-001.26 schema | T1 | `bin/pest tests/Feature/M001_Identity` — SchemaContractTest 9 tests; RED first (8 failed/1 passed: only sanctum guard existed), GREEN after impl; full suite 46 passed, coverage 100.0% | green | pending |
| spike | T1.1/T5.0 | `composer require` → laravel/sanctum 4.3.3, socialite 5.31, spatie/permission 8.3, pragmarx/google2fa 9.1, bacon-qr-code 3.1, **asbiin/laravel-webauthn 6.0.0** (published webauthn.php config + webauthn_keys migration + public/vendor/webauthn js assets) — L13-compatible | green | pending |
| T1 impl notes | | config/hashing.php published, default argon2id; config/auth.php + `password.rules` (complexity per decision #4) + `two_factor_policy` documented as container-binding (closures excluded → config:cache/route:cache verified OK); migration 2026_10_02_150000_identity_core (users cols, PAT device_type/ip/agent, oauth_accounts, auth_links); User traits SoftDeletes+HasApiTokens, encrypted 2FA casts; DatabaseSeeder bcrypt()→raw password (hashed cast owns hashing — argon2id verification rejected pre-hashed) | green | pending |
| Pest 5 pitfalls hit | | `.pest` unsupported; `toIncludeAllMembers` removed (→ array_diff helper); `Model::casts()` protected → `getCasts()`; digit-leading/`NNN-name` dirs → `M<NNN>_Name` | — | pending |

## Package versions (T1.1 evidence)
- sanctum 4.3.3 · socialite 5.31 · permission 8.3 · google2fa 9.1 · bacon-qr-code 3.1 · laravel-webauthn 6.0.0
| AC-001.1–.4 | T2 | RegistrationTest: 15 tests (202-no-token, complexity dataset ×4, confirmation, event-once, anti-enumeration incl. unverified-duplicate path, link lifecycle: verify/consume/replay/expired/tampered/foreign/wrong-type/nonexistent-user, resend generic-202 matrix, AuthLink hash-at-rest, mail content render). RED 12-fail → GREEN 62 tests, coverage 100.0% | green | pending |
| impl notes | T2 | RegisterUserAction cost-parity Hash::make on dup path; resend+register duplicate both funnel through EmailVerificationRequested → single filter point in listener; spatie HasRoles trait on User; #[Response] type-notation on all 3 auth endpoints; throttle buckets auth-register/resend/verify (5,5,20/min by ip) | green | pending |
| arch-tool fix | T2 | RouteRules checker bug surfaced by real invokable routes: controller action without @method must default to __invoke (fixed in checker, not weakened — still verifies FormRequest param) | green | pending |
| bruno | T2 | src/bruno/auth/{register,resend-verification,verify-email}.yml per new convention; YAML validated | green | pending |
| AC-001.5–.9, S2/S7 | T3 | LoginTest: 15 tests — token per device_type (replace-same/keep-others, byte-identical 401 matrices incl. soft-deleted + restore, unverified 403 gate, other_login.detected conditional, sessions list/current-flag/revoke incl. IDOR-404, logout vs logout-all, TrackTokenUsage 5-min throttle via time travel, enum source). RED→GREEN: 77 tests, 100.0% coverage. | green | pending |
| T3 test-isolation lesson | | container persists across getJson() within a test → stale guard user; after revocation asserts call `$this->app['auth']->forgetGuards()`. NOT an app bug (verified real-stack curl 401). Earlier real-stack 500 "Route [login] not defined" was leftover config:cache from the 010 parity rehearsal — optimize:clear fixed. | green | pending |
| 201-default gotcha | | spatie Data returned from POST controller defaults 201 → LoginController returns ->toResponse($request)->setStatusCode(200). | green | pending |
| AC-001.9(.10 part), AC-001.10, AC-001.11 | T4 | PasswordResetMagicLinkTest: 10 tests — forgot generic-202 matrix (ghost/live/soft-deleted) + framework-default-suppression, reset rotate+revoke-all-tokens+revoke-outstanding-links+replay-422+byte-identical unknown/bogus 422, magic request 15-min link, consume=signin+verify-email+single-use+same-type-replace+OtherLoginDetected, expired/bogus/deleted-owner identical 403, device_type validated pre-link, both notification render. 87 tests, 100.0% coverage | green | pending |
| T4 refactor caught by arch | | RequestMagicLinkAction called ->notify() → SourceRules arch test FAILED as designed → moved link-issue+mail into SendMagicLinkNotification listener (event MagicLinkRequested). LoginAction deduped into IssueDeviceTokenAction (shared w/ magic consume). | green | pending |
| L13 API notes | | PasswordBroker::create removed → Password::createToken($user); NotificationFake has no sentTo()/no 2-arg assertNothingSentTo → use assertNotSentTo; belongsTo needs @return BelongsTo<User,$this> for Larastan; Event::fake/assert must use FQCN (unqualified ::class ≠ FQCN in Pest global-ns files). | — | — |
| AC-001.17–.20, S5 | T5 | TwoFactorTest: 9 tests — enroll (secret/otpauth/SVG-QR data-url, encrypted at rest, unconfirmed; 401 unauth), confirm (8 recovery codes once, other devices revoked, event; wrong code 422), login challenge matrix (missing 401 / invalid 422 / TOTP 200 / recovery single-use 200+event), disable (password-gated 422/200, clears columns, event, login stops asking otp), mandatory policy on login AND magic-link surfaces (403 + errors.two_factor, link+email untouched), magic consume respects confirmed 2FA (failed challenge keeps link reusable). 96 tests, 100.0% coverage. | green | pending |
| T5 impl notes | | Pragmarx→PragmaRX namespace casing broke autoload (PSR-4 is case-sensitive); Google2FA bound as singleton (core pkg, no laravel adapter installed); recovery codes = 10-char upper hex, sha256-hashed inside encrypted:array column; TotpProvisioner builds otpauth URI + Bacon SVG data-url (no gd needed); TwoFactorPolicy contract (default false) — 002 rebinds per-org; challenge runs BEFORE link consumption on magic surface (reusable on failed 2FA). Controllers returning spatie Data on POST need explicit ->toResponse()->setStatusCode(200) (5 endpoints now). | green | pending |
