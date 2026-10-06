# Agent-First Laravel Boilerplate

A Laravel 13 / PHP 8.4 **API-only** boilerplate that is developed **100% by AI agents** under a spec-driven (SDD) + strict TDD process. Humans never review line-by-line diffs — they approve **specs**, **implementations (via PRs)**, and **commits**. The repo itself is the agent's brain: contracts, working state, guardrails and verification all live in version control.

> Feature scope (locked, read-only): [`specs/feature-scope.md`](specs/feature-scope.md)
> Immutable rules: [`constitution.md`](constitution.md)
> Agent entrypoint: [`AGENTS.md`](AGENTS.md)

---

## Stack

| Layer | Choice |
|---|---|
| Runtime | Laravel 13 · PHP 8.4 · **Docker only** (nothing on host — ADR-0008) |
| DB / cache / queue | PostgreSQL 16 · Redis · Horizon |
| Realtime | Laravel Reverb (Echo-compatible ws) |
| Auth | Sanctum (device tokens) · spatie/permission · pragmarx/google2fa · asbiin/laravel-webauthn · Socialite |
| API contract | **Pest 4 arch tests + Larastan L8 + Scramble** (`openapi.json` freshness-gated) |
| Mail / S3 (dev) | Mailpit · RustFS (see ADR-0010) |
| API client collection | Bruno — `src/bruno/` (OpenCollection YAML, implemented endpoints only) |

## Quickstart

```bash
harness/init.sh                 # compose up → composer install → key → migrate → seed (idempotent)
bash harness/scripts/check.sh   # the full verification pipeline (identical to CI)
```

- API: http://localhost:8080 (`/api/v1/ping` is the smoke test)
- OpenAPI UI: http://localhost:8080/docs (`DOCS_PUBLIC=true` in dev)
- Mailpit: http://localhost:8025 · S3 console: http://localhost:9001
- Demo user: `test@example.com` / `Str0ng!Passw0rd` (verified, personal workspace pre-created)

Every PHP/Composer/artisan command goes through the Docker wrappers in `src/bin/`:

```bash
src/bin/pest --filter=Billing
src/bin/artisan migrate --seed
src/bin/scramble            # regenerate openapi.json (must be committed with route changes)
src/bin/up | down | logs | psh
```

---

## Repository layout

```
.
├── AGENTS.md                 # THE agent entrypoint (<100 lines) — CLAUDE.md symlinks to it
├── constitution.md           # 11 immutable principles — only a human edits them
├── docs/                     # slow knowledge: architecture, conventions, api-conventions, adr/
├── specs/
│   ├── feature-scope.md      # global locked scope (approved module-by-module in chat)
│   ├── active/NNN-<name>/    # spec.md (contract) · plan.md · tasks.md · verification.md
│   └── archive/              # shipped specs land here = definition of "done"
├── harness/
│   ├── feature_list.json     # acceptance truth: every step, `passes:false` until proven
│   ├── progress.md           # append-only session journal (the agent's memory across sittings)
│   ├── init.sh               # idempotent cold boot
│   └── scripts/
│       ├── check.sh          # pint → larastan L8 → composer audit → pest + 100% coverage → openapi freshness  (= CI)
│       ├── gate-spec-required.sh   # PR gate: src/ changes need a specs/ diff
│       ├── gate-tdd-order.sh       # PR gate: impl commits must carry tests
│       └── selftest.sh             # both-direction tests for the gates themselves
├── .agents/
│   ├── skills/               # procedures: tdd-cycle, new-feature, new-action, new-notification
│   ├── subagents/            # fresh-context reviewers: spec / test / security
│   └── hooks/                # guardrail proposal (see “Hooks” below)
├── .github/workflows/        # ci.yml (runs check.sh) + pr-checks.yml (runs the gates + selftest)
└── src/                      # the Laravel app itself
    ├── app/                  # Option-B architecture (see docs/architecture.md)
    ├── bruno/                # API collection
    ├── bin/                  # docker wrappers — the ONLY sanctioned tool entry points
    ├── docker/               # image build context (vendored PECL tarballs, corporate CA — ADR-0010)
    └── docker-compose.yml    # single source of truth for dev == CI
```

---

## The method: SDD + TDD + evidence

Every change must belong to a task in an approved spec, and every task is test-first.

