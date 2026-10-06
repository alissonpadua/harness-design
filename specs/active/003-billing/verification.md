# Verification 003 — Billing & Monetization

Append-only evidence log. Flips of feature_list 003 (S1–S7) and 010 S6 from rows here. Environment: Docker (PHP 8.4/Laravel 13), sqlite :memory: for tests, BILLING_DRIVER=fake (phpunit.xml) — zero network.

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
| AC-003.1 | T1 | PlansEntitlementsTest 'AC-003.1' | 3 plans × 3 currencies × 2 intervals = 18 price rows; BRL annual 99000; re-seed keeps counts | pending |
| money-as-integers, uniques | T1 | PlansEntitlementsTest 'money ints…' | plan_prices.amount int; (plan,currency,interval) unique throws; subscriptions.organization_id unique throws | pending |
| AC-003.4 | T1 | PlansEntitlementsTest 'cast rejects' | unknown/missing entitlement keys → InvalidArgumentException at model; HTTP shape → 422 via request rules | pending |
| AC-003.2 | T2 | PlansEntitlementsTest 'entitlements come from plans' + M002 suite green | maxMembers/maxTeams read plan; best plan across owned orgs; free floor self-heals | pending |
| AC-003.21/.22 | T2 | PlansEntitlementsTest over-limit tests | downgrade below usage stamps over_limit_until (+30d); upgrade clears; adds 402; members untouched (3 after block) | pending |
| AC-003.5, micro-dec.3 | T3 | CheckoutWebhookTest 'validation matrix' + 're-checkout blocked' | ghost 404, free 422, bad interval 422, missing currency 422, outsider 404, pro→201; subscribed→422 | pending |
| AC-003.6 | T3 | 'AC-003.6' + StripeGatewayTest checkout shape | payment_method_collection=always + trial_period_days asserted in recorded request; trialing + trial_end≈+14d after webhook | pending |
| AC-003.7 | T3 | PortalTest cancel + PlansEntitlements change→free | free switch immediate, no gateway money intent (cancel only) | pending |
| AC-003.8 | T3 | checkout webhook test asserts same sub row upgraded | one row per org, plan_id/status swap | pending |
| AC-003.9 | T4 | 'signature enforcement and idempotent replay' | forged sig 400 + zero rows; valid 200 processed; replay 200 'duplicate'; 1 row | pending |
| AC-003.10 | T4 | 'invoice events move the mirror' + 'updated events cover…' | payment_failed→past_due+clock; invoice.paid→active+mirror 2 rows; domain events failed/recovered/invoiced observed | pending |
| AC-003.11 | T4 | 'unknown stored as ignored' (CheckoutWebhookTest) | cosmic.rays.beamed stored, no side effects | pending |
| AC-003.12 | T4 | 'billing:ingest replays…' + DunningTest 'billing:sync' | pulled events flow through same pipeline, duplicate-safe; sync idempotent + skips ghost orgs | pending |
| AC-003.13 | T5 | PortalTest 'subscription snapshot' | full shape incl. over_limit_until/is_over_limit/entitlements | pending |
| AC-003.14 | T5 | 'preview then change' + StripeGatewayTest preview | lines −/+ int minor units, total_due; recorded POST /v1/invoices/create_preview | pending |
| AC-003.15 | T5 | same | change applies (business), same-plan 422, ghost 404 | pending |
| AC-003.16 | T5 | 'payment methods lifecycle' | setup 201 {client_secret,url}; attach→2; default swap; detach default-while-active 422; ghost 404 | pending |
| AC-003.17 | T5 | 'invoices mirror + download' | list from mirror after webhook; download url; foreign 404 | pending |
| AC-003.18 | T5 | 'cancel-at-period-end and resume' | true→ok, dup true 422, false resumes; free 422 | pending |
| AC-003.19 | T6 | DunningTest + 'stamps over-limit' | billing:dunning downgrades past_due>7d to free (active, clock cleared); below-caps → over_limit_until set too | pending |
| AC-003.20 | T6 | 'recent past_due untouched; recovery' | 1-day past_due survives sweep; recovery resets | pending |
| S6-schedule | T6 | 'dunning scheduled hourly' | Schedule events contain billing:dunning expression `0 * * * *` | pending |
| AC-003.3 | T7 | AdminPlansTest CRUD | index/show/store(+prices)/update(+price upsert EUR) w/o deploy; 422 invalid interval/dup code/missing shape; non-super-admin 403; effect immediate on live orgs | pending |
| perms | T8 | M002 MembersRoles + PortalTest role matrix | admin now billing.view+manage; member 403 on portal reads; outsider 404 | pending |
| AC-003.23 | T1–T9 | whole M003 dir: 79 tests | all green under BILLING_DRIVER=fake; StripeGatewayTest uses in-process FakeStripeHttp (ApiRequestor static) — no network anywhere | pending |
| AC-003.24 / 010-S6 | T9 | `time bin/pest` full suite | **261 passed, 1167 assertions, 44.2s total** (< 90s) | pending |
| gates | T9 | harness/scripts/check.sh | pint ✓ phpstan L8 ✓ composer-audit ✓ arch ✓ pest --coverage **Total: 100.0 %** ✓; openapi freshness = pending the commit below (gate is diff-vs-HEAD by design) | pending |

