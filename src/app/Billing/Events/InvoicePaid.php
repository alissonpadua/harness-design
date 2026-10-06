<?php

declare(strict_types=1);

namespace App\Billing\Events;

use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class InvoicePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BillingSubscription $subscription,
        public readonly BillingInvoice $invoice,
    ) {}
}
