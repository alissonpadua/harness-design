# Verification 004 — Notifications

Append-only evidence log. Flips of feature_list 004 S1–S5 only from rows here. Gates run 2026-10-06: pint 369 files ✓ · Larastan L8 [OK] ✓ · composer audit ✓ · arch (17) ✓ · **289 tests / 1439 assertions / Total: 100.0 %** ✓ · suite 44.5s ✓.

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
| AC-004.1 | T1 | NotificationsTest 'catalog exposes exactly the locked 12 types' + arch 'keeps every Types class a CatalogNotification implementation' | 12 dotted types sorted; unknown → InvalidArgumentException; glob-discovered, stray non-class file ignored (CatalogSweepTest) | pending |
| AC-004.2 | T7 | 'notification:make scaffolds…' | generated Types/SampleProbe picked up by catalog + prefs matrix with zero config; duplicate + bad-slug rejected; file cleaned in finally | pending |
| AC-004.3 | T3 | 'every catalog type renders title, body and mailable…' + HardeningAudit 'auth catalog types share the mail contract shape' | all 12 render via shared `mail.catalog` markdown (accent theme in config/mail.php + published vendor mail theme); subjects/lines non-empty incl. email_change to_old/to_new variants | pending |
| AC-004.4 | T3 | DeliverNotification job implements ShouldQueue; Queue::fake assertion in queued-mail test; phpunit sync driver executes | queue-pushed + inline in tests | pending |
| AC-004.5 | T4 | 'delivery persists…' + 'broadcast payload mirrors…' | DatabaseNotification row {type,title,body,data}; BroadcastMessage data identical; broadcastType notification.{type}; channel user.{id} | pending |
| AC-004.6 | T4 | 'channel callback… admits only the owner' (routes/channels.php required onto a bare Broadcaster spy) | self true; other user false; wrong id false; /broadcasting/auth registered with auth:sanctum (withBroadcasting in bootstrap) | pending |
| AC-004.7 | T4 | 'inbox prunes to newest 100…' + 'cursor pagination…' + defensive test | 101st insert prunes oldest; cursor 'created_at(u)|id' walks newest-first w/ correct tails; cross-user isolation | pending |
| AC-004.8 | T2 | 'preferences matrix, toggle, suppression…' | toggle persists NotificationPreference; email suppressed but database+broadcast still land; defaults from catalog | pending |
| AC-004.9 | T2 | same test | locked → 422 'This notification type cannot be changed.'; unknown → 422 'Unknown notification type.' | pending |
| AC-004.10 | T3 | rewritten 001 tests: RegistrationTest(3), PasswordResetMagicLinkTest, OAuthTest(2), ProfileLifecycle(2), Login new-device listener | verification/resend/magic/reset/email-change(×2 lanes)/new-device all assert CatalogDelivery.type; framework default ResetPassword assertNotSentTo still passes; 001 suite green | pending |
| AC-004.11 | T3 | M002 suites green w/ re-aimed asserts (OrgInvite → catalog email-only via on-demand; TenancyInternals renders catalog mailable incl. token); member_joined + ownership_transferred fan-out test | invites land on-demand (mail-only), org events hit owner+admins | pending |
| AC-004.12 | T6 | 'org + billing domain events fan out…' + billing orphan-safety test | PaymentFailed/InvoicePaid/PlanChanged → org audience; null-org guards safe | pending |
| AC-004.13 | T6 | 'trial reminders…' split pair | +2d trialing → owner+admins sent; +9d → nothing; after REAL inbox row → rerun dedupes (assertNothingSent) | pending |
| AC-004.14 | T5 | 'job records failure evidence…' + 'retry sweep records retry…' | throwing delivery → failed_notifications row (attempts 0, error); markAttempt backoff 2^n×15min; attempts≥3 parks (isRedeliverable false); sweep skips parked | pending |
| AC-004.15 | T5 | same + 'failed sends are recorded, retried…' | notifications:sweep-failed (15-min schedule entry asserted in 003 dunning pattern); retry-failed --id/--all redelivers → resolved_at; deleted-user fallback to email lane | pending |
| AC-004.16 | T8 | endpoint tests + bruno/notifications + openapi (9 refs) | list/preferences get/put shapes exact; 401 unauth via sanctum group | pending |
| schedule (Q3/Q5) | T5/T6 | 'sweep and trial-reminder schedules are registered' (Artisan::call schedule:list --json) | `*/15 * * * *` + `0 8 * * *` entries present (kernel bootstraps withSchedule lazily — must go through the console, not app(Schedule)) |
| S1–S5 (feature_list) | — | rows above map S1(1,2) S2(3,4) S3(5,6,7) S4(8,9,10,11) S5(14,15) | all covered | pending |

