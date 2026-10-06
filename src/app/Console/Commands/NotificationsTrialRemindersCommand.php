<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notifications\DispatchNotification;
use App\Enums\SubscriptionStatus;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Notifications\NotificationCatalog;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Daily 08:00 UTC (Q5): remind owner+admins when a trial ends within N days,
 * once per subscription (dedupe via persisted inbox row).
 */
final class NotificationsTrialRemindersCommand extends Command
{
    protected $signature = 'notifications:trial-reminders';

    protected $description = 'Send trial-ending-soon notices for subscriptions within the reminder window';

    public function handle(DispatchNotification $notify, NotificationCatalog $catalog): int
    {
        $window = (int) config('notifications.trial_reminder_days');
        $count = 0;

        BillingSubscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_end')
            ->whereBetween('trial_end', [now(), now()->addDays($window)])
            ->with(['organization', 'plan'])
            ->each(function (BillingSubscription $sub) use ($notify, &$count): void {
                $org = $sub->organization;

                if ($org === null || $this->alreadyReminded($sub->id)) {
                    return;
                }

                $notify->org($org, 'billing.trial_ending_soon', [
                    'org_name' => $org->name,
                    'plan_name' => $sub->plan instanceof Plan ? $sub->plan->name : 'Trial',
                    'days_left' => max(1, (int) now()->diffInDays($sub->trial_end, false)),
                    'sub_id' => $sub->id,
                ]);

                $count++;
            });

        $this->info("Sent {$count} trial reminder(s).");

        return self::SUCCESS;
    }

    private function alreadyReminded(int $subscriptionId): bool
    {
        return DatabaseNotification::query()
            ->where('data->type', 'billing.trial_ending_soon')
            ->where('data->data->sub_id', $subscriptionId)
            ->exists();
    }
}
