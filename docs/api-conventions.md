# API Conventions (agent contract — deviations need ADR)

- Base: `/api/v1/*` (public app), `/admin/v1/*` (super-admin only). Version in path. `Accept: application/json` required.
- Success: Laravel Resource shape. Errors: `{"message": "...", "errors": {"field": ["..."]}}` with proper status (422 validation, 401, 403, 402 `SubscriptionRequired`, 429 + `Retry-After`).
- Pagination: cursor default (`?cursor=` + `?per_page` max 100); page params on admin lists.
- Timestamps: UTC ISO-8601 always (`Z`); clients format.
- Requests carry `X-Request-Id` (echoed + logged). Org context: none — persisted `current_organization` (switch via endpoint).
- Docs: Scramble → `/docs` (public on dev/staging, super-admin prod). Every endpoint covered by feature test.
