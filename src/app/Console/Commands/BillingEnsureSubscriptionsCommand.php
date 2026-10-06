<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\EnsureOrgSubscription;
use App\Models\Organization;
use Illuminate\Console\Command;

final class BillingEnsureSubscriptionsCommand extends Command
{
    protected $signature = 'billing:ensure-subscriptions';

    protected $description = 'Backfill a free subscription for every organization without one';

    public function handle(EnsureOrgSubscription $ensure): int
    {
        $count = 0;

        Organization::query()->doesntHave('subscriptions')->each(function (Organization $org) use ($ensure, &$count): void {
            $ensure->handle($org);
            $count++;
        });

        $this->info("Created {$count} subscription(s).");

        return self::SUCCESS;
    }
}
