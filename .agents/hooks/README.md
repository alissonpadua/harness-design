# Hooks (wire per tool: Claude Code .claude/settings.json, opencode plugin, CI mirror)
- session-start: `tail -n 80 harness/progress.md && git log --oneline -15 && harness/init.sh`
- post-edit (php): run scoped pest filter for touched file's test mirror + pint on that file
- pre-commit: harness/scripts/check.sh (or fast subset); block edits to: specs/*/spec.md, harness/feature_list.json (except passes/append), constitution.md, tests/** when impl-only changes present
- stop: require progress.md append + tasks.md tick in same commit
Guardrails are advisory locally, ENFORCING in CI (mirrors exist in .github/workflows).
