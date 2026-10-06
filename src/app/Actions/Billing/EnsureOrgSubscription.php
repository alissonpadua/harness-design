<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Plan\PlanEntitlementsData;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\Plan;

final readonly class EnsureOrgSubscription
{
    public function handle(Organization $org): BillingSubscription
    {
        /** @var BillingSubscription|null $existing */
        $existing = $org->subscription();

        if ($existing !== null) {
            return $existing;
        }

        return BillingSubscription::create([
            'organization_id' => $org->id,
            'plan_id' => $this->freePlan()->id,
            'status' => SubscriptionStatus::Active,
            'interval' => BillingInterval::Monthly,
        ]);
    }

    public function freePlan(): Plan
    {
        // self-healing floor: the free plan must exist even in bare test DBs
        return Plan::query()->firstOrCreate(
            ['code' => 'free'],
            [
                'name' => 'Free',
                'trial_days' => 0,
                'active' => true,
                'entitlements' => new PlanEntitlementsData(
                    max_teams: 1, max_members_per_org: 3, webhooks: false,
                    audit_retention_days: 0, api_rate_limit_per_min: 60,
                ),
            ]
        );
    }
}
