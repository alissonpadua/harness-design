# Tasks 010 — Developer Platform & CI

One task = one session-sized unit, TDD order inside each. Tick `[x]` only with a commit + evidence in verification.md. AC = spec.md acceptance criterion.

## T1 — Tooling install & config (AC-010.2)
- [x] T1.1 `src/bin/composer require --dev larastan/larastan dedoc/scramble spatie/laravel-data spatie/php-structure-discoverer` (prod deps vs dev per plan); record versions
- [x] T1.2 add `src/phpstan.neon` (level 8) + `src/pint.json`; `check.sh` step `phpstan` + `pint --test` pass on clean tree
- [x] T1.3 confirm pest-plugin-arch present (bundled vs add); `vendor/bin/pest --version` evidence

## T2 — Architecture test pack (AC-010.9)
- [x] T2.1 write RED source-scan tests: mass-assignment scan + Stripe/Cashier-outside-Billing scan, each with a passing fixture string AND a failing fixture string (both directions asserted)
- [x] T2.2 implement scan helpers + fixtures → green
- [x] T2.3 RED→GREEN Pest arch rules: controllers no DB/save/new; Actions only under App\Actions + no mailer/HTTP; write-route FormRequest coverage via Route reflection (add a throwaway `PingController` in T3 to satisfy — sequence with T3)
- [x] T2.4 wire `tests/Architecture` into `check.sh` pest run (already global); ensure green on clean tree

## T3 — API skeleton + standard envelope (AC-010.7, AC-010.8, AC-010.9 route rule)
- [x] T3.1 RED: tests — `GET /api/v1/ping` → 200 `{"data":{"pong":true}}`; 404 path → JSON envelope; 405 → JSON; 422 validation shape; 401 unauthenticated JSON; 429 has `Retry-After`
- [x] T3.2 GREEN: `routes/api.php` `/api/v1` group; `PingController`+`PingAction`+`PongResource`; `bootstrap/app.php` JSON exception render closures; throttle bucket on ping to make 429 testable in isolation
- [x] T3.3 T2.3 route-FormRequest rule passes (ping is GET — exempt; added one guarded example write route only if a rule requires non-empty set, else document exemption)

## T4 — OpenAPI docs + freshness gate (AC-010.5)
- [x] T4.1 RED: test asserting openapi route-count ≥ router count (openapi.json absent → red)
- [x] T4.2 GREEN: `config/scramble.php` (publish optional), `DOCS_PUBLIC` env gate, `scramble:export --path=openapi.json` → commit `src/openapi.json`; `check.sh` freshness diff updated to openapi.json
- [x] T4.3 `/docs` served by Scramble; document super-admin-prod limitation (005 finishes guard). NOTE: export runs default (docs route registration) — prod guard = DOCS_PUBLIC.

## T5 — Environment parity & agent guide (AC-010.10, AC-010.11, AC-010.1)
- [x] T5.1 rewrite `src/.env.example`: all docker hosts, each commented
- [x] T5.2 AC-010.10 proof: cold `/tmp` clone + `init.sh` (no .env) → booted, migrated, seeded, `curl /api/v1/ping` = `{"data":{"pong":true}}`; compose stays source of truth, `.env.example` mirrors container DNS
- [x] T5.3 replace `src/AGENTS.md` with project guide (docker-only, envelope, arch pointers)
- [x] T5.4 AC-010.1: cold-boot rehearsal in scratch dir + idempotent re-run; found+fixed non-idempotent DatabaseSeeder (AC-010.1 real bug catch)

## T6 — CI + PR gates single-source (AC-010.2, .3, .4)
- [x] T6.1 extract PR-gate logic to `harness/scripts/gate-spec-required.sh` + `gate-tdd-order.sh`; workflows call them
- [x] T6.2 both-direction tests: `harness/scripts/selftest.sh` (synthetic git repo, CI job `gate-selftest`) — rejects impl-only & testless-impl, accepts compliant
- [~] T6.3 push branch, open PR → confirm both gates fire on GitHub (needs a remote + human; local selftest is the substitute evidence for now)
- [x] T6.4 `check.sh` == CI parity: workflow only calls `check.sh` (+ selftest job); no duplicated step logic

## T7 — Convergence (after 003 lands)
- [ ] T7.1 golden-org fixture + FakeGateway present → measure module-suite CI time < 90s (AC-010.6); flip feature_list S6

## Definition of done (010)
AC-010.1–.5, .7–.11 green with evidence; AC-010.6 pending convergence (T7) as documented in spec.
