<?php

declare(strict_types=1);

namespace App\Contracts\Org;

use App\Actions\Billing\EnsureOrgSubscription;
use App\Data\Plan\PlanEntitlementsData;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;

/**
 * Plan-backed entitlements (003) replacing the config stand-in (002).
 * Fallback is always the seeded `free` plan — entitlements never vanish.
 */
final readonly class PlanOrgEntitlements implements OrgEntitlements
{
    public function __construct(private EnsureOrgSubscription $ensure) {}

    public function maxTeams(User $user): int
    {
        $plans = Plan::query()
            ->whereIn('id', function ($query) use ($user): void {
                $query->select('subscriptions.plan_id')
                    ->from('subscriptions')
                    ->join('organizations', 'organizations.id', '=', 'subscriptions.organization_id')
                    ->where('organizations.owner_id', $user->id);
            })
            ->get()
            ->map(fn (Plan $p): PlanEntitlementsData => $p->entitlements);

        return $plans->isEmpty()
            ? $this->free()->max_teams
            : max(1, $plans->max(fn (PlanEntitlementsData $e): int => $e->max_teams));
    }

    public function maxMembers(Organization $organization): int
    {
        $sub = $organization->subscription();

        return $sub?->plan?->entitlements->max_members_per_org ?? $this->free()->max_members_per_org;
    }

    private function free(): PlanEntitlementsData
    {
        return $this->ensure->freePlan()->entitlements;
    }
}