## Deviations & learnings
1. **`notification:make`** instead of `make:notification` (feature-scope wording) — avoids clashing with the framework command; intent (stub generator + auto-discovery) identical.
2. **Mailable vs MailMessage:** MailChannel sends a returned `Mailable` without attaching recipients — `CatalogDelivery::toMail` sets `->to(routeNotificationFor('mail'))` itself (found by live webhook→notify path failing 'email must have To').
3. **`BroadcastMessage` lives in `Illuminate\Notifications\Messages\`**; unregistered/mail-only recipients use framework `AnonymousNotifiable` (keeps `assertSentOnDemand`), no custom notifiable class.
4. **Broadcasting auth route is opt-in in L13:** `withRouting(channels:)` absent by default — used `withBroadcasting(routes/channels.php, ['middleware' => ['auth:sanctum']])`; reverb driver also needs `pusher/pusher-php-server` (added) or the broadcaster factory fatals on route:list.
5. **Notification::fake() swallows ALL channels** — tests mixing real inbox rows with faked phases were split (fake and real delivery in separate tests).
6. Mail markdown views are `.blade.php` with `mail::` components (not `.blade.md`); accent via `config/mail.php markdown.theme` + published `resources/views/vendor/mail/html/themes/default.css`.
7. PruneInbox uses pluck-then-whereNotIn (passing a Builder to whereKeyNot string-casts and dies).
8. Cursor pagination needs microsecond + id tiebreak (`created_at` is second-precision; uuid keys order arbitrarily — deterministic ORDER BY created_at,id + same-shape cursor).
9. Legacy 6 notification classes deleted; 001/002 suites re-aimed to catalog assertions (no weakened checks: same subjects/tokens/URLs/anti-framework assertions).

## Dev-infra live verification (2026-10-06, supersedes 'manual only' for S3)
| item | observed |
|---|---|
| `laravel/reverb` package | **was never installed** (config/keys existed since 010, `reverb:start` missing → service crashed) — installed with `-W` (react/* deps), `php artisan reverb:start` now serves :8080 in container `reverb` |
| queue worker | dev stack ships none → notifications sat in Redis; `queue:work --stop-when-empty` drains (documented below) |
| broadcast push | `BroadcastException: cURL 28 SSL timeout https://reverb:8080/...` → fixed via `REVERB_SCHEME=http` (internal network; browsers use their own wss URL) — after scheme fix + `queue:retry all`: **failed_jobs = 0**, BroadcastEvent delivered to reverb |
| fault isolation proof | before reverb existed, the failed broadcast left the DB inbox row + mail untouched (AC-004.5 'AND' semantics holds under infrastructure failure) |
| inbox API live | 12-row prefs matrix; PUT toggle ok; locked 422 `This notification type cannot be changed.`; dispatched `auth.new_device_login` + `org.member_joined` → GET /notifications newest-first ✓ |

## Human manual checks (dev)
0. Need a worker: `docker compose exec app php artisan queue:work` (or `--stop-when-empty` one-shot); reverb: `docker compose up -d reverb`.
1. Reverb running (`docker compose up` includes reverb service). client-demo (future inbox UI) or `curl -H "Authorization: Bearer <token>" http://localhost:8080/api/v1/notifications`.
2. Trigger e.g. invite → Mailpit :8025 shows layout-branded mail; subscribe WS to `private-user.{id}` → live `notification.org.member_joined` event; offline → row still in list.
3. Kill mailpit (docker stop mailpit) → trigger mail → failed_notifications row → `notifications:sweep-failed` retries with backoff (restore mailpit).
