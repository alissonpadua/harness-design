# Conventions

- Naming: Actions `VerbNounAction` (`InviteOrganizationMemberAction`); events `NounVerb` past tense (`MemberInvited`); Data DTOs `*Data` (input) — one per write endpoint; `make:*` stubs exist via `.agents/skills`.
- Tests: Pest. Feature tests mirror module numbers: `tests/Feature/001-Identity/...`. Every acceptance step in `feature_list.json` = one test name substring. Unit-test Actions directly; endpoints via HTTP tests.
- Queues: any IO = `ShouldQueue`. Queues: `default`, `mail`, `webhooks`.
- Enums: native PHP enums in `app\Enums`; model states via spatie/laravel-model-states (subscriptions).
- Time: `now()` (UTC), Carbon; user timezone only for date-window logic.
- Git: Conventional Commits, one task per commit, message includes `Spec: specs/active/<NNN> (task X.Y)`.
- No TODO/FIXME without `Spec:` reference; no commented-out code; no dead config.
