# Verification 006 — Security & API

Append-only evidence log. 006 S1–S7 flips only from rows here. Gates run 2026-10-08: pint ✓ · Larastan L8 [OK] ✓ · composer audit ✓ · arch (28) ✓ · **378 tests / 2026 assertions / Total: 100.0 %** ✓ · openapi regenerated (freshness pending commit, by design).

| AC | Task | Test / command | Observed | Commit |
|----|------|----------------|----------|--------|
| AC-006.1 | T1 | HeadersTest 'locked header set' + '/docs exempt' | nosniff/XFO DENY/Referrer no-referrer/CSP on 200·404·401·422; HSTS only via https://; /docs/api lacks CSP, keeps rest; nginx duplicate add_headers REMOVED after live curl showed conflicting Referrer-Policy (middleware is now single source) | pending |
| AC-006.2 | T1 | HeadersTest 'reused verbatim / replaced' + 'log line context' | valid client id echoed; `<script>` + 300-char → ULID; monolog tap appends extra.request_id (single-channel file probe) | pending |
| AC-006.3 | T1 | HeadersTest CORS | listed origin → ACAO on simple+preflight, no Allow-Credentials ever; unlisted never receives its own origin (single-origin static-header optimization documented); empty allowlist → no headers. LIVE: evil Origin → 0 ACAO headers at 8080 | pending |
| AC-006.4 | T2 | ThrottleTest 429 buckets | auth-login(5) auth-forgot(5) auth-reset(10) auth-2fa(10) all → 429 envelope 'Too Many Requests' + Retry-After≥1 (framework-provided, now asserted) | pending |
| AC-006.5 | T2/T4 | ThrottleTest plan-limit + IntegrationTokensTest shared-bucket | per-ORG key (route {organization} id-or-slug > token org > current org > fallback); isolation across orgs; 005 override RAISES ceiling; device+integration tokens share budget; stale/absent org → 60/min; guest → ip. LIVE dev: ceiling 3 → 429 + Retry-After: 9 | pending |
| AC-006.6 | T3/T4 | IntegrationTokensTest (9) | schema (organization_id+kind+named index); plaintext once, list metadata-only, device tokens invisible; catalog validation 422; bearer only within granted abilities (403 names the ability — central seam in OrgScopedRequest covers EVERY permission-checked org route, spec amendment over per-route list); revoke 204 → bearer 401 (Accept-aware; no-Accept 500 quirk is pre-existing framework behavior for API-only app); cross-org id 404; member role 403; long-lived + 5 coexisting + device-limit-exempt; audit rows both events. LIVE: create→members 200, billing 403 named, revoke→401 | pending |
| AC-006.7 | T3 | IntegrationTokensTest 2FA-gate | no factor → 403 exact message; TOTP confirmed → ok; PASSKEY-ONLY (Q3) → ok. LIVE: tinker-set confirmation → create ok | pending |
| AC-006.8 | T5 | ImageProcessor branch in CoverageTest + LogoTest (8) | png/jpeg/webp ≤2MiB → webp RIFF magic on PRIVATE disk, 1200×900→1024×768 cap, bytes≠upload; finfo beats lying filename (PHP payload named .png → 422); 3MiB → 422 pre-write; member 403; public route local-streams image/webp + Cache-Control max-age=300, s3 driver → 302 signed (Storage::fake); DELETE clears → 404; legacy free-text logo_url column DROPPED (accessor-derived; M002/M005 touchpoints re-aimed). LIVE: upload → public slug URL 200 image/webp | pending |
| AC-006.9 | T7 | RouteRulesTest 4 new | mass-assignment ban (`create($request->all())`, `fill($request->all())`, `unguard(`, `$guarded = []`) + upload-surface allow-list (SetOrgLogoAction/OrgLogoController only) — both with fixture prove-the-checker pairs; existing FormRequest-on-100%-writes rule stays green incl. all 006 routes | pending |
| AC-006.10 | T6 | KillSwitchTest (5) | default open; toggle 403-for-non-admin, 200+audit for admin; register + oauth NEW-user → 403 'Registrations are closed.'; existing-user login + existing-identity oauth unaffected; reopen works; settings singleton staleness fixed via forgetInstance after save. LIVE: full close→403→login-ok→reopen cycle | pending |
| Infra (T0) | — | docker rebuild | PHP gd (jpeg/webp) baked into app image (was MISSING — found by pre-draft recon); spatie/laravel-settings 3.9 + settings table + settings-migration seed (`registrations.open` default row REQUIRED: package refuses save() of pure-default property sets); dependabot.yml | pending |

## feature_list 006 step → AC map
- S1 (headers+CORS+request-id) ← AC-006.1/.2/.3
- S2 (throttle buckets + plan rate) ← AC-006.4/.5
- S3 (integration tokens abilities/show-once/revoke) ← AC-006.6
- S4 (2FA-fresh gate) ← AC-006.7 (D1)
- S5 (logo upload finfo+re-encode, public read, sole surface) ← AC-006.8 + arch in AC-006.9
- S6 (CI mass-assign + FormRequest greps) ← AC-006.9
- S7 (registrations kill-switch) ← AC-006.10

## Deviations & human overrides
1. **D1** — step 4 "2FA-fresh-session": Q2=C chose ACCOUNT-level `two_factor_confirmed_at` (no time window); Q3=A passkey registration satisfies the gate. Annotated in feature_list step text at flip.
2. **D2** — Q5=A (latest instruction): plan `api_rate_limit_per_min` throttles PER ORGANIZATION, not per token.
3. **D3** — CSP exempts the /docs HTML surface (API plane still fully covered + tested); earlier tentative Q4 answer (S3 public-ACL bucket) superseded by B: controller-served public logo route, private storage everywhere.
4. Spec amendment (self-caught during build): ability enforcement implemented CENTRALLY in OrgScopedRequest (every permission-checked org route) instead of the drafted 3-route opt-in list — strictly stronger, same tests.
5. Mockery Socialite facade-mock collision across suites → hand-rolled Tests\Support\FakeSocialite + clearResolvedInstances per test.

## Manual checks (human, dev)
1. Headers: `curl -si localhost:8080/api/v1/ping -H 'X-Request-Id: mine-1'` → echo; with junk header → ULID; `/docs` has no CSP.
2. Throttle: 6 rapid `POST /auth/login` → 429 + Retry-After; override an org to `api_rate_limit_per_min:2` then hammer org routes (429 while OTHER org stays 200).
3. Tokens: Admin console (client-demo) or bruno/security — create ci token with `members.view`, use as bearer (members 200, billing 403 naming ability), revoke, bearer dies.
4. Logo: put `src/bruno/security/fixtures/logo.png` via PUT /orgs/{id}/logo; open the returned public URL in a browser; check stored object is WEBP (bucket console :9001).
5. Kill-switch: `PUT /admin/v1/settings/registrations {"open":false}` → sign-up flow 403s; existing login works.
