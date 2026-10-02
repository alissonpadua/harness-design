# Plan 010 — Developer Platform & CI

## Packages (via src/bin/composer)
- `dedoc/scramble` (docs, prod-gated via env)
- `larastan/larastan` (dev) + `phpstan.neon` level 8
- `pestphp/pest-plugin-arch` (dev, if not bundled in installed Pest 5)
- `spatie/laravel-data`, `spatie/php-structure-discoverer` (runtime conventions deps)
- `filp/whoops` untouched (default dev handler already JSON-capable for api? no — handled in T3 via exception render)

## Configs/files to create
- `src/phpstan.neon` (level 8, App+Tests paths, Larastan)
- `src/pint.json` (laravel preset + rules per docs/conventions.md)
- `src/tests/Architecture/` — envelope: controllers.b.php, actions.b.php, requests.b.php, billing.b.php, mass-assignment grep test (plain Pest, scans app/ source), form-request coverage test (reflects Route::getRoutes() → controller method signature contains FormRequest subclass for writes)
- `routes/api.php` — `/api/v1` group; `routes/web.php` — `/docs` (scramble) + `/up` placeholder deferred to 005 (NOT added here); ping controller+Action+Resource (first vertical slice proving the flow)
- `bootstrap/app.php` — api routing, JSON exception render closures: 404/405/401/403/422/429 envelope per api-conventions (TestException... implement `App\Exceptions\RenderJson` closures in bootstrap)
- `config/scramble.php` (publish) + docs visibility: register routes only when `config('app.docs_public')` (env DOCS_PUBLIC) or super-admin (guard arrives in 005 → this spec: env flag only; noted limitation)
- `.env.example` rewrite: document every var incl. docker hosts (AC-010.10); prune compose `environment:` duplication ONLY where parity holds — keep compose as truth for CI (AC-010.1/2 unchanged)
- `src/openapi.yaml` generated artifact committed
- `src/AGENTS.md` replacement (AC-010.11)
- CI: `.github/workflows/ci.yml` — add `docker/php/fetch-deps.sh`? not needed (tarballs committed); confirm composer cache? skip
- Golden fixtures dir convention `src/tests/Fixtures/` + `goldenOrg()` helper STUB marked Spec-010 S6 with TODO pointer to 002/003 (helper implemented there; S6 flips at convergence)

## Test-first mapping
- T1: `check.sh` steps green after installs (commands as evidence, no new tests)
- T2: Architecture/*.b.php (write violations as skipped negative fixtures? NO constitution #3 — instead: rules must pass on real tree; negative coverage via fixtures in `tests/Architecture/Fixtures` asserted with `arch->assertFailed`? Pest arch has no red-mode assert → use custom source-scan tests with synthetic string inputs for the grep-based rules (mass assignment, Stripe import) — testable both directions)
- T3: ping + envelope tests (404/405/422/429/401) FIRST (red), then routes/middleware/render closures
- T4: scramble route-count test FIRST (red), then install+export
- T5: cold-boot checklist executed manually w/ recorded output → verification.md

## Risks
- Scramble × Laravel 13 compat — pin latest, fallback: keep gate on committed openapi.yaml regeneration command (documented) if mismatch found in T4.
- Pest 5 arch plugin surface differs from Pest 3 docs — pin versions in T1 evidence.
- GitHub cannot be reached for PR-gate proof from this network (Zscaler) — AC-010.3/4 verified via `act`? NO (host-install ban). Fallback evidence: run the exact bash gate scripts locally against synthetic diffs (make them `harness/scripts/gate-*.sh` so logic is testable locally + called by the workflow — single source of truth).

## Sequencing
T1 tooling → T2 arch pack → T3 api skeleton/envelope (red→green) → T4 docs freshness → T5 env/AGENTS/cold-boot → T6 gate-script refactor + local verification + first PR experiment.
