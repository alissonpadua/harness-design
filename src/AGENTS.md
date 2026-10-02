# AGENTS.md — src/ (Laravel app)

Part of the agent-first boilerplate. **Root `../AGENTS.md` and `../constitution.md` govern.**

## Non-negotiables (app-level)

1. **Nothing runs on the host.** Not even PHP or Composer. Use the wrappers:
   `bin/artisan`, `bin/composer`, `bin/pest`, `bin/pint`, `bin/phpstan`, `bin/scramble`, `bin/up|down|logs|psh`.
   (The Laravel installer left generic host-install advice in older revisions of this file — ignore it.)
2. **Write flow:** `Route → FormRequest → App\Data DTO → App\Actions\*Action → Event → Listener`, output via `App\Resources`. Reads may use Models+Resources from the controller directly.
3. **Every acceptance criterion gets a test before implementation** (constitution #2). Feature tests live in `tests/Feature/M<NNN>_<Area>/` (dir names must not start with a digit — Pest 5 limitation).
4. **Architecture is enforced by `tests/Architecture`** — file placement and forbidden calls fail the suite; do not move code to dodge a rule, change the rule via ADR + human approval.
5. **Envelope:** success `{"data": ...}` (auto-wrapped, `ApiEnvelope` middleware), errors `{"message", "errors"}` via `ApiErrorRenderer`. Never `response()->json($model->toArray())` ad hoc in controllers.
6. **Org-scoped models:** use the `BelongsToOrganization` trait (arrives with spec 002); `current_organization` resolves from `users.current_organization_id`.
7. **Billing code imports only `Contracts\PaymentGateway`** — Stripe SDK/Cashier are legal only inside `app/Billing/`.
8. **Readonly by default:** every class without mutable state is `final readonly` (Actions + Middleware enforced by `tests/Architecture`). Extending a mutable framework base (Model, JsonResource, Controller) → `final` + `readonly` promoted properties. Mutability needs a one-line justification.
9. Run `bash ../harness/scripts/check.sh` before declaring any task done; then commit + journal (`../harness/progress.md`).

## Useful commands (all in-container via wrappers)

```
bin/artisan migrate:fresh --seed
bin/pest --filter=Billing
bin/scramble            # regenerate openapi.json (commit it)
bin/psh                 # psql shell
```

## Stack map

- `app/Actions app/Data app/Resources app/Events app/Listeners app/Billing app/Enums` — domain layers
- `routes/api.php` (`/api/v1`) · `routes/admin-v1.php` (from spec 005)
- `tests/Architecture` rules are documentation — read them before restructuring
- Docker/Compose: `docker-compose.yml` (single source of truth, dev==CI), `docker/` build context
