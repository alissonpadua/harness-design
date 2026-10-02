# Skill: new feature (spec-first)
1. Draft specs/active/NNN-name/spec.md (EARS criteria 1:1 with new feature_list.json entries — steps added here are the ONLY allowed append).
2. Draft plan.md (files, migrations, arch-fit) + tasks.md (one-session tasks, each = failing-test-first unit).
3. STOP — request human approval of spec.md. No src/ edits until approved (constitution #1).
4. On approval: run feature by feature via tdd-cycle skill.
5. Done when all steps passes:true with evidence → move spec dir to specs/archive/, commit.
