# Tasks 003 — Billing & Monetization

TDD (constitution #2). AC = spec.md. Definition of done: S1–S6 evidenced here + 010 S6 flip.

## T1 — Data model + gateway contract + FakeGateway
- [x] T1.1 RED: schema/enum/money-int tests (plans, plan_prices unique (plan,currency,interval), billing_customers, subscriptions one-active, invoices, webhook_events unique gateway_event_id; plans.entitlements JSON typed cast strict)
- [x] T1.2 GREEN: migrations + models + PlanEntitlementsData + contracts (PaymentGateway + data shapes) + config/billing.php + FakeGateway (in-memory, HMAC-signed fake ingest) + billing.driver binding
- [x] T1.3 GREEN: seeder 3 plans × 3 currencies × 2 intervals exact table, idempotent (AC-003.1)

## T2 — Bootstrap subscriptions + plan-backed entitlements + over-limit (AC-003.2/.21/.22)
- [x] T2.1 RED: free sub auto (org create + backfill), maxTeams/maxMembers from plan, over_limit set on downgrade below usage, adds 402 during+after grace, clear on compliant change
- [x] T2.2 GREEN: PlanOrgEntitlements + rebind, EvaluateOverLimit action, hook into 002 flows via interface only

## T3 — Checkout, trial, switch-to-free (AC-003.5–.8)
- [x] T3.1 RED: checkout validation matrix (unknown/inactive/free/currency/already-subscribed), trial via subscriptionFor (card required → status trialing, trial_end), free immediate, one-active invariant
- [x] T3.2 GREEN: StartCheckout/CreateSubscriptionTrial/SwitchToFree + routes + throttle billing

## T4 — Webhooks + dev ingest/sync (AC-003.9–.12)
- [x] T4.1 RED: bad signature 400 no-store; valid stores+dispatches once; replay idempotent; each event type mutates mirror (checkout/invoice.paid/payment_failed/updated/deleted); unknown stored-no-op; billing:ingest replays pullRecentEvents through same path; billing:sync reconciles
- [x] T4.2 GREEN: ProcessWebhook + EventNormalizer + WebhookSignature + stripe route + 2 commands

## T5 — Portal (AC-003.13–.18)
- [x] T5.1 RED: subscription GET shape; preview lines math (fake deterministic); change apply + same-plan 422; PM list/setup/default/detach incl. default-while-active 422 + foreign 404; invoices list/download foreign 404; cancel/resume toggles
- [x] T5.2 GREEN: portal actions/controllers/requests

## T6 — Dunning (AC-003.19/.20)
- [x] T6.1 RED: past_due>7d → free downgrade + events + fields cleared; recovery zeroes; healthy untouched; command schedulable + hourly registration test
- [x] T6.2 GREEN: RunDunning action + billing:dunning command + schedule

## T7 — Admin plan CRUD (AC-003.3/.4)
- [x] T7.1 RED: /admin/v1/plans index/show/store/update + price upsert; shape validation 422; super-admin gate (403 for user)
- [x] T7.2 GREEN: actions/controller/requests + RolesSeeder permissions wiring

## T8 — Permissions + client surfaces
- [x] T8.1 billing.manage catalog + org_roles update + spec-mandated removal of billing.view from member/viewer (regression on 002? none uses billing)
- [x] T8.2 bruno/billing requests (checkout, subscription, preview, change, cancel/resume, PMs, invoices, webhook-fake, admin plans), stripe-mirror command for live price provisioning, openapi regen

## T9 — Convergence
- [x] T9.1 full suite timing evidence (010 S6 flip, <90s) + openapi freshness + 100% coverage
- [x] T9.2 verification table for 003 ACs + human live-smoke checklist (test keys present; run mirror → checkout in browser → billing:ingest → observe) in verification.md
- [x] T9.3 feature_list 003 S1–S6 flips + README roadmap update (003 ✅, 010 ✅)
