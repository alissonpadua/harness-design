<?php

declare(strict_types=1);

namespace App\Contracts\Org;

use App\Actions\Billing\EnsureOrgSubscription;
use App\Data\Plan\PlanEntitlementsData;
use App\Enums\OrgType;
use App\Models\Organization;
use App\Models\OrganizationEntitlementOverride;
use App\Models\User;

/**
 * Plan-backed entitlements (003) replacing the config stand-in (002),
 * with per-org manual overrides merged on top (spec 005).
 * Fallback is always the seeded `free` plan — entitlements never vanish.
 */
final readonly class PlanOrgEntitlements implements OrgEntitlements
{
    public function __construct(private EnsureOrgSubscription $ensure) {}

    /**
     * Effective entitlements for one org: its subscription plan, then override.
     */
    public function effective(Organization $organization): PlanEntitlementsData
    {
        $base = $organization->subscription()?->plan->entitlements ?? $this->free();

        $override = OrganizationEntitlementOverride::query()
            ->where('organization_id', $organization->id)
            ->value('overrides');

        if (! is_array($override) || $override === []) {
            return $base;
        }

        return PlanEntitlementsData::from(array_merge($base->toArray(), $override));
    }

    public function maxTeams(User $user): int
    {
        $owned = Organization::query()
            ->where('type', OrgType::Team)
            ->where('owner_id', $user->id)
            ->get();

        if ($owned->isEmpty()) {
            return $this->free()->max_teams;
        }

        return max(1, $owned->max(fn (Organization $o): int => $this->effective($o)->max_teams));
    }

    public function maxMembers(Organization $organization): int
    {
        return $this->effective($organization)->max_members_per_org;
    }

    public function auditRetentionDays(Organization $organization): int
    {
        return $this->effective($organization)->audit_retention_days;
    }

    private function free(): PlanEntitlementsData
    {
        return $this->ensure->freePlan()->entitlements;
    }
}
