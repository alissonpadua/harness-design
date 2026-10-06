<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Actions\Notifications\DispatchNotification;
use App\Billing\Events\InvoicePaid;
use App\Billing\Events\PaymentFailed;
use App\Billing\Events\SubscriptionPlanChanged;
use App\Models\Plan;

final readonly class NotifyBillingEvents
{
    public function __construct(private DispatchNotification $notify) {}

    public function onPaymentFailed(PaymentFailed $event): void
    {
        $org = $event->subscription->organization;

        if ($org !== null) {
            $this->notify->org($org, 'billing.payment_failed', ['org_name' => $org->name]);
        }
    }

    public function onInvoicePaid(InvoicePaid $event): void
    {
        $org = $event->subscription->organization;

        if ($org === null) {
            return;
        }

        $this->notify->org($org, 'billing.invoice_paid', [
            'org_name' => $org->name,
            'amount' => sprintf('%.2f', $event->invoice->amount_due / 100),
            'currency' => $event->invoice->currency,
            'url' => $event->invoice->hosted_url,
        ]);
    }

    public function onPlanChanged(SubscriptionPlanChanged $event): void
    {
        $org = $event->subscription->organization;

        if ($org !== null) {
            $this->notify->org($org, 'billing.plan_changed', [
                'org_name' => $org->name,
                'to' => $event->subscription->plan instanceof Plan ? $event->subscription->plan->name : 'Plan',
            ]);
        }
    }
}
