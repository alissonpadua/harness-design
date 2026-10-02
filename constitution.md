# Constitution

Immutable principles. Editing this file requires explicit human approval.
Agents: you may reference these rules; you may not modify, weaken, or reinterpret them.

1. **Spec-driven.** No implementation code without an approved spec task. Specs are the contract; code is generated from them. Humans approve specs and evidence, not line-by-line diffs.
2. **TDD mandatory.** Failing test first, minimal implementation, refactor. A task flips to done only when its tests existed before the implementation and pass in `check.sh`.
3. **Tests and specs are sacred.** Never delete, skip, or relax a test/assertion/acceptance criterion to obtain green. Fix the code. Removing or editing `harness/feature_list.json` entries or `specs/*/spec.md` requirements is forbidden; only status fields may change.
4. **Clean shift.** End every session with: `check.sh` green, one scoped git commit, progress.md appended, environment bootable by `init.sh` without guesswork.
5. **One task per context window.** If a task exceeds one session, split it in tasks.md first, then work. No half-implemented features left undocumented.
6. **Boundaries are executable.** Architecture rules live in `docs/architecture.md` and are enforced by Pest arch tests + Larastan L8. Circumventing a guardrail (baseline ignore, phpstan-exit, test-only env hacks) requires human approval.
7. **Docker-only.** The dev/test/prod toolchain runs exclusively in containers via `src/bin/*`. Host PHP/composer invocations are bugs to fix in the wrappers.
8. **API-only product.** JSON responses everywhere, standard envelope (`docs/api-conventions.md`). No frontend assets creep into `src/resources`.
9. **Security defaults.** No mass assignment, FormRequest on every write, auth+rate-limit on every non-public endpoint group, secrets never in code/tests/logs.
10. **Evidence over claims.** `passes: true` and "done" require recorded verification evidence in the spec's verification.md — command output, test names, or HTTP evidence. Missing verification is not a successful change.
11. **100% test coverage, always.** Line coverage of `src/app/` must be exactly 100% at all times (`check.sh` runs `pest --coverage --min=100`; build fails below). New uncovered code = new tests in the same commit. Exemptions (e.g. generated vendor glue moved into app/) require an ADR + human approval — never by shrinking the coverage scope silently.
