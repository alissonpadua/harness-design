# Spec 003 — Billing & Monetization

Status: APPROVED (Q1–Q9 human sign-off + “go”, 2026-10-03) — SHIPPED, see verification.md
Source: ../../feature-scope.md § Module 3 v2 (LOCKED) + ADR-0003 (swappable gateway) + ADR-0004 (plans in DB) + Q1–Q9 decisions 2026-10-03
Maps to: feature_list 003 S1–S6. Flips 010 S6 (FakeGateway + golden-org <90s) at convergence.

## Locked decisions (from Q&A)
1. Plans: `free` $0 · `pro` $19 · `business` $79 (monthly), annual = 10× monthly (2 months free)
2. Entitlements (per plan): max_teams 1/5/25 · max_members_per_org 3/20/100 · webhooks ✗/✓/✓ · audit_retention_days 0/90/365 · api_rate_limit_per_min 60/600/3000
3. Trials: `trial_days` column per plan (pro/business seeded 14, free 0), **card required** for trial
4. Currencies seeded: **USD, EUR, BRL** — DB rows only, scalable via admin CRUD
5. Stripe Tax: **disabled** (`billing.tax_enabled=false`), everything tax-agnostic
6. Over-cap downgrade: **Policy A** — immediate downgrade, 30-day `over_limit` grace blocking ADD operations only, existing data untouched forever
7. Live smoke: Stripe **test keys in src/.env** (already present); NO stripe CLI / NO tunnel → dev uses `billing:ingest` (pulls events from API) + `billing:sync` (state reconciliation); real webhook route implemented for later deploy
8. Portal v1 set: payment methods · change + proration preview · invoices · cancel/resume · over_limit surfacing (no promos/seats/receipt-API)
9. Out: refunds API, metered billing, Paddle (contract keeps door open), admin plan-GRANT hook (005) — but plan **CRUD** is in-scope here (feature_list S1)

## Data model
- `plans`: code (unique), name, `trial_days` int, `active` bool, `entitlements` JSON (typed cast → `PlanEntitlementsData` with the 5 keys, strict validation on write)
- `plan_prices`: plan, currency (ISO 4217), interval (`monthly|annual`), amount int (minor units), `gateway_price_id` nullable (filled by `billing:stripe-mirror`)
- `billing_customers`: organization_id unique, gateway, `gateway_customer_id`
- `subscriptions`: organization_id, plan_id, status enum (`inactive|trialing|active|past_due|canceled`), gateway, `gateway_subscription_id`, interval, `current_period_end`, `trial_end`, `cancel_at_period_end`, `past_due_since` nullable, `over_limit_until` nullable, timestamps; one active row per org
- `invoices`: organization_id, gateway ids (`gateway_invoice_id` unique), status, total minor units, currency, `hosted_url`, `paid_at`/`due_at`, timestamps
- `webhook_events`: gateway, `gateway_event_id` unique, type, payload JSON, `processed_at` nullable, `error` nullable — idempotency + replay

## Gateway contract (`App\Contracts\PaymentGateway`)
`customer(org)`, `checkoutUrl(org, plan, interval, currency): {url, gatewayRef}`, `subscriptionFor(org, plan, interval, currency, trialDays): {url, gatewayRef}` (card-required trial), `previewChange(org, plan, interval): ChangePreview`, `applyChange(org, plan, interval)`, `cancelAtPeriodEnd(org)`, `resume(org)`, `paymentMethods(org)`, `setupIntent(org): {clientSecret,url}`, `attachPaymentMethod(org, gatewayPmId)`, `defaultPaymentMethod(org, id)`, `detachPaymentMethod(org, id)`, `invoices(org)`, `ingest(string rawPayload, string signature): IngestResult` (verify sig, return normalized domain events), `pullRecentEvents()` (dev ingest command).
Adapters: `StripeGateway` (only place `stripe/stripe-php` may be imported — arch rule exists) and `FakeGateway` (deterministic, in-memory + scripted events; bound via `config('billing.driver')`; **tests must never hit network**).
Normalized internal domain events: `CheckoutCompleted`, `SubscriptionActivated`, `SubscriptionPlanChanged`, `SubscriptionCanceled`, `SubscriptionResumed`, `PaymentFailed`, `PaymentRecovered`, `InvoicePaid` — app code listens only to these.

## Acceptance criteria (EARS) — maps to S-steps in ()

### Plans & entitlements (S1)
- AC-003.1 Seeder creates the 3 plans × 3 currencies × 2 intervals rows with the exact entitlement table above; re-run idempotent.
- AC-003.2 `OrgEntitlements` binding is now **plan-backed**: `maxTeams(org owner user)` / `maxMembers(org)` read the org's active subscription's plan entitlements (fallback: `free` when none). Orgs' existing 402 behavior (002) must not regress.
- AC-003.3 Admin CRUD `/admin/v1/plans` (super-admin): index, show, store, update (name/trial_days/active/entitlements full replace with shape validation), plus nested prices add/update (`plan_id, currency, interval` unique). No deploy needed to change prices/entitlements.
- AC-003.4 Entitlement JSON cast rejects unknown/missing keys (422 at the admin boundary, exception in direct writes).

### Checkout & subscriptions (S2)
- AC-003.5 POST `api/v1/billing/checkout` {plan_code, interval monthly|annual, currency?} (permission `billing.manage`) → 201 `{data:{url}}` via gateway; unknown/inactive plan 404; free 422; currency without price row 422.
- AC-003.6 A paid plan starts on `trialing` when `trial_days>0` with **card required** (gateway called with card collection enforced) — `subscriptionFor` path returns a checkout URL and, after `checkout.session.completed`, subscription status `trialing`, `trial_end` = now+days, org current plan = target.
- AC-003.7 Free is the floor: switching to free is immediate, no checkout, status `active`, no gateway money intent.
- AC-003.8 One active subscription per org enforced (DB + app); changing plan updates the SAME row (new gateway subscription id stored), old canceled via webhook.