```
        ┌──────────────────────── human approval gate ─────────────────┐
        │                                                               ▼
draft spec.md ──► approve ──► plan.md / tasks.md ──► per task: RED → GREEN → evidence
   (agent)        (human)          (agent)                │
                                                          ▼
        feature_list.json step flips passes:true  ◄── verification.md line
                                                          │
        PR review (human: spec ref + evidence + gates green) ──► merge ──► specs/archive/
```

Hard rules that make this enforceable (full list in `constitution.md`):

1. **No spec → no code.** `gate-spec-required.sh` fails PRs touching `src/` without a `specs/` or `feature_list.json` diff.
2. **TDD.** `gate-tdd-order.sh` fails commits that touch `src/app|routes` without `src/tests` changes.
3. **Tests/specs are sacred.** Agents may only flip `passes` status fields in `feature_list.json` — never delete, edit or weaken criteria, steps or assertions.
4. **100% line coverage of `src/app/`, always.** `check.sh` runs `pest --coverage --min=100`; a new uncovered line fails the build (no silent scope shrinks — exemptions need an ADR).
5. **Clean shift.** Each session: read journal tail + git log + `init.sh` smoke; end with `check.sh` green, journal appended, one task max in progress.
6. **The agent proposes commits; the human executes them** (`git commit -S -m …`). The agent never commits.
7. **Evidence over claims.** Done = a row in the spec's `verification.md` with the command/test name and observed output.

### What the executable boundaries look like

Architecture is not a document, it's a test. `src/tests/Architecture/` enforces:

- `Controller (~5 lines) → FormRequest → Data DTO → Action → Event → Listener → Resource`
- no `DB::`/`->save()`/`new Model` in controllers; Actions never touch mailers/HTTP (dispatch events instead — caught a real violation mid-build)
- every write route type-hints a FormRequest (route reflection); no `$request->all()` anywhere (source scan)
- Stripe/Cashier imports confined to `app/Billing/` (the swappable-payment-gateway seam, ADR-0003)
- every `*Action` class lives in `App\Actions`; stateless classes must be `final readonly`
- every auth/admin route carries a named throttle bucket, and buckets are defined in exactly one home

Plus **Larastan level 8**, **Pint** (strict, `declare(strict_types=1)`), **composer audit**, and a committed `openapi.json` that fails the build when it drifts from the router.

---

## AI setup: rules, agents, hooks — and how to trigger them

### 1. Standing rules (read by the agent every session)
- `AGENTS.md` — entrypoint: commands, layout, hard rules, module map. `CLAUDE.md` is a symlink so Claude Code/Codex/opencode all pick it up; `src/AGENTS.md` adds app-level rules (docker-only wrappers, envelope, arch pointers).
- `constitution.md` — the non-negotiables. Agents reference but never edit it.
- `docs/conventions.md` + `docs/architecture.md` + `docs/api-conventions.md` — the contract for *how* code must look; every rule here mirrors an arch test.
- `docs/adr/` — decision records (tenancy model, payment seam, Postgres, docker-only, coverage rule…). New architecture opinions arrive as ADR PRs, never silently.

### 2. Skills (`.agents/skills/`) — reusable procedures
Procedural playbooks the agent follows. They are read on demand (paste/instruct the path) — e.g. “follow `.agents/skills/tdd-cycle/SKILL.md` for task T2.3”:

| Skill | Use when |
|---|---|
| `tdd-cycle` | implementing any task: failing test first → minimal impl → evidence |
| `new-feature` | starting a module: draft spec → STOP for human approval → tasks |
| `new-action` | adding a write endpoint in the arch-legal shape |
| `new-notification` | adding a notification type (catalog class, mail-only from listeners) |

### 3. Subagents (`.agents/subagents/`) — fresh-context reviewers, launched on request
These are reviewer prompts run in a **clean context** (no author bias). They do **not** run automatically — nothing in a repo can self-trigger an agent. Launch them:

- by asking: *“run the security reviewer on the new org endpoints”*
- or at the designed checkpoints: **spec drafted** (before your approval) → `spec-reviewer`; **before proposing a commit** → `test-reviewer`; **any new/changed endpoint** → `security-reviewer`; **module convergence** → `spec-reviewer` divergence sweep.

