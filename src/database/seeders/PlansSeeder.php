<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\Plan\PlanEntitlementsData;
use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Seeder;

/**
 * Locked Q1/Q2 table (spec 003). Idempotent; admin CRUD takes over afterwards.
 * Amounts are minor units; annual = 10x monthly (2 months free).
 */
final class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'free', 'name' => 'Free', 'trial_days' => 0,
                'entitlements' => new PlanEntitlementsData(
                    max_teams: 1, max_members_per_org: 3, webhooks: false,
                    audit_retention_days: 0, api_rate_limit_per_min: 60,
                ),
                'prices' => ['USD' => 0, 'EUR' => 0, 'BRL' => 0],
            ],
            [
                'code' => 'pro', 'name' => 'Pro', 'trial_days' => 14,
                'entitlements' => new PlanEntitlementsData(
                    max_teams: 5, max_members_per_org: 20, webhooks: true,
                    audit_retention_days: 90, api_rate_limit_per_min: 600,
                ),
                'prices' => ['USD' => 1900, 'EUR' => 1900, 'BRL' => 9900],
            ],
            [
                'code' => 'business', 'name' => 'Business', 'trial_days' => 14,
                'entitlements' => new PlanEntitlementsData(
                    max_teams: 25, max_members_per_org: 100, webhooks: true,
                    audit_retention_days: 365, api_rate_limit_per_min: 3000,
                ),
                'prices' => ['USD' => 7900, 'EUR' => 7900, 'BRL' => 39900],
            ],
        ];

        foreach ($plans as $def) {
            $plan = Plan::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'trial_days' => $def['trial_days'],
                    'active' => true,
                    'entitlements' => $def['entitlements'],
                ]
            );

            foreach ($def['prices'] as $currency => $monthlyAmount) {
                foreach ([BillingInterval::Monthly, BillingInterval::Annual] as $interval) {
                    PlanPrice::updateOrCreate(
                        [
                            'plan_id' => $plan->id,
                            'currency' => $currency,
                            'interval' => $interval->value,
                        ],
                        [
                            'amount' => $interval === BillingInterval::Annual ? $monthlyAmount * 10 : $monthlyAmount,
                        ]
                    );
                }
            }
        }
    }
}
