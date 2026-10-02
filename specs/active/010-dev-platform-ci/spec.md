# Spec 010 — Developer Platform & CI

Status: DRAFT — awaiting human approval (constitution #1)
Source scope: ../../feature-scope.md § Module 11 + Architecture & Tooling (LOCKED)
Purpose: the rails every other module runs on — tooling installed & configured, API skeleton + envelope, arch-test pack, docs pipeline, CI = check.sh, PR gates.

## Problem statement
The repo boots in Docker but the verification pipeline (phpstan, arch tests, OpenAPI, TDD gates) is not installed or green. Forkers/agents cannot trust "done" until `check.sh` and CI enforce the constitution mechanically.

## Acceptance criteria (EARS)
Maps 1:1 to `harness/feature_list.json` → features["010"].steps (S1–S6).

- AC-010.1 (S1) WHEN a fresh clone is checked out with no `.env` and no running containers THE SYSTEM SHALL reach a booted, migrated, seeded stack via `harness/init.sh`, AND re-running init.sh immediately SHALL produce no errors (idempotent).
- AC-010.2 (S2) WHEN `harness/scripts/check.sh` is executed THE SYSTEM SHALL run, in order: pint --test, phpstan level 8 (Larastan), composer audit, pest including `tests/Architecture`, scramble export + openapi.yaml freshness diff — AND all steps SHALL pass on a clean tree; AND the GitHub `CI` workflow SHALL invoke exactly `check.sh` (no parallel logic).
- AC-010.3 (S3) IF a pull request touches `src/(app|routes|database|config)/` WITHOUT a diff under `specs/` or `harness/feature_list.json` THEN the `spec-required` PR gate SHALL fail the check.
- AC-010.4 (S4) IF a commit within a PR touches implementation (`src/app`, `src/routes`) without touching `src/tests` THEN the `tdd-order` PR gate SHALL fail the check. (Commits that only add tests, only refactor docs, or touch `specs/` are exempt.)
- AC-010.5 (S5) WHEN routes are registered THE SYSTEM SHALL expose every route in the generated OpenAPI document served at `/docs` (public on dev/staging, super-admin on prod via `DOCS_PUBLIC=false` env), AND a Pest test SHALL assert route-count(openapi) ≥ route-count(router) for JSON route files.
- AC-010.6 (S6) WHEN module feature suites run in CI (FakeGateway for Stripe calls, golden-org fixture for shared setup) THE total test stage SHALL complete in under 90 seconds. *Provability: requires 002/003 to exist — this step flips only at 010-convergence after 003 lands; until then S6 stays `passes:false` with a pointer here.*

### Skeleton criteria (new code introduced by 010 must obey the contract)
- AC-010.7 WHEN `GET /api/v1/ping` is called WITHOUT auth THE SYSTEM SHALL return 200 `{"data":{"pong":true}}` (standard success envelope).
- AC-010.8 WHEN any non-HEAD request to an unauthenticated route fails validation THE SYSTEM SHALL return 422 with `{"message":string,"errors":{field:[messages]}}`; unknown API paths SHALL return 404 JSON (not HTML redirect); 405, 429 (`Retry-After` header present), 401 envelopes SHALL follow `docs/api-conventions.md`.
- AC-010.9 THE architecture test pack SHALL enforce, and pass, at minimum: controllers use no `DB::`/`->save()`/`new Model`; Actions exist only under `App\Actions` and dispatch side effects via events (no mailer/HTTP client calls inside `App\Actions`); every POST/PUT/PATCH/DELETE route in `routes/` is backed by a FormRequest (reflection over injected Request types); no `$request->all()`/`request()->all()` anywhere in `app/`; `App\Billing` is the only namespace permitted to import `Stripe\` or `Laravel\Cashier\`.
- AC-010.10 WHEN a fresh developer copies `.env.example` → `.env` and runs `docker compose up` WITHOUT the compose `environment:` block for connectivity vars THE SYSTEM SHALL still connect (all docker-host defaults — `DB_HOST=pgsql`, `REDIS_HOST`, `MAIL_HOST=mailpit`, `AWS_ENDPOINT`, `REVERB_HOST` — documented in `.env.example`, each with a one-line comment; compose `environment:` retained as single source of truth for CI/stack, `.env` for host-launched tools parity).
- AC-010.11 THE file `src/AGENTS.md` SHALL contain app-level agent guidance for THIS project (docker-only commands via `src/bin/*`, envelope rules, arch-test pointers) and SHALL NOT contain the Laravel installer's default host-install/boost instructions.

## Out of scope (this spec)
Auth, org tenancy, billing gateways, plans, notifications, webhooks, Horizon config beyond install-time queue health (specs 001–009). CI beyond the two existing workflow files. Production deploy story.

## Non-functional
- All new code under the standard stack: PHP 8.4 strict types, pint, Larastan L8 clean.
- Each AC above is proven by at least one named test or a recorded command output in `verification.md`.
- Constitution #2 ordering applies per task: test first, red, then green.
