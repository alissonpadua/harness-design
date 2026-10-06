<?php

declare(strict_types=1);

namespace App\Data\Plan;

use Spatie\LaravelData\Data;

/**
 * plans.entitlements JSON shape — single source of truth for caps/gates.
 * Strict: unknown keys are rejected by the Plan model cast at write time.
 */
final class PlanEntitlementsData extends Data
{
    public function __construct(
        public readonly int $max_teams,
        public readonly int $max_members_per_org,
        public readonly bool $webhooks,
        public readonly int $audit_retention_days,
        public readonly int $api_rate_limit_per_min,
    ) {}
}