| Reviewer | Questions it answers |
|---|---|
| `spec-reviewer.md` | does the implementation match spec.md/feature_list? missing criteria? skipped 402/403/429 cases? |
| `test-reviewer.md` | did tests exist before impl (git order)? weakened assertions? names map to ACs? `final readonly` honored? |
| `security-reviewer.md` | auth guard + throttle on every route, FormRequest everywhere, mass assignment, **cross-tenant IDOR probes**, secrets in logs |

In tools with native subagent support (Claude Code `.claude/agents`, Codex, opencode agents) you can copy/wire these files there so the orchestrator spawns them; otherwise the main agent reads the definition and executes it as a Task with fresh context.

### 4. Hooks (`.agents/hooks/`) — deterministic guardrails, status: **proposed**
Hooks are the only *automatic* layer, but they are runtime configuration — they must be registered in the tool you run agents with (e.g. Claude Code `settings.json` hooks, opencode plugin events, or simply CI). This repo defines the *intended* hook set in `.agents/hooks/README.md`:

| Hook | Action |
|---|---|
| session-start | tail `harness/progress.md`, `git log -15`, run `harness/init.sh` + smoke |
| post-edit | run Pint + the focused Pest filter for the touched file |
| pre-commit | `check.sh`; block edits to `specs/*/spec.md`, `feature_list.json` (status fields only), `constitution.md` |
| stop | require journal append + `tasks.md` tick in the same commit |

**Until someone wires them into a runtime, the enforcement you can trust is the deterministic one:** `check.sh` (local == `ci.yml`) and the PR gates (`pr-checks.yml` runs both gate scripts + their `selftest.sh`). CI is the backstop that cannot be forgotten.

### 5. State files — the agent's long-term memory
- `harness/feature_list.json` — acceptance truth. Only `passes` / `verified_at` / `verification` fields change, and only with evidence.
- `harness/progress.md` — append-only journal; every session ends with decisions, catches, gotchas, next task. The session-start ritual is what gives a stateless model continuity.
- `specs/*/verification.md` — per-AC evidence table (test names, observed results, known deviations).

---

## Day-one flows

**Add a feature**
```
1. agent drafts specs/active/NNN-name/spec.md (EARS criteria + feature_list steps)
2. human approves (or bulk-delegates per module, as done for 002)
3. agent implements task-by-task: RED → GREEN → evidence rows
4. agent PROPOSES commit commands; human executes
5. convergence: feature_list passes:true → spec moves to specs/archive/
```

**Run an agent session** — just tell the agent to obey `AGENTS.md`; it starts with the ritual and never calls php/composer directly.

**Verify anything yourself** — one command: `bash harness/scripts/check.sh`.

## Roadmap (locked scope — `specs/feature-scope.md`)

| # | Module | Status |
|---|---|---|
| 001 | Identity & Access (auth, 2FA, passkeys, OAuth, magic link, sessions, role plane) | ✅ shipped |
| 002 | Teams, Orgs & Tenancy (orgs, memberships, invites, tenant scope) | ✅ shipped |
| 003 | Billing & Monetization (DB plans, swappable gateway, own portal) | ✅ shipped |
| 004 | Notifications (catalog, prefs, Echo + persisted) | ⏳ next |
| 005 | Admin & Ops (impersonation, suspend, audit, Horizon prod) | ⏳ |
| 006 | Security & API (integration tokens, uploads hygiene, headers) | ⏳ |
| 007 | Onboarding Events | ⏳ |
| 008 | Settings & Personalization | ⏳ |
| 009 | Background Work & Outbound Webhooks | ⏳ |
| 010 | Dev Platform & CI | ✅ shipped (suite 44s < 90s gate, met at 003 convergence) |

## Notes

- **client-demo/** — a throwaway Vite/shadcn-admin SPA used to exercise the API from a browser. It is **git-ignored on purpose**; it is not part of the boilerplate and never merged.
- **Corporate networks** — TLS-intercepting proxies (Zscaler) break in-container downloads; the image vendors PECL tarballs, trusts the corporate root CA and uses Debian base (ADR-0010). Frontend tooling needs `--use-system-ca` (see `client-demo/.npmrc`).
- **Commits are signed** (`git commit -S`) by convention; agents only propose messages following Conventional Commits + `Spec:` trailers.

## License

MIT
