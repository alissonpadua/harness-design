<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\SyncInvoices;
use App\Models\BillingCustomer;
use Illuminate\Console\Command;

final class BillingSyncCommand extends Command
{
    protected $signature = 'billing:sync {organization? : org id, omit for all with gateway customers}';

    protected $description = 'Reconcile local invoice mirrors from the gateway';

    public function handle(SyncInvoices $sync): int
    {
        $arg = $this->argument('organization');
        $customers = BillingCustomer::query()
            ->when($arg !== null, fn ($q) => $q->where('organization_id', (int) $arg))
            ->whereNotNull('gateway_customer_id')
            ->get();

        foreach ($customers as $customer) {
            $org = $customer->organization;

            if ($org === null) {
                continue;
            }

            $this->line(sprintf('org %d: %d invoices', $org->id, $sync->handle($org)->count()));
        }

        return self::SUCCESS;
    }
}
