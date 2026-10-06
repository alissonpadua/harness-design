# Spec 004 — Notifications

Status: APPROVED (Q1–Q6 human sign-off + “go”, 2026-10-06) — SHIPPED, see verification.md
Source: ../../feature-scope.md § Module 4 v2 (LOCKED) + Q1–Q6 decisions 2026-10-06
Maps to: feature_list 004 S1–S5.

## Locked decisions (Q&A 2026-10-06)
1. **Q1=A** — migrate existing 001/002 mails into the catalog; security types `locked`
2. **Q2** — 12-type catalog (below), defaults as tabled
3. **Q3=A** — failed sends: scheduled sweep every 15 min, max 3 attempts w/ backoff, then parked; PLUS manual `notifications:retry-failed [--id=|--all]`
4. **Q4** — in-app persistence keeps latest **100/user** (prune on insert); list = `GET /api/v1/notifications?cursor=`, no read/unread
5. **Q5** — org/billing types → **owner + org admins**; trial reminder daily **08:00 UTC**, fires when trial_end ≤ 3 days, once per org
6. **Q6=A** — one shared minimal Markdown layout (app name + accent from config); types override subject/body only

## Catalog (class per type; `type` string, channels, email default, locked)
| type | class | email default | locked | audience |
|---|---|---|---|---|
| auth.email_verification | EmailVerification | ✓ | 🔒 | user |
| auth.password_reset | PasswordReset | ✓ | 🔒 | user |
| auth.magic_link | MagicLink | ✓ | 🔒 | user |
| auth.new_device_login | NewDeviceLogin | ✓ | no | user |
| auth.email_change | EmailChange (variants to_old/to_new) | ✓ | 🔒 | user ×2 |
| org.invite_received | OrgInviteReceived | ✓ | no | invited user (email to invite address, unregistered ok) |
| org.member_joined | OrgMemberJoined | ✓ | no | owner+admins |
| org.ownership_transferred | OwnershipTransferred | ✓ | 🔒 | owner+new owner |
| billing.payment_failed | PaymentFailed | ✓ | no | owner+admins |
| billing.invoice_paid | InvoicePaid (receipt-ish) | ✓ | no | owner+admins |
| billing.plan_changed | PlanChanged | ✓ | no | owner+admins |
| billing.trial_ending_soon | TrialEndingSoon | ✓ | no | owner+admins |

All types also deliver **in-app (database row + Echo broadcast)** — those channels are always on (locked scope).

