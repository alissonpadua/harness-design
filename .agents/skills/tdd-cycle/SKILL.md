# Skill: TDD cycle (one task)
1. Read specs/active/<F>/spec.md acceptance criterion + matching feature_list step.
2. Write failing test: tests/Feature/<NNN>-<Area>/<Test>.pest.php named after the criterion.
3. RUN: src/bin/pest --filter=<name> → confirm RED (failure reason must be missing behavior, not setup).
4. Implement minimal vertical slice: FormRequest → Data → Action → Route → Resource (docs/architecture.md roles).
5. RUN same filter → GREEN; then src/bin/pest (full) → green.
6. Refactor; pint auto-fix via src/bin/pint.
7. Append verification evidence line to spec's verification.md; tick tasks.md box.
NEVER: weaken/delete tests, edit feature_list steps, mark passes:true without evidence.
