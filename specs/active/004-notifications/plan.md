# Plan 004 — Notifications

## Dependencies
Zero new composer packages (database+broadcast channels + Reverb already installed). Queue driver redis for dev; `MAIL_*` → Mailpit already configured (8025 UI).

## Layout
- `app/Notifications/Contracts/CatalogNotification.php` — the per-type contract (type/title/body/mailable/emailDefault/locked)
- `app/Notifications/Types/*` — 12 final readonly classes implementing the contract (one dir, auto-discovered by filename)
- `app/Notifications/NotificationCatalog.php` — registry: glob Types, instantiate via container, index by type()
- `app/Notifications/CatalogNotificationMessage.php` ← concrete Illuminate Notification wrapper (ShouldQueue + Queueable mail/db/broadcast, failed() hook, toArray payload builder) — name: `App\Notifications\NotifiesViaCatalog`? keep `CatalogNotifiable`? DECISION: class name **`CatalogDelivery`**
- `app/Notifications/Mail/Layout` → `resources/views/notifications/layout.blade.php` (markdown component w/ accent config) + shared `components/notification-email.blade.php`
- `app/Actions/Notifications/DispatchNotification.php` (dispatcher, audience resolvers incl. org owner+admins), `UpdatePreferences`, `RetryFailed`, `PruneInbox`
- Listeners `app/Listeners/Notifications/*` thin → queued? listener sync dispatches a queued notification — fine
- Models: `NotificationPreference`, `FailedNotification`; DB notifications via morph ManyMany (DatabaseChannel writes stock table; list endpoint queries it directly w/ json_extract for title/body)
- Commands: `notification:make`, `notifications:retry-failed`, `notifications:trial-reminders` (+ schedule 15min sweep + daily 08:00 in bootstrap)
- Sweeper action `SweepFailedNotifications`
- Migration: notification_preferences + failed_notifications (+ notifications stock table from L12 `php artisan make:notifications-table`? publish vendor migration)
- Routes under v1 (notifications + preferences) in api.php; channels.php created + `->withChannels(...)`? (L13 auto-loads routes/channels.php when present)
- 001 touch: User::sendPasswordResetNotification/sendEmailVerificationNotification overrides → dispatcher; Remove/replace 6 legacy notification classes after re-wiring (delete files, move logic into Types/*, keep Mailable look identical via shared layout)
- 002 touch: OrgInviteNotification replaced by catalog dispatch w/ email-only path (raw email to string recipient via Mail::to? Use temporary Notifiable wrapper `App\Notifications\Recipients\EmailRecipient` implementing hasSlot/mailNotificationRoute returning the address — simplest: notification class supports route override via data)
- Permission catalog: none new (own inbox only).

## Wrapper vs unregistered recipients
`dispatchUser(User)` full 3 channels. Invitee (no user): mail-only — Delivery built with `toOverride: string email` → DatabaseChannel skipped, Broadcast skipped, mail route via overridden `routeNotificationFor('mail')` on a lightweight `App\Notifications\AnonymousRecipient extends Model implements HasNotifications? ` DECISION: `final class MailableRecipient extends Notifiable` stub model (not persisted) — tested via Mail::fake assert to invite address.

## Testing strategy
Queue::fake vs sync: DB+broadcast rows must be written synchronously for list/prune tests → use `Queue::fake()` for mail-assertion tests and `config queue.default=sync`+`Queue::connection sync`? Existing 001 tests assert via Mail::fake + assertQueued patterns — mirror. Broadcast::fake for payload; NotificationSent listener prune test with 101 inserts (loop create DatabaseNotification rows directly then dispatch real → prune runs; seed 100 rows cheap).
Sweep tests with time travel. Trial reminder dedupe w/ two runs.

## Sequencing
T1 schema+contract+registry+2 seed types+dispatcher+wrapper (S1 core)
T2 prefs matrix API + toggles + locked (S4)
T3 mail layout + remaining 10 types + 001/002 migration rewiring (S2, AC-004.10/.11)
T4 broadcast+database channels + list/prune endpoints (S3, AC-004.5-.7)
T5 failed_notifications + sweep + retry cmd (S5)
T6 billing listeners + trial reminders scheduler (AC-004.12/.13)
T7 notification:make stub + arch rule (AC-004.2)
T8 bruno/openapi/docs
T9 convergence+flips
