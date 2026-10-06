<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Models\BillingSubscription;
use Spatie\LaravelData\Data;

final class SubscriptionViewData extends Data
{
    public function __construct(
        public readonly string $plan_code,
        public readonly string $plan_name,
        public readonly string $status,
        public readonly string $interval,
        public readonly string $currency_default,
        public readonly ?string $current_period_end,
        public readonly ?string $trial_end,
        public readonly bool $cancel_at_period_end,
        public readonly ?string $past_due_since,
        public readonly ?string $over_limit_until,
        public readonly bool $is_over_limit,
        /** @var array<string, mixed> */
        public readonly array $entitlements,
    ) {}

    public static function make(BillingSubscription $sub): self
    {
        $plan = $sub->plan ?? throw new \RuntimeException('Subscription without plan.');

        return new self(
            plan_code: $plan->code,
            plan_name: $plan->name,
            status: $sub->status->value,
            interval: $sub->interval->value,
            currency_default: 'USD',
            current_period_end: $sub->current_period_end?->toIso8601String(),
            trial_end: $sub->trial_end?->toIso8601String(),
            cancel_at_period_end: $sub->cancel_at_period_end,
            past_due_since: $sub->past_due_since?->toIso8601String(),
            over_limit_until: $sub->over_limit_until?->toIso8601String(),
            is_over_limit: $sub->over_limit_until !== null,
            entitlements: $plan->entitlements->toArray(),
        );
    }
}
