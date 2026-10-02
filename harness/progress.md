# Progress Journal — append-only, never rewrite history

## 2026-10-02 — session 0 (harness bootstrap)
- Locked feature scope modules 1–11 in specs/feature-scope.md (TDD mandatory, architecture = "modern Laravel way", pgsql, Scramble, docker-only).
- Created Laravel 13.34 app in `src/` via `laravel new src --database=pgsql --pest --no-authentication`.
- Scaffolded harness: AGENTS.md, constitution.md, docs (architecture/conventions/api-conventions + ADRs 0001–0009), specs/active/001–010, harness/{init.sh, scripts/check.sh, feature_list.json}, .agents/, .github/, src/{docker-compose.yml, docker/, bin/}.
- Next session: 010-dev-platform-ci first (the rails for everything else): finish docker stack smoke-run, wire phpstan/pint configs, arch-test pack, then 001-identity-access spec.md draft for human approval.
- Unknowns: Laravel installer left default sqlite-style config? verified DB=pgsql env keys present; Scramble + spatie packages not yet installed (task 010.x).

## 2026-10-02 — session 0b (harness bootstrap, docker bring-up)
- Scaffolded full harness + Docker stack; cold boot verified: init.sh idempotent, migrate+seed OK, nginx→app 200, Redis PONG, pest 2 passed, bin wrappers work.
- Network reality forced ADR-0010: RustFS replaces MinIO (images pulled from public registries), PECL tarballs vendored, Zscaler root CA baked into php:8.4-fpm-bookworm image (Alpine unusable — TLS interception).
- KNOWN unfinished (owned by spec 010): phpstan+scramble+pint configs not installed yet → check.sh cannot be fully green yet; src/AGENTS.md is the installer's boost-default and must be replaced; .env.example lacks docker-host defaults (DB_HOST=pgsql etc. currently only via compose environment — verify forkers get identical behavior from .env alone); git repo not initialized (awaiting human).
- Next session: 010-dev-platform-ci — composer require (sanctum, cashier? no: stripe-php behind gateway contract later, spatie packages, pest plugins, larastan, scramble), pint/phpstan configs, arch test pack, CI green.
