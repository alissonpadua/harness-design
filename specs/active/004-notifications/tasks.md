# Tasks 004 — Notifications

TDD (constitution #2); AC = spec.md. Done = S1–S5 evidenced.

## T1 — Core: schema, contract, registry, dispatcher
- [x] T1.1 RED: migrations (notification_preferences unique user+type; failed_notifications cols; stock notifications table) + registry finds Types dir classes by contract (temp fake type); AC-004.1 discovery test
- [x] T1.2 GREEN: contract, CatalogDelivery wrapper (via/queued/failed), NotificationCatalog, DispatchNotification (user + org audiences), 2 pilot types (auth.magic_link + billing.payment_failed)

## T2 — Preferences (S4)
- [x] T2.1 RED: GET matrix shape (type/locked/email_default/email_enabled), PUT partial, toggle persists & suppresses email only (db+broadcast still land), locked 422 msg, unknown 422, foreign type key 422 — AC-004.8/.9
- [x] T2.2 GREEN: UpdatePreferences action + routes + requests

## T3 — Mail pipeline + full catalog (S2, Q1 migration)
- [x] T3.1 RED: shared layout asserts (accent + app name present in all 12 renders), markdown subject/line overrides; queued delivery asserted
- [x] T3.2 GREEN: 10 remaining Types + layout blade + wire 001 (overrides for reset/verification, magic link listener, email_change ×2, new_device_login) + 002 (invite email-only via MailableRecipient, member_joined, ownership_transferred); legacy notification classes deleted; 001/002 suites updated to catalog assertions, no behaviour regressions — AC-004.3/.4/.10/.11

## T4 — In-app: broadcast + persist + list (S3)
- [x] T4.1 RED: Broadcast::fake payload+channel user.{id}; DB row content; list shape/cursor/ownership; prune@100 boundary; channels.php callback matrix (self true/other false/anonymous rejected) — AC-004.5/.6/.7/.16
- [x] T4.2 GREEN: toBroadcast + NotificationSent prune listener + list endpoint + channels route

## T5 — Failure capture + retry (S5)
- [x] T5.1 RED: wrapper failed() records row; sweep retries ≤3 w/ 2^n×15m next_retry_at; parks; manual cmd --id/--all incl. override parked; resolved_at set on success — AC-004.14/.15
- [x] T5.2 GREEN: actions + commands + 15-min schedule entry

## T6 — Billing notifications + trial reminders
- [x] T6.1 RED: PaymentFailed/InvoicePaid/PlanChanged listeners → owner+admins inbox; trial-reminders window logic + once-only dedupe + idempotent rerun — AC-004.12/.13
- [x] T6.2 GREEN: listeners + trial cmd + 08:00 UTC schedule

## T7 — Generator + arch
- [x] T7.1 RED: notification:make scaffolds compiling class w/ type slug; registry+prefs pick it up (AC-004.2); arch rule: Types implement contract, no raw ->notify( outside dispatcher/catalog delivery
- [x] T7.2 GREEN: stub command + SourceRules addition

## T8 — Surfaces
- [x] T8.1 bruno/notifications (list, prefs get/put, invite email-only), openapi regen, docs/api-conventions note re notification payload contract

## T9 — Convergence
- [x] T9.1 full gates (check.sh green incl. 100% cov), suite time sane
- [x] T9.2 Mailpit manual render note + Reverb manual subscribe checklist in verification.md; feature_list 004 flips; README roadmap
