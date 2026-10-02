# Plan 001 — Identity & Access

## Packages (src/bin/composer)
- `laravel/sanctum` (device tokens; publish config, migrations)
- `laravel/socialite` (google + facebook built-in; `Socialite::driver($provider)` only inside `App\Auth\OAuth`)
- `asbiin/laravel-webauthn` (passkeys; publishes migrations/models — wrap our own tables, use its ceremony classes)
- `pragmarx/google2fa` (+ `bacon/bacon-qr-code` for provisioning URI data URL)
- `spatie/laravel-permission` (global plane)

## Migrations (in order)
1. add-to-users: `email_verified_at`(exists), softDeletes, `two_factor_secret`(text, encrypted cast), `two_factor_recovery_codes`(text, encrypted cast), `two_factor_confirmed_at`, `locale`, `timezone`, `current_organization_id` nullable (no FK yet until 002 — int FK-safe)
2. oauth_accounts, passkeys, custom personal_access_tokens (device_type, name, ip, user_agent, last_used_at) — Sanctum table modified via its published migration
3. permission tables

## Namespace layout (arch-compliant)
- `app/Auth/` (OAuth provider normalizers, TOTP service, recovery-code codec) — pure services, no DB writes
- Actions in `app/Actions/Auth/`, `app/Actions/Profile/` (e.g. `LoginAction`, `RegisterUserAction`, `ChangePasswordAction`, `ConsumeMagicLinkAction`, `CompleteOAuthAction`, `EnrollTwoFactorAction`, `ConfirmTwoFactorAction`, `AssertPasskeyAction`, `RevokeDeviceTokensAction`)
- Data DTOs `app/Data/Auth/*Data`; Requests `app/Http/Requests/Auth/*`; Resources `app/Resources/Auth/*`
- Controllers `app/Http/Controllers/Api/Auth/*` (thin)
- Events `app/Events/Auth/*` + `UserRegistered` (from 007 contract); Listeners `app/Listeners/Auth/*` send Notifications only
- Notifications `app/Notifications/*` (6 classes, mail channel; M4 will wrap into catalog — keep shapes: subject lines + action URL payloads)
- Middleware: `EnsureEmailIsVerified`, `TrackTokenUsage` (last_used throttle), `ForceTwoFactor` policy hook reads `config('auth.two_factor_policy')` closure
- `config/rate-limiting.php` with named limiter definitions registered in AppServiceProvider

## Token/session semantics (AC-001.5/8/9)
- `LoginAction`: DB::transaction — create token, delete same-device_type tokens, compare remaining count BEFORE for `other_login.detected`
- Revocation-by-context helpers: `RevokeDeviceTokensAction::allExcept($user, $tokenId)`, `::forType(...)` — used by password/email change & 2FA enable
- Sanctum guard for `/api/v1/*`; signature payloads via `URL::temporarySignedRoute` equivalents → JSON-friendly: sign `{id,hash,exp}` strings with `encrypter`+`hash_hmac`; single-use tracking table `auth_links` (type, user_id, expires_at, used_at) for magic links & email-change confirmations (password resets stay on Laravel's token broker)

## Test strategy
- Feature tests per AC group: `tests/Feature/M001_Identity/{Registration,Login,DeviceTokens,PasswordReset,MagicLink,OAuth,Passkeys,TwoFactor,Profile,Roles}Test.php`
- Fake mail driver + `Notification::fake` assertions; Redis test DB for throttle; no external HTTP (Socialite mocked via its facade)
- Anti-enumeration: response-shape + timing-window assertions (status equality, not sleep benchmarks)
- Unit: recovery-code codec, TOTP service (fixed clock), signature payloads

## Sequencing (tasks.md) & key risks
- Webauthn package maturity vs Laravel 13 — if broken: fallback `passwordless-...`? Decision gate at T5 spike (1 hour timebox, else swap lib, note in verification).
- Socialite facebook + Zscaler network can't reach graph API in tests — everything mocked; smoke of real flow is a human/browser step logged to verification.
- Suspend hook (AC-001.7 "when 005 lands"): implement as `isSuspended(): bool` stub returning false + TODO pointer — flip in 005.
