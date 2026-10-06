<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\BillingSubscription;

/**
 * AC-003.19/.20 — past_due older than the grace window falls back to free.
 */
final readonly class DunningSweep
{
    public function __construct(private SwitchPlan $switchPlan) {}

    /**
     * @return int number of downgrades performed
     */
    public function handle(): int
    {
        $deadline = now()->subDays((int) config('billing.dunning_grace_days'));
        $count = 0;

        BillingSubscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('past_due_since')
            ->where('past_due_since', '<', $deadline)
            ->each(function (BillingSubscription $sub) use (&$count): void {
                $org = $sub->organization;

                if ($org !== null) {
                    $this->switchPlan->handle($org, 'free');
                    $count++;
                }
            });

        return $count;
    }
}