### Webhooks & mirror (S4)
- AC-003.9 POST `billing/webhook/stripe` (public, CSRF-exempt): invalid/missing signature → 400, event NOT stored; valid → `webhook_events` row (id unique) + idempotent (replay 200, single processing, domain event dispatched once).
- AC-003.10 Ingested events mutate the mirror correctly: checkout completed → sub active/trialing + customer row; invoice.paid → invoice row + `past_due_since=null` + status active + `PaymentRecovered` when previously past_due; invoice.payment_failed → `past_due` + `PaymentFailed` + `past_due_since`; customer.subscription.updated (period end) → `current_period_end`/`cancel_at_period_end`; deleted → `canceled`.
- AC-003.11 Unknown event types: stored, `processed_at` set, no domain event, no error.
- AC-003.12 `php artisan billing:ingest` (dev-only): pulls recent events from the gateway, replays them through the SAME pipeline (idempotent), prints per-event result. `billing:sync`: reconciles org subscription/invoice state from the API.

### Portal (S3)
- AC-003.13 GET `billing/subscription` (billing.view) → plan snapshot, interval, status, period end, trial_end, cancel_at_period_end, over_limit fields.
- AC-003.14 POST `billing/subscription/preview` {plan_code, interval} → gateway-computed prorations: `{lines:[{description, amount, currency}], total_due}` (signed ints; FakeGateway deterministic).
- AC-003.15 POST `billing/subscription/change` applies previewed change (billing.manage); same-plan 422; result reflected after webhook/mirror (FakeGateway reflects immediately in tests).
- AC-003.16 Payment methods: GET list (id, brand, last4, exp, default) · POST `payment-methods/setup` → `{client_secret, url}` · POST `payment-methods/{id}/default` · DELETE `payment-methods/{id}` (404 foreign/unknown). Removing the default while subscription active → 422.
- AC-003.17 Invoices: GET list (status, total, currency, period, hosted_url) + GET download-url for one (404 foreign).
- AC-003.18 POST `billing/subscription/cancel` → `cancel_at_period_end=true` (200) · POST `resume` → false (200); both billing.manage.

### Dunning (S5)
- AC-003.19 Hourly scheduler `billing:dunning`: `past_due` with `past_due_since` older than **7 days** → downgrade org to `free` (gateway `change` to free = AC-003.7 path), status `active` (free), `past_due_since=null`, dispatch `SubscriptionPlanChanged` + mark over-limit evaluation (AC-003.21).
- AC-003.20 `past_due` recovery (PaymentRecovered) zeroes `past_due_since`; dunning never touches healthy subscriptions.

### Over-limit (policy A, S2-adjacent)
- AC-003.21 When an org's active plan changes to one whose caps are below CURRENT usage (teams/members), set `over_limit_until = now()+30d`. While `over_limit_until` in future (or set with expired window): `CreateOrganizationAction` and `InviteMemberAction`/`JoinInviteLinkAction` throw `SubscriptionRequiredException` (402 `Subscription required.`) — reads and existing rows untouched, ever. After grace, adds remain blocked until under caps (no deletion jobs).
- AC-003.22 Downgrading while already within caps clears `over_limit_until`. Portal GET subscription exposes both fields.

### FakeGateway & performance (S6)
- AC-003.23 Entire suite runs with `billing.driver=fake`: zero outbound network, all above flows covered end-to-end incl. signed-fake webhooks.
- AC-003.24 Full `pest` suite wall-clock stays **under 90s** in CI (010 S6 flip with timing evidence in 010 verification).

## Permissions added
Catalog: `billing.manage` (+ existing `billing.view`). org_roles: owner `*`; admin gains `billing.manage`; member/viewer keep only `billing.view`? NO — member/viewer: no billing perms (reads via `billing.view` for admin+owner). Update `config/org_roles.php` accordingly.

## Security & misc
- All billing mutation endpoints: `throttle:billing` (30/min/user). Webhook route: no throttle-exempt abuse (throttle 60/min/IP) + signature gate.
- Gateway errors map to 502 `{message:'Payment provider error.'}` (no provider message leakage); unknown plan id in gateway responses treated as 500-safe default.
- Money only ever as integers (minor units) + currency code — no floats anywhere (arch test: no float column types in billing migrations).
- `billing.driver`, `billing.tax_enabled`, `billing.dunning_grace_days=7`, `billing.over_limit_grace_days=30` in config/billing.php.

## Out of scope
Refunds/credit notes API, promo codes, seat quantity, metered billing, merchant-of-record switch, email receipts (004 consumes events), admin manual plan grants (005), tax enablement.

## Non-functional
Every AC ≥ one Pest test in `tests/Feature/M003_Billing/`; TDD; 100% coverage of app/; arch rules stay green (Stripe imports confined to app/Billing/); openapi committed; bruno/billing + verification evidence for 003 and 010-S6.

## Micro-decisions baked (change if you object)
1. Trial checkout uses the same hosted Checkout session with card enforcement (no separate trial endpoint).
2. Plan switch swaps gateway subscriptions (cancel old) rather than in-place item swap — simpler across gateways.
3. `checkout` for already-subscribed org = blocked (422) — changes go through preview/change, not re-checkout.
4. Invoices list = mirror table only (hosted URLs from Stripe); no pagination infra beyond cursor default.
