# Architecture

Laravel 13 · PHP 8.4 · API-only · PostgreSQL · Redis. Decisions: see `adr/`.

## Request lifecycle (write path)

```
Route (/api/v1|/admin/v1) → middleware (auth, org, throttle)
  → FormRequest        validation + authorization, nothing else
  → App\Data\*Data     DTO (spatie/laravel-data) built from validated()
  → App\Actions\*Action  ~30-60 lines, one public method, all domain writes
  → Event              App\Events\* (dispatched by the Action)
  → Listeners          notifications (M4), webhooks (M10), side effects
  → API Resource       response shape
```

## Read path
Controllers may query Models directly (with scopes + eager loads) and return Resources. No Action for reads unless multi-source.

## Hard rules (Pest arch tests enforce — src/tests/Architecture)
- `App\Actions\*` live only in `app/Actions`; Actions contain no `->save(`/`->create(` outside models/repositories they own... (keep pragmatic: DB writes allowed, HTTP/mailer calls NOT — dispatch events instead)
- Controllers: no `DB::`, no `->save()`, no `new Model` — `Arch::expect('App\Http\Controllers')->toUseNothingExcept([...])`
- Every write route has a FormRequest (CI greps `routes/` — non-negotiable)
- No `$request->all()`; guarded mass assignment via `$fillable`
- `BelongsToOrganization` trait required on all org-scoped models
- Domain code imports `Contracts\PaymentGateway`, never `Laravel\Cashier` directly (Cashier allowed only inside `App\Billing\Stripe`)

## Tenancy (spec 002)
Single DB, `organization_id` + `App\Models\Scopes\OrganizationScope` (queries without an explicit organization constraint are fail-closed to `users.current_organization_id`; console-without-auth exempt). Membership = `organization_user` pivot (role enum owner/admin/member/viewer + status active/suspended); permission checks through `App\Auth\OrgAuthorizer` reading `config/org_roles.php`. Invitation tokens (email + link) are sha256-hashed single-use. Entitlements behind `Contracts\Org\OrgEntitlements` (config-backed; spec 003 rebinds to DB plans). Org-scoped domain models adopt the `BelongsToOrganization` trait.

## Key domain contracts
- `Contracts\PaymentGateway` (+ `StripeGateway`, `FakeGateway`) — M3
- `Contracts\SmsChannel` — DOES NOT EXIST (cut); notification channels = mail + broadcast only
- `OrgScopedJob` trait — queued jobs bind `current_organization`
- Notification catalog class per type (`type` string, via(), `locked` flag)
- Plans live in DB (`plans`, `plan_prices`, `entitlements` JSON cast) — M3

## Auth model
Sanctum DB tokens. `device_type` tokens (1 per type) + ability-scoped integration tokens. `users.current_organization_id` persisted (switch endpoint updates it). Roles: spatie global plane; org roles on pivot.

## Realtime
Reverb (ws :8081), private `user.{id}` channels; notifications also persisted for offline fetch.
