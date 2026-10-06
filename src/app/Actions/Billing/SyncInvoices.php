<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Models\BillingInvoice;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mirror pull for the portal invoice list (reads always hit the mirror).
 */
final readonly class SyncInvoices
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @return Collection<int, BillingInvoice>
     */
    public function handle(Organization $org): Collection
    {
        $remote = $this->gateway->invoices($org);

        foreach ($remote as $invoice) {
            BillingInvoice::updateOrCreate(
                ['gateway_invoice_id' => $invoice->gatewayInvoiceId],
                [
                    'organization_id' => $org->id,
                    'status' => $invoice->status,
                    'amount_due' => $invoice->amountDue,
                    'currency' => $invoice->currency,
                    'hosted_url' => $invoice->hostedUrl,
                    'paid_at' => $invoice->paidAt !== null ? Carbon::parse($invoice->paidAt) : null,
                    'due_at' => $invoice->dueAt !== null ? Carbon::parse($invoice->dueAt) : null,
                ]
            );
        }

        return BillingInvoice::query()
            ->where('organization_id', $org->id)
            ->latest('id')
            ->get();
    }
}
