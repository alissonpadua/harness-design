<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Billing\Events\CheckoutCompleted;
use App\Billing\Events\InvoicePaid;
use App\Billing\Events\PaymentFailed;
use App\Billing\Events\PaymentRecovered;
use App\Billing\Events\SubscriptionActivated;
use App\Billing\Events\SubscriptionCanceled;
use App\Billing\Events\SubscriptionResumed;
use App\Data\Billing\IngestResultData;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The one ingestion pipeline: HTTP webhook AND dev-pulled events flow through
 * here — idempotent via webhook_events unique (AC-003.9/.10/.11).
 */
final readonly class ProcessWebhookEvent
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(string $gateway, array $event): IngestResultData
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($id === '' || $type === '') {
            return new IngestResultData('unknown', 'unknown', 'failed');
        }

        if (WebhookEvent::query()->where('gateway', $gateway)->where('gateway_event_id', $id)->exists()) {
            return new IngestResultData($id, $type, 'duplicate');
        }

        $row = WebhookEvent::create([
            'gateway' => $gateway,
            'gateway_event_id' => $id,
            'type' => $type,
            'payload' => $event,
        ]);

        try {
            $outcome = match ($type) {
                'checkout.session.completed' => $this->checkoutCompleted($event),
                'customer.subscription.updated' => $this->subscriptionUpdated($event),
                'customer.subscription.deleted' => $this->subscriptionDeleted($event),
                'invoice.paid' => $this->invoicePaid($event),
                'invoice.payment_failed' => $this->invoicePaymentFailed($event),
                default => 'ignored',
            };

            $row->forceFill(['processed_at' => now()])->save();

            return new IngestResultData($id, $type, $outcome);
        } catch (Throwable $e) {
            $row->forceFill(['error' => $e->getMessage()])->save();

            return new IngestResultData($id, $type, 'failed');
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function checkoutCompleted(array $event): string
    {
        /** @var array<string, mixed> $session */
        $session = $event['data']['object'] ?? [];
        $meta = (array) ($session['metadata'] ?? []);

        $org = $this->orgFor((string) ($meta['org_id'] ?? ''), (string) ($session['customer'] ?? ''));

        if ($org === null) {
            return 'failed';
        }

        $sub = $this->subFor($org);
        $trialDays = (int) ($meta['trial_days'] ?? 0);

        $sub->forceFill([
            'plan_id' => (int) ($meta['plan_id'] ?? $sub->plan_id),
            'gateway_subscription_id' => (string) ($session['subscription'] ?? ''),
            'interval' => (string) ($meta['interval'] ?? BillingInterval::Monthly->value),
            'status' => $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'trial_end' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'current_period_end' => $trialDays > 0 ? now()->addDays($trialDays) : $this->periodEnd((string) ($meta['interval'] ?? 'monthly')),
            'cancel_at_period_end' => false,
            'past_due_since' => null,
        ])->save();

        CheckoutCompleted::dispatch($sub->refresh());
        SubscriptionActivated::dispatch($sub->refresh());

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function subscriptionUpdated(array $event): string
    {
        $sub = $this->subByGatewayId($event);

        if ($sub === null) {
            return 'failed';
        }

        /** @var array<string, mixed> $obj */
        $obj = $event['data']['object'] ?? [];
        $was = $sub->status;

        $sub->forceFill([
            'status' => $this->mapStatus((string) ($obj['status'] ?? 'active')),
            'current_period_end' => isset($obj['current_period_end']) ? now()->setTimestamp((int) $obj['current_period_end']) : $sub->current_period_end,
            'trial_end' => isset($obj['trial_end']) ? now()->setTimestamp((int) $obj['trial_end']) : $sub->trial_end,
            'cancel_at_period_end' => (bool) ($obj['cancel_at_period_end'] ?? false),
            'past_due_since' => (($obj['status'] ?? '') === SubscriptionStatus::PastDue->value)
                ? ($sub->past_due_since ?? now())
                : null,
        ])->save();

        if ($was === SubscriptionStatus::PastDue && $sub->status === SubscriptionStatus::Active) {
            PaymentRecovered::dispatch($sub->refresh());
        }

        if ($was !== SubscriptionStatus::Canceled && $sub->status === SubscriptionStatus::Canceled) {
            SubscriptionCanceled::dispatch($sub->refresh());
        } elseif ($was === SubscriptionStatus::Canceled && $sub->status !== SubscriptionStatus::Canceled) {
            SubscriptionResumed::dispatch($sub->refresh());
        }

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function subscriptionDeleted(array $event): string
    {
        $sub = $this->subByGatewayId($event);

        if ($sub === null) {
            return 'failed';
        }

        $sub->forceFill([
            'status' => SubscriptionStatus::Canceled,
            'cancel_at_period_end' => false,
        ])->save();

        SubscriptionCanceled::dispatch($sub->refresh());

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function invoicePaid(array $event): string
    {
        $sub = $this->subByInvoice($event);
        $invoice = $this->upsertInvoice($event, 'paid');

        if ($sub === null) {
            return 'failed';
        }

        if ($sub->status === SubscriptionStatus::PastDue) {
            $sub->forceFill(['status' => SubscriptionStatus::Active, 'past_due_since' => null])->save();
            PaymentRecovered::dispatch($sub->refresh());
        }

        InvoicePaid::dispatch($sub->refresh(), $invoice);

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function invoicePaymentFailed(array $event): string
    {
        $sub = $this->subByInvoice($event);
        $this->upsertInvoice($event, 'open');

        if ($sub === null) {
            return 'failed';
        }

        $sub->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'past_due_since' => $sub->past_due_since ?? now(),
        ])->save();

        PaymentFailed::dispatch($sub->refresh());

        return 'processed';
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function subByGatewayId(array $event): ?BillingSubscription
    {
        /** @var array<string, mixed> $obj */
        $obj = $event['data']['object'] ?? [];
        $id = (string) ($obj['id'] ?? '');

        return BillingSubscription::query()->where('gateway_subscription_id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function subByInvoice(array $event): ?BillingSubscription
    {
        /** @var array<string, mixed> $obj */
        $obj = $event['data']['object'] ?? [];
        $subId = (string) ($obj['subscription'] ?? '');

        return BillingSubscription::query()->where('gateway_subscription_id', $subId)->first();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function upsertInvoice(array $event, string $status): BillingInvoice
    {
        /** @var array<string, mixed> $obj */
        $obj = $event['data']['object'] ?? [];
        $customerId = (string) ($obj['customer'] ?? '');
        $customer = BillingCustomer::query()->where('gateway_customer_id', $customerId)->first();

        return BillingInvoice::updateOrCreate(
            ['gateway_invoice_id' => (string) ($obj['id'] ?? 'unknown')],
            [
                'organization_id' => (int) ($customer->organization_id ?? 0),
                'status' => $status,
                'amount_due' => (int) ($obj['amount_due'] ?? 0),
                'currency' => strtoupper((string) ($obj['currency'] ?? 'usd')),
                'hosted_url' => $obj['hosted_invoice_url'] ?? null,
                'paid_at' => $status === 'paid' ? now() : null,
                'due_at' => $status === 'paid' ? null : now()->addDays(7),
            ]
        );
    }

    private function orgFor(string $orgId, string $customerId): ?Organization
    {
        if ($orgId !== '') {
            $org = Organization::query()->find((int) $orgId);

            if ($org !== null) {
                return $org;
            }
        }

        $customer = BillingCustomer::query()->where('gateway_customer_id', $customerId)->first();

        return $customer?->organization;
    }

    private function subFor(Organization $org): BillingSubscription
    {
        $free = Plan::query()->where('code', 'free')->firstOrFail();

        return $org->subscription() ?? BillingSubscription::create([
            'organization_id' => $org->id,
            'plan_id' => $free->id,
            'status' => SubscriptionStatus::Inactive,
        ]);
    }

    private function mapStatus(string $remote): SubscriptionStatus
    {
        return match ($remote) {
            'trialing' => SubscriptionStatus::Trialing,
            'active', 'automatic_tax' => SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'canceled', 'incomplete_expired' => SubscriptionStatus::Canceled,
            default => SubscriptionStatus::Inactive,
        };
    }

    private function periodEnd(string $interval): Carbon
    {
        return $interval === BillingInterval::Annual->value ? now()->addYear() : now()->addMonth();
    }
}
