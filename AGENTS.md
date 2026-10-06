# AGENTS.md — Agent-First Laravel Boilerplate

Laravel API-only boilerplate, 100% agent-developed under SDD + strict TDD.
The Laravel app lives in `src/`. This root is the harness.

## Hard rules (see constitution.md — violations block the task)

1. **No spec → no code.** Every change to `src/` must belong to a task in `specs/active/<feature>/tasks.md`.
2. **TDD:** failing tests first (from acceptance criteria in spec.md / harness/feature_list.json), then implementation. Never weaken/delete a test, spec, or feature_list entry to make it pass.
3. **Never call `php`/`composer`/`artisan` on host.** Use `src/bin/*` (Docker wrappers). Nothing runs outside containers.
4. **Read at session start:** tail `harness/progress.md`, `git log --oneline -15`, then `harness/init.sh` + smoke test.
5. **Write at session end:** one task max in progress, `check.sh` green, PROPOSE commit command(s) per docs/conventions.md — **the agent NEVER runs `git commit`; the human executes them** (`git commit -S -m "..."`), append to `harness/progress.md`, flip `passes` in `feature_list.json` only after real verification.

## Commands

| Task | Command |
|---|---|
| Boot env | `harness/init.sh` |
| Full verification (= CI) | `harness/scripts/check.sh` — incl. `pest --coverage --min=100` (constitution #11) |
| Run tests | `src/bin/pest --filter=...` |
| Migrate/seed | `src/bin/artisan migrate --seed` |
| API docs | http://localhost:8080/docs (dev) |
| Mailpit UI | http://localhost:8025 |

## Gate discipline (learned 2026-10-06, human-enforced)

6. **Never advance on a red gate.** `harness/scripts/check.sh` must print `✔ green` before: declaring work done, starting the next task/module, or proposing commits. "Tests passed earlier" is not evidence — re-run. Small docblock/generic fixes can snowball into 2 hidden Larastan errors.

## Architecture (enforced by Pest arch tests in src/tests/Architecture)

Controller (~5 lines) → FormRequest → `App\Data` DTO → `App\Actions\*Action` → Event → Listener.
Reads via Models/Resources. Details: `docs/architecture.md`, conventions: `docs/conventions.md`.

## Layout

- `constitution.md` — immutable principles (never edit without human approval)
- `docs/` — architecture, api-conventions, adr/ (READ; propose ADR via PR, don't rewrite silently)
- `specs/active/NNN-*/` — spec.md (contract) / plan.md / tasks.md / verification.md
- `harness/` — feature_list.json (acceptance truth), progress.md (journal), scripts/
- `.agents/` — skills (procedures), subagents (reviewers), hooks (guardrails)
- `src/` — Laravel 13 / PHP 8.4 app; app-level guide: `src/AGENTS.md`; API collection: `src/bruno/` (OpenCollection YAML — implemented endpoints only, env `local` = http://localhost:8080)

## Module map

001 Identity & Access · 002 Teams & Tenancy · 003 Billing · 004 Notifications ·
005 Admin & Ops · 006 Security & API · 007 Onboarding Events · 008 Settings &
Personalization · 009 Background & Webhooks · 010 Dev Platform & CI.

- `specs/feature-scope.md` — global locked scope (read-only reference for spec authors)
