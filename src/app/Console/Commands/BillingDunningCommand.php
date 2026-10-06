<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\DunningSweep;
use Illuminate\Console\Command;

final class BillingDunningCommand extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Downgrade past-due subscriptions older than the dunning grace window to free';

    public function handle(DunningSweep $sweep): int
    {
        $count = $sweep->handle();
        $this->info("Downgraded {$count} subscription(s) to free.");

        return self::SUCCESS;
    }
}
