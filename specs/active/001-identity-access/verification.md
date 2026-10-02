# Verification 001 — Identity & Access

Append-only evidence log. Each line: `AC-id | task | command/test name | observed result | commit`.
`feature_list.json` S-steps flip only with evidence here (constitution #10).

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
|    |      |                |          |        |

## Notes / pending human micro-decisions
- spec.md § Micro-decisions 1–5 carry defaults; flip any before T2 starts.

## Package versions (T1.1 evidence)
- pending
| AC-001.23(partial) T9 seeds early; AC-001.26 schema | T1 | `bin/pest tests/Feature/M001_Identity` — SchemaContractTest 9 tests; RED first (8 failed/1 passed: only sanctum guard existed), GREEN after impl; full suite 46 passed, coverage 100.0% | green | pending |
| spike | T1.1/T5.0 | `composer require` → laravel/sanctum 4.3.3, socialite 5.31, spatie/permission 8.3, pragmarx/google2fa 9.1, bacon-qr-code 3.1, **asbiin/laravel-webauthn 6.0.0** (published webauthn.php config + webauthn_keys migration + public/vendor/webauthn js assets) — L13-compatible | green | pending |
| T1 impl notes | | config/hashing.php published, default argon2id; config/auth.php + `password.rules` (complexity per decision #4) + `two_factor_policy` documented as container-binding (closures excluded → config:cache/route:cache verified OK); migration 2026_10_02_150000_identity_core (users cols, PAT device_type/ip/agent, oauth_accounts, auth_links); User traits SoftDeletes+HasApiTokens, encrypted 2FA casts; DatabaseSeeder bcrypt()→raw password (hashed cast owns hashing — argon2id verification rejected pre-hashed) | green | pending |
| Pest 5 pitfalls hit | | `.pest` unsupported; `toIncludeAllMembers` removed (→ array_diff helper); `Model::casts()` protected → `getCasts()`; digit-leading/`NNN-name` dirs → `M<NNN>_Name` | — | pending |

## Package versions (T1.1 evidence)
- sanctum 4.3.3 · socialite 5.31 · permission 8.3 · google2fa 9.1 · bacon-qr-code 3.1 · laravel-webauthn 6.0.0
