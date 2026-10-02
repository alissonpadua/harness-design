# Boilerplate Feature Scope — LOCKED (draft, work in progress)

Status: feature scope LOCKED (modules 1–11). Architecture & tooling LOCKED (TDD mandatory). Distribution shape discussion deferred.

## Repo layout (locked earlier in discussion)

```
repo-root/
├── AGENTS.md              # entrypoint <100 lines (symlinked CLAUDE.md)
├── constitution.md        # immutable principles
├── docs/                  # architecture.md, conventions.md, adr/
├── specs/
│   ├── active/<feature>/  # spec.md, plan.md, tasks.md, verification.md
│   └── archive/
├── harness/               # feature_list.json, progress.md, init.sh, scripts/check.sh
├── .agents/               # skills/, subagents/, hooks/
└── src/                   # THE Laravel app (PSR-4 app code)
```
Human approval surfaces: constitution/adr, specs/*/spec.md, implementation PRs (spec ref + verification evidence + feature_list flips). "No spec → no code" enforced in CI.

---

## Module 1 — Identity & Access (v1.2)

- Email + password (Argon2id); email verification before sensitive actions; signed URL/email actions replaced by stateless signed payloads (JSON expires+signature)
- Social OAuth: **Google + Meta only** (Facebook Login covers FB/IG; pure-IG login flagged as Meta-review item)
- **Passkeys/WebAuthn in v1** (asbiin/laravel-webauthn), first-class login method
- Magic-link login as alternative method
- Sessions = **DB-backed Sanctum tokens**; **1 token per device type** (`web`, `mobile`, `desktop`, `cli`) — re-login same type revokes old one; `auth.other_login.detected` event fired (silent); revoke-everywhere endpoint; tokens auto-revoked on password/email change, suspension
- Roles: spatie/laravel-permission; **global plane** (super-admin, user) vs **team plane** (M2) strictly separate; `resource.action` permission names; wildcards only `*` for super-admin
- Profile: **name + email only** (no avatar, no username/handle); email change = verify new + notify old; password change requires current
- Deletion = **soft delete only**; login as soft-deleted user → blocked (generic msg); **restore is admin-only** (M5)
- 2FA: TOTP + recovery codes; org-enforceable policy hook (M2)
- **No login history table** (failed attempts throttled in cache only)
- **API only, JSON responses** (Laravel API resources, `{ message, errors }` envelope)

Out: teams (M2), API integration tokens (M6), billing gates (M3), SSO/SAML (add-on).

## Module 2 — Teams, Orgs & Tenancy (v2)

- Single DB, shared schema, `organization_id` FK (no stancl/tenancy); `BelongsToOrganization` trait + global scope; super-admin cross-org queries always audited
- `current_organization` = **`users.current_organization_id` column** (persisted; switch endpoint updates; no header override; per-user not per-device — accepted tradeoff)
- Org: name, slug (internal identifier), logo, owner, plan; users can own N orgs (plan-gated via M3 entitlement `max_organizations`)
- **Personal workspace** auto-created on signup, private, undeletable while user exists; always-free plan
- Memberships: invite by email (SMTP exists; pending, 7-day expiry) + invite links (role baked in, expiring token, single/multi-use); join/leave/remove; transfer ownership (2FA re-check); owner can't leave without transfer; deactivate vs delete membership
- **Builtin org roles only**: owner, admin, member, viewer (pivot-stored; custom roles cut)
- Org settings: name, logo, domain, default member role, security policy hooks (require-2FA, invite-only, session lifetime)
- Org delete: soft, confirm-text, admin-only restore

## Module 3 — Billing & Monetization (v2)

- **Stripe via Cashier as first adapter behind `Contracts\PaymentGateway`** — fully swappable; adapters normalize webhooks → internal domain events (`SubscriptionActivated`, `PaymentFailed`, `InvoicePaid`); app code never touches Stripe SDK
- **Own billing portal (API)** — not Stripe-hosted portal: payment methods (attach/default/remove via SetupIntents), subscription change + proration preview (gateway-computed), invoice list + download URLs
- **Local billing mirror**: `billing_customers`, `subscriptions`, `invoices` synced by webhooks; portal reads local, calls gateway for actions
- **Plans in DB**: `plans` (code, name, trial_days, active) + `plan_prices` (plan, currency, interval, gateway price id) + `entitlements` JSON typed cast; admin CRUD (M5); seed defaults
- Entitlements API: `org->can('max_organizations')`, `->limit(...)`; hard checks at action sites → `SubscriptionRequired` → HTTP 402; **no quota metering (cut)**
- Multi-currency: per-currency price rows, gateway renders, `invoices.currency` stored
- Trial plan-gated, **card required**; free plan no card; Stripe-native proration; upgrade/downgrade with pending-at-period-end; cancel/resume; dunning: `past_due` → grace → **downgrade to free**
- Stripe Tax enabled by default (config flag); **Hosted Stripe Checkout for payment** (checkout session created via API, client redirects)
- All webhooks: signature verify + `webhook_events` log (idempotency/replay) — feeds M5 audit

## Module 4 — Notifications (v2)

- Channels: **Email + Broadcast only** (no push, no SMS — fully cut)
- In-app = **Laravel Echo** (`ShouldBroadcast`, private `user.{id}` channel; Reverb default driver, Pusher via env); notifications **also persist to `notifications` table** for offline fetch (simple list endpoint, no read/unread) — CONFIRMED
- Email: queued Mailables, **Blade + Markdown templates**, default layout ships
- Notification catalog in code: class per type (`type` string, via channels, defaults, `locked` flag for security-critical); `make:notification` stub command; preferences matrix auto-reads catalog
- Preferences: per user, type × channel (email/push n/a → email toggle; in-app always on); locked types non-overridable
- Queued sends, `failed_notifications` + retry command
- API: notifications list (missed/offline fetch), `GET/PUT /preferences`

## Module 5 — Admin & Ops (v2)

- **API-only admin** `/admin/v1/*`, **super-admin only** (no staff role); no Filament; no feature flags (cut)
- Users: list/search, view; **suspend/unsuspend** (reason, revokes tokens); restore soft-deleted + force-delete; **impersonation** = 15-min token with `impersonator_id` claim, fully audit-logged
- Orgs: list/detail; change plan manually (audited + domain event); **manual per-org entitlement overrides** (kept)
- Plans/prices/entitlements CRUD (DB plans from M3)
- **Audit log**: spatie/laravel-auditable on User, Org, memberships, plans, subscriptions, permission changes + explicit security events (impersonation, suspension, admin login, plan override, webhook replay); query API; **append-only**, retention prune job only
- Health: `GET /up` (spatie/laravel-health)
- **Horizon: fully included, prod-enabled**, dashboard behind super-admin middleware
- No GDPR export endpoint (cut)

## Module 6 — Security (v2)

- Headers: HSTS, XCTO, XFO DENY, Referrer-Policy, minimal CSP; CORS allowlist via `FRONTEND_ORIGINS`; `X-Request-Id` generate/pass/echo/log
- Rate limiting (Redis): per-IP → per-user → per-route-group; tight buckets on auth routes; plan-gated via entitlement read (no metering); 429 + Retry-After in standard envelope; config in `config/rate-limiting.php`
- **Data protection section cut** (no encrypted casts/log-scrubbing requirements) — Sentry cut; error reporting = plain logs, forker's choice
- Tokens: Sanctum serves **device tokens + integration tokens (with abilities)** — `device_type` and/or `abilities` (e.g. `members.read`), integration tokens shown-once, per-org, long-lived, exempt from device limit, creation requires 2FA-fresh session; token CRUD under org settings
- Input hygiene: FormRequest per endpoint, no mass assignment (CI-enforced); uploads: finfo + allowlist + size caps + image re-encode; **private + signed-URL serving only; org logo = sole public-read exception**
- Supply chain: Dependabot, `composer audit` + npm audit in CI, Larastan L8, documented `.env.example`
- Queue payload encryption default OFF
- Out: SSO/SAML, pen-test docs, WAF, data residency, CMK (enterprise add-ons)

## Module 7 — Onboarding (v2)

- **No checklist engine, no analytics stub/adapter, no referrals** (all cut)
- Ships exactly two internal events: `user.registered`, `org.created` (+ M4 welcome-email listener on first)
- `php artisan org:seed-demo {org}` — idempotent faker data, demo provider interface
- `users.last_login_at` single timestamp; first login → `user.registered` → welcome email

## Module 8 — Personalization & Settings (v3)

- `users.locale` + `users.timezone` columns (UTC ISO-8601 API timestamps; timezone for server-side date logic only)
- **App settings (v1)**: spatie/laravel-settings — typed classes, cached, admin CRUD `/admin/v1/settings`, `GET /settings/public`; includes **registrations open/closed kill-switch** (register endpoint → 403)
- **i18n via Laravel built-in localization only**: `lang/` files, `SetLocale` middleware (user.locale → app.locale default, Accept-Language fallback unauthenticated); no laravel-lang package, no demo locale

## Module 9 — Content & Files — **DELETED**

No media library, no CSV import/export, no search. Only upload surface = org logo (M6 rules).

## Module 10 — Background Work (locked)

- Queue conventions (ShouldQueue default for IO, named queues, backoff) as docs + config
- **`OrgScopedJob` trait**: carries `organization_id`, binds current-org inside queued code
- **Outbound webhooks (v1)**: per-org subscriptions (url, secret shown-once, event list from internal events, toggle); HMAC-SHA256 signature + timestamp headers; exponential backoff retries (~5); dead-letter + `webhook.failing` notification; `webhook_deliveries` log (attempt, status, latency); redrive command; org-scoped CRUD `/webhooks/*`
- Inbound: Stripe only (3.6). Generic receiver framework cut; per-org user-defined cron cut

## Module 11 — Developer Platform (v3)

- API versioning: `/api/v1` public + `/admin/v1`; **Scramble** → OpenAPI + `/docs` (public dev/staging, super-admin prod); `docs/api-conventions.md` = agent contract
- Tests: Pest + factories all models + example tests per module mirroring specs; **`FakeGateway`** implements PaymentGateway contract (billing tests w/o Stripe); mail assertQueued; Redis test DB; golden-org fixture (owner + 2 members + trial sub)
- CI `check.sh` = local = CI: pint → larastan L8 → composer audit → tests → **Pest arch tests** → Scramble diff-check (fail on undocumented endpoint changes)
- **100% Docker, nothing on host**: compose = app (php-fpm), nginx, **pgsql**, redis, mailpit, minio; all tooling (composer/artisan) runs in-container; `harness/init.sh` = compose up + install + migrate + seed, idempotent
- Telescope dev/staging-only for request/DB debugging
- Laravel app lives in `src/` under the locked repo-root harness layout

## Architecture & Tooling (LOCKED)

- **Architecture: "Modern Laravel way" (Option B)** — classic Laravel skeleton, strict roles per request:
  `Controller (~5 lines) → FormRequest → Action → Event → listener (Notification/Webhook)`, output via API Resource
- **DTOs**: `spatie/laravel-data` (Input/Output data objects between FormRequest ↔ Action ↔ Resource)
- **TDD MANDATORY** (red-green-refactor): for every task in `specs/*/tasks.md`, the agent FIRST writes failing Pest tests derived from the spec's acceptance criteria (and the `feature_list.json` steps), THEN the implementation; a task cannot flip to done without its tests committed first and passing; CI diff-check rejects implementation-only commits touching `src/` with no test changes in the same task scope
- **Enforcement = executable boundaries**: **Pest arch tests** forbid rogue file placement/imports (no DB calls in controllers, Actions only in `app/Actions`, FormRequest for every write endpoint, no mass assignment) — agents fail CI, not human review
- **Auto-registration**: `spatie/php-structure-discoverer` (attribute-based discovery of Actions/Observers/listeners)
- Enums (native PHP) + `spatie/laravel-model-states` for subscription/payment states
- PHP **8.4** (8.5 when base image stable) · Laravel **12.x** · Pest 4 · Larastan L8→9 · Pint (strict)
- **Octane/FrankenPHP: OUT** (v1) · nwidart/laravel-modules: OUT · CQRS/event-sourcing: OUT
- Distribution shape (template repo vs packaged modules): still deferred to later architecture discussion

---

## OPEN items

1. Distribution shape discussion (deferred by user — "later")