## Architecture
- **Contract** `App\Notifications\Contracts\CatalogNotification`: `type(): string`, `title(array $data): string`, `body(array $data): string` (plain-text/markdown), `mailable(array $data): Mailable`, `locked(): bool`, `emailDefault(): bool`.
- **Registry** `App\Notifications\NotificationCatalog` (service): auto-discovers catalog classes in `app/Notifications/Types/`; `all()`, `get(type)`; unknown type → `InvalidArgumentException`. Preferences matrix = registry + user rows (new types appear without migration — S1).
- **`CatalogNotification`** — ONE concrete Illuminate notification wrapping a catalog entry + payload: `via()` → ['mail','database','broadcast'] minus email when preference disabled (locked ⇒ mail always); `toMail` → catalog mailable; `toArray` → `{type, title, body, data}` (notifications.data); `toBroadcast` → same array, `broadcastType = 'notification.'.$type`, private channel `user.{notifiableId}`; `failed(Throwable)` → records `failed_notifications`.
- **`Dispatcher`** action: `dispatchUser(User, type, data)` / `dispatchOrg(Org, type, data)` (owner + members with `billing.manage` for `billing.*` and `org.update` holders? NO — org types audience = owner + org **admin** role holders via membership role column; transfer adds new owner; invite targets email-string "user" — unregistered invited users receive email only via direct `Notification::send` on the invite e-mail (temporary notifiable path preserved from 002: keep OrgInviteNotification behavior, but it must route through catalog type org.invite_received — the invitee has no User row yet → preferences n/a, email always + no DB row until registered… decision: email-only for unregistered).
- **Preferences**: table `notification_preferences (user_id, type, email_enabled)` unique(user,type); row exists only on deviation; PUT validates type known + not locked (422 `This notification type cannot be changed.`); GET returns full matrix (type, locked, email_default, email_enabled).
- **Wiring** (Listeners — thin, call Dispatcher): UserRegistered→email_verification; PasswordResetLink sent→catalog mail (override `User::sendPasswordResetNotification`); MagicLinkRequested; OtherLoginDetected→new_device_login; EmailChangeRequested→email_change×2; MemberInvited→invite_received; MemberJoined; OwnershipTransferred; Billing events (PaymentFailed/Recovered?, InvoicePaid, SubscriptionPlanChanged→plan_changed, SubscriptionCanceled→? NO — cancel is status not notice; keep 4 billing types) + scheduled `notifications:trial-reminders` 08:00 UTC.
- **Failure**: `failed_notifications (id, type, notifiable_type/id, payload json, attempts, error, next_retry_at nullable, resolved_at nullable, failed_at)`. Sweep every 15 min: `attempts<3 AND (next_retry_at null OR <=now)` → resend, attempts++, backoff `2^attempts × 15min`; exhausted → next_retry_at null & resolved_at null = parked (retry via manual cmd with --all overrides attempts).
- **Retention**: after DB-channel insert, prune user rows beyond latest 100 (single delete w/ offset subquery; listener on `DatabaseNotificationCreated`? Use afterSend hook in CatalogNotification via listener on Laravel's `Illuminate\Notifications\Events\NotificationSent` for database channel).
- **`make:notification`**: our stub command `php artisan make:notification {Name} {--type=}` generates `Types/{Name}.php` + test skeleton + registers nothing else (auto-discovery). Name collision with core command → ours overrides by registering first OR name it `notification:make`. Decision: implement as **`notification:make`** (core command stays untouched; spec feature-scope says "make:notification stub command" — alias note in verification).
- **channels.php**: create `routes/channels.php` with `Broadcast::channel('user.{id}', fn ($u, $id) => (int) $u->id === (int) $id);` + wire in bootstrap `withChannels` if not auto.

## API (auth, v1)
- `GET /api/v1/notifications?cursor=&limit=50(max100)` → `{data:{notifications:[{id, type, title, body, data, created_at}], next_cursor}}` (own rows only)
- `GET /api/v1/notifications/preferences` → matrix
- `PUT /api/v1/notifications/preferences` `{notifications: {type: {email: bool}, …}}` partial; unknown/locked → 422
- throttle: standard api group; prefs PUT `throttle:billing`-like 30/min.

## Acceptance criteria (EARS) — maps to S1–S5
- AC-004.1 Catalog contains exactly the 12 locked types; registry discovery finds new Type class without config change (S1).
- AC-004.2 `notification:make Foo` scaffolds a compiling Types/Foo with unique type string; appears in preferences matrix immediately (S1).
- AC-004.3 Every type renders email via shared layout (subject+lines override only); Mailpit receives queued mail with accent/layout markup (S2).
- AC-004.4 All sends are queued (Queue::fake asserts job count for a dispatch; sync in tests).
- AC-004.5 Broadcast payload `{type,title,body,data}` lands on private `user.{id}` (Broadcast::fake) AND row persists in `notifications` (S3).
- AC-004.6 Unauthenticated socket cannot subscribe to another user's channel (channel callback true/false matrix).
- AC-004.7 GET list returns only own notifications, cursor-paginated, newest-first; persisted rows pruned to latest 100 (boundary test: create 101 → oldest gone) (S3/Q4).
- AC-004.8 Preferences: default from catalog; per-user toggle persists; disabled email still delivers DB+broadcast (S4).
- AC-004.9 Locked types reject toggle 422 `This notification type cannot be changed.`; unknown type 422 (S4).
- AC-004.10 Migrated 001 flows now route through catalog: verification, reset (override), magic link, email-change (×2 variants), new-device-login — existing 001 tests still pass (mail assertions re-aimed at catalog), no behavioural regression (Q1).
- AC-004.11 002 flows: invite (email-only for unregistered), member_joined + ownership_transferred to owner+admins (registered users get 3 channels) (Q1/Q5).
- AC-004.12 Billing listeners: payment_failed, invoice_paid, plan_changed → owner+admins; dedupe when acting user IS the only audience member? NO — still notify (simple, spec'd here).
- AC-004.13 `trial-reminders` command: orgs with `status=trialing AND trial_end BETWEEN now AND now+3d` and no prior `billing.trial_ending_soon` for that subscription → notify owner+admins once; idempotent on re-run (Q5).
- AC-004.14 Notification failure → failed_notifications row (attempts=0, error text); sweep retries ≤3 with exponential next_retry_at; parked after 3 (S5).
- AC-004.15 `notifications:retry-failed` (--all or --id) redelivers and sets resolved_at; parked rows require manual --all override (S5).
- AC-004.16 API surfaces: list + GET/PUT preferences shapes exactly as spec'd; unauthenticated 401; cross-user list isolation.

## Non-functional
Every AC ≥1 Pest test in `tests/Feature/M004_Notifications/`; TDD; 100% app coverage; arch tests updated: notification classes under `app/Notifications/Types` must implement the contract (new arch rule); no direct `->notify(` calls outside Dispatcher/NotifyUser action (extend existing scan); openapi regenerated; bruno/notifications folder; verification.md incl. Mailpit screenshot-check note + Reverb subscribe manual step.

## Micro-decisions baked
1. `notifications` table = stock Laravel schema (uuid, morphs, json data, read_at unused-nullable).
2. Broadcast uses Laravel's built-in `broadcast` channel (event `notification.{type}`) — client subscribes `private-user.{id}`.
3. Org audience = membership.role IN (owner, admin) evaluated at dispatch time.
4. Email for unregistered invitees stays email-only (no DB/broadcast rows for ghosts).
5. Trial reminder dedupes on `(type, data->sub_id)` via notifications table lookup.
6. New arch rule: `App\Notifications\Types\*` implement CatalogNotification contract; dispatcher is the single notify() caller.
