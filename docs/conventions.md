# Conventions

- **Readonly by default**: every class with no mutable state MUST be `final readonly` (Actions, DTOs where possible, middleware, helpers, static util/service classes). A class extending a mutable framework base (Model, JsonResource, Controller, Seeder) can't be readonly — then use `final` + constructor property promotion with `readonly` properties. Justify mutability or downgrade with a comment.
- Naming: Actions `VerbNounAction` (`InviteOrganizationMemberAction`); events `NounVerb` past tense (`MemberInvited`); Data DTOs `*Data` (input) — one per write endpoint; `make:*` stubs exist via `.agents/skills`.
- Tests: Pest 5 (`.php` files only — `.pest` unsupported; dirs must not start with a digit). Feature tests mirror modules as `tests/Feature/M<NNN>_<Area>/`. Every acceptance step in `feature_list.json` = one test name substring. Architecture rules live in `tests/Architecture/` (bound to TestCase for router access). Unit-test Actions directly; endpoints via HTTP tests.
- Queues: any IO = `ShouldQueue`. Queues: `default`, `mail`, `webhooks`.
- Enums: native PHP enums in `app\Enums`; model states via spatie/laravel-model-states (subscriptions).
- Time: `now()` (UTC), Carbon; user timezone only for date-window logic.
- Git: Conventional Commits (`type(scope): imperative subject ≤72c`), one task per commit, body ends with `Spec: specs/active/<NNN>-<name> (task X.Y)` when applicable. **The agent NEVER commits** — it proposes exact `git add` + `SECURITY_AUDIT=off git commit -S -m "..."` command blocks; the human executes them. **Human rule (2026-10-07): every proposed commit — without exception — is prefixed with `SECURITY_AUDIT=off`; the audit gate runs deliberately per module instead of on every commit.** Types: `feat fix test ci build chore refactor docs perf`.
- Bruno: every new implemented endpoint gets its request in `src/bruno/<module>/` (OpenCollection YAML, `{{baseUrl}}` + tests) — same freshness discipline as `openapi.json`; never document unimplemented routes.
- No TODO/FIXME without `Spec:` reference; no commented-out code; no dead config.
