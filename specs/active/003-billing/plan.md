# Plan 003 — Billing & Monetization

## Dependencies
`composer require stripe/stripe-php` (inside app/Billing only — arch rule pre-exists). No Cashier.

## Layout
- `app/Billing/` — `StripeGateway`, `FakeGateway`, `Support/{EventNormalizer,CheckoutLinks,WebhookSignature}.php`, `Events/*` domain events (CheckoutCompleted, SubscriptionActivated, SubscriptionPlanChanged, SubscriptionCanceled, SubscriptionResumed, PaymentFailed, PaymentRecovered, InvoicePaid)
- `app/Contracts/PaymentGateway.php` + `app/Contracts/DTO`-ish return shapes as `app/Data/Billing/*Data` (ChangePreview, PaymentMethodData, InvoiceData, IngestResult…)
- Models: Plan, PlanPrice, BillingCustomer, BillingSubscription, BillingInvoice, WebhookEvent (all in app/Models; Billing* prefix avoids collision with generic words)
- `PlanEntitlementsData` (spatie Data with strict rules; JSON cast on plans)
- Actions `app/Actions/Billing/*`: StartCheckout, CreateSubscriptionTrial, SwitchToFree, PreviewChange, ApplyChange, CancelSubscription, ResumeSubscription, SetupPaymentMethod, AttachPaymentMethod, SetDefaultPaymentMethod, DetachPaymentMethod, ProcessWebhook (delegates gateway.ingest → mirror sync), RunDunning, EvaluateOverLimit, admin CRUD actions (UpsertPlan, UpsertPlanPrice, TogglePlan)
- `PlanOrgEntitlements implements Contracts\Org\OrgEntitlements` — rebind in AppServiceProvider (single line swap of the 002 seam)
- Controllers `app/Http/Controllers/Api/Billing/*` + `Admin/PlanController`; Requests `app/Http/Requests/Billing/*`
- Commands: `billing:ingest`, `billing:sync`, `billing:dunning` (scheduled hourly in bootstrap/console.php), `billing:stripe-mirror` (create products/prices in Stripe test account from DB, write back gateway_price_id)
- Migrations per spec data model; `webhook_events` first (002 note: M3 owned it — now built)
- Permission catalog: +billing.manage; org_roles: admin gains billing.view+billing.manage; member/viewer lose billing.view (per spec §Permissions)
- 002 touch-points: CreateOrganization/InviteMember/JoinInviteLink go through PlanOrgEntitlements automatically (interface unchanged) + over-limit check inside PlanOrgEntitlements returns effective caps (used→0 headroom when over-limit active).

## Webhook route
POST `/billing/webhook/stripe` (outside api/v1 — Stripe configured later) in routes/api? No: plain `Route::post` in bootstrap web group? It must skip CSRF (api middleware group has no CSRF ✓) — register as `api/v1/billing/webhook/stripe` for consistency; Stripe can point anywhere.

## FakeGateway design
- stateful in-memory arrays keyed by org; `ingest()` accepts payloads signed with a test HMAC secret (same verify code path with `WebhookSignature::fake($payload)` helper) so tests exercise signature logic
- scripted emitters: `FakeGateway::emitCheckoutCompleted($org, …)` used by tests
- deterministic proration math (target − remaining), stable ids (`fake_pm_…`, `fake_in_…`)

## Test fixtures
`tests/Feature/M003_Billing/` : PlansSeederTest, CheckoutTest, WebhookIngestTest, PortalTest, PaymentMethodsTest, InvoicesTest, DunningTest, OverLimitTest, AdminPlansTest, FakeGatewayContractTest (asserts Stripe+Fake same contract via shared abstract contract test? keep light: FakeGatewayContractTest + a skipped-tagged live smoke script `smoke:billing` for real keys, human-run).

## Risks
- stripe-php version vs PHP 8.4/L13 → pin latest ^18; used only behind adapter.
- Checkout session needs a price with gateway_price_id → until `billing:stripe-mirror` runs, live checkout 502s cleanly (spec'd).
- `billing:ingest` pulls with pagination cap 50; idempotency covers overlap.
- Performance (010 S6): billing suites use RefreshDatabase (sqlite in-memory) + FakeGateway; measure; if >90s parallelize CI job split (workflow tweak).

## Sequencing
T1 data-model+gateway-contract+fake → T2 subscriptions bootstrap + entitlements rebind + over-limit core → T3 checkout/trial/free-switch → T4 webhook pipeline + ingest/sync commands → T5 portal (preview/change/cancel/resume/PM/invoices) → T6 dunning → T7 admin plans CRUD → T8 perms/org-roles wiring + bruno/openapi → T9 convergence (003 flips + 010 S6 timing + live-smoke checklist for human).