## Deviations & learnings (human-decision-justified where noted)
1. **feature_list 003 step 4** reads "downgrade pending flips at period end". Human Q6 decision (spec.md §Locked decisions 6) supersedes the wording: paid plan downgrades apply immediately with over_limit grace (Policy A); *cancel at period end* still exists (AC-003.18) and flips via `customer.subscription.updated` webhook at period end. Intent (pro-rated upgrades, non-destructive downgrades) verified.
2. **stripe-php v22 API shape** (verified by FakeStripeHttp-recorded requests): `subscriptions->cancel($id)` (service, not instance); upcoming-invoice = `invoices->createPreview` → `POST /v1/invoices/create_preview`; paging via `Collection->autoPagingIterator()`; **no `StripeClient::setHttpClient`** — tests inject via static `ApiRequestor::setHttpClient()`.
3. **Event::fake() breaks Eloquent `creating` callbacks** (dispatcher swap) — webhook/model-event assertions capture via `Event::listen(...)` collectors instead; never fake events around model creation in this repo.
4. **routes/admin-v1.php is mounted standalone** → needed explicit `SubstituteBindings` middleware (route-model binding silently injected empty models before the fix). All future admin routes rely on the corrected group.
5. spatie/laravel-data 4 has no `Integer` validation attribute in this install → entitlement shape guarded by model setter (unknown/missing keys) + admin FormRequest nested rules.
6. Portal routes nest under `/api/v1/orgs/{organization}/billing/*` (org-scoped request family); webhook is public at `/api/v1/billing/webhook/stripe` with its own IP limiter.
7. Stripe Tax left OFF (`billing.tax_enabled=false`) per Q5; nothing in code branches on tax — enabling later is config + Stripe dashboard only.

## Live-smoke evidence (2026-10-06, test-mode keys from saas-engine account)
| item | observed |
|---|---|
| `POST …/billing/checkout` pro/USD (org 1, test@example.com) | **200→201 with real `https://checkout.stripe.com/c/pay/cs_test_a1gt…` URL** — customer + lazy price/product creation in Stripe test account |
| first attempt | 500 via PaymentProviderException — **caught a stub-unverifiable bug**: `recurring.interval` sent `monthly/annual`; Stripe wants `month/year` → added `BillingInterval::stripeInterval()` + unit test + server-side `Log::error('Stripe gateway call failed', …)` in every gateway catch (provider message never returned to clients, per spec) |
| `php artisan billing:ingest` | pulled **4 real events** (price/plan/product/customer.created) through the same idempotent pipeline — all 'ignored' (unknown types, AC-003.11 in production shape) |
| `GET billing/subscription` / payment-methods / invoices | 200 with live Stripe customer; empty PM/invoice mirrors (pre-payment, correct) |
| remaining for human | pay the hosted checkout with 4242… then `billing:ingest` again → expect checkout.session.completed → trialing/active; failure-dunning per steps 4–5 below |

## Human live-smoke checklist (test keys already in src/.env)
1. `src/bin/artisan billing:stripe-mirror` → creates products/prices in Stripe test account, writes back gateway_price_ids.
2. Bruno `Billing/Start checkout` (pro, monthly, USD, org 2 token) → open `data.url` → pay with `4242 4242 4242 4242`.
3. `src/bin/artisan billing:ingest` → events replay through the real pipeline; `GET billing/subscription` shows `active`/`trialing`.
4. Stripe dashboard → test a failed payment → `billing:ingest` again → `past_due`; `src/bin/artisan billing:dunning` respects the 7-day clock (travel time or wait).
5. `src/bin/artisan billing:sync` reconciles invoice mirrors.
