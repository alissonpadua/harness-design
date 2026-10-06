<?php

declare(strict_types=1);

namespace App\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Data\Billing\ChangePreviewData;
use App\Data\Billing\GatewayInvoiceData;
use App\Data\Billing\GatewayRedirectData;
use App\Data\Billing\PaymentMethodData;
use App\Data\Billing\ProrationLineData;
use App\Data\Billing\SetupIntentData;
use App\Enums\BillingInterval;
use App\Exceptions\InvalidWebhookException;
use App\Exceptions\PaymentProviderException;
use App\Models\BillingCustomer;
use App\Models\BillingSubscription;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Stripe adapter — the ONLY class allowed to import stripe-php (arch rule).
 * Verified via the human live-smoke checklist (spec 003 verification.md);
 * automated suites run the FakeGateway.
 */
final class StripeGateway implements PaymentGateway
{
    private StripeClient $stripe;

    public function __construct()
    {
        $secret = (string) config('billing.stripe.secret');

        if ($secret === '') {
            throw new PaymentProviderException('STRIPE_SECRET is not configured.');
        }

        $this->stripe = new StripeClient($secret);
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function ensureCustomer(Organization $organization): string
    {
        /** @var BillingCustomer|null $customer */
        $customer = BillingCustomer::query()->where('organization_id', $organization->id)->first();

        if ($customer?->gateway_customer_id !== null) {
            return $customer->gateway_customer_id;
        }

        $remote = $this->stripe->customers->create([
            'name' => $organization->name,
            'metadata' => ['organization_id' => (string) $organization->id],
        ]);

        BillingCustomer::updateOrCreate(
            ['organization_id' => $organization->id],
            ['gateway' => 'stripe', 'gateway_customer_id' => $remote->id]
        );

        return $remote->id;
    }

    public function checkoutUrl(Organization $organization, PlanPrice $price): GatewayRedirectData
    {
        return $this->checkout($organization, $price, trialDays: 0);
    }

    public function trialCheckoutUrl(Organization $organization, PlanPrice $price, int $trialDays): GatewayRedirectData
    {
        return $this->checkout($organization, $price, $trialDays);
    }

    public function applyChange(Organization $organization, PlanPrice $from, PlanPrice $to): GatewayRedirectData
    {
        try {
            $customer = $this->ensureCustomer($organization);
            /** @var BillingSubscription|null $sub */
            $sub = $organization->subscription();

            if ($sub?->gateway_subscription_id !== null) {
                $this->stripe->subscriptions->cancel($sub->gateway_subscription_id);
            }

            $remote = $this->stripe->subscriptions->create([
                'customer' => $customer,
                'items' => [['price' => $this->remotePrice($to)]],
            ]);

            return new GatewayRedirectData(url: '', gatewayRef: $remote->id);
        } catch (Throwable $e) {
            Log::error('Stripe gateway call failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            throw new PaymentProviderException(previous: $e);
        }
    }

    public function cancel(Organization $organization): void
    {
        /** @var BillingSubscription|null $sub */
        $sub = $organization->subscription();

        if ($sub?->gateway_subscription_id !== null) {
            $this->stripe->subscriptions->cancel($sub->gateway_subscription_id);
        }
    }

    public function previewChange(Organization $organization, PlanPrice $from, PlanPrice $to, BillingInterval $interval): ChangePreviewData
    {
        try {
            $customer = $this->ensureCustomer($organization);
            /** @var BillingSubscription|null $sub */
            $sub = $organization->subscription();

            $upcoming = $this->stripe->invoices->createPreview([
                'customer' => $customer,
                'subscription' => (string) $sub?->gateway_subscription_id,
                'subscription_items' => [
                    [
                        'id' => (string) $this->currentItemId($sub),
                        'price' => $this->remotePrice($to),
                    ],
                ],
                'proration_behavior' => 'always_invoice',
            ]);

            $lines = [];

            foreach ($upcoming->lines->data as $line) {
                $lines[] = new ProrationLineData(
                    description: (string) ($line->description ?? 'proration'),
                    amount: (int) $line->amount,
                    currency: strtoupper((string) $line->currency),
                );
            }

            return new ChangePreviewData(
                lines: $lines,
                totalDue: (int) ($upcoming->total ?? 0),
                currency: strtoupper($to->currency),
            );
        } catch (Throwable $e) {
            Log::error('Stripe gateway call failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            throw new PaymentProviderException(previous: $e);
        }
    }

    public function setCancelAtPeriodEnd(Organization $organization, bool $cancel): void
    {
        /** @var BillingSubscription|null $sub */
        $sub = $organization->subscription();

        if ($sub?->gateway_subscription_id !== null) {
            $this->stripe->subscriptions->update($sub->gateway_subscription_id, [
                'cancel_at_period_end' => $cancel,
            ]);
        }
    }

    public function paymentMethods(Organization $organization): array
    {
        $customer = $this->ensureCustomer($organization);
        $default = $this->defaultMethodId($organization);

        return array_map(
            fn ($pm): PaymentMethodData => new PaymentMethodData(
                id: $pm->id,
                brand: ucfirst((string) $pm->card?->brand),
                last4: (string) $pm->card?->last4,
                expMonth: (int) $pm->card?->exp_month,
                expYear: (int) $pm->card?->exp_year,
                isDefault: $pm->id === $default,
            ),
            iterator_to_array($this->stripe->paymentMethods->all([
                'customer' => $customer,
                'type' => 'card',
                'limit' => 24,
            ])->autoPagingIterator())
        );
    }

    public function setupIntent(Organization $organization): SetupIntentData
    {
        $remote = $this->stripe->setupIntents->create([
            'customer' => $this->ensureCustomer($organization),
            'payment_method_types' => ['card'],
        ]);

        return new SetupIntentData(
            clientSecret: (string) $remote->client_secret,
            url: 'https://payments.stripe.test/setup',
        );
    }

    public function attachPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $this->stripe->paymentMethods->attach($paymentMethodId, [
            'customer' => $this->ensureCustomer($organization),
        ]);
    }

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $customer = $this->ensureCustomer($organization);
        $gatewaySubId = $organization->subscription()?->gateway_subscription_id;

        if ($gatewaySubId !== null) {
            $this->stripe->subscriptions->update($gatewaySubId, ['default_payment_method' => $paymentMethodId]);
        }

        $this->stripe->customers->update($customer, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);
    }

    public function detachPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $this->stripe->paymentMethods->detach($paymentMethodId);
    }

    public function invoices(Organization $organization): array
    {
        $customer = $this->ensureCustomer($organization);

        return array_map(
            fn ($inv): GatewayInvoiceData => new GatewayInvoiceData(
                gatewayInvoiceId: $inv->id,
                status: (string) $inv->status,
                amountDue: (int) $inv->amount_due,
                currency: strtoupper((string) $inv->currency),
                hostedUrl: $inv->hosted_invoice_url ?: null,
                paidAt: isset($inv->status_transitions) && $inv->status_transitions->paid_at !== null
                    ? date(DATE_ATOM, (int) $inv->status_transitions->paid_at)
                    : null,
                dueAt: $inv->due_date !== null ? date(DATE_ATOM, (int) $inv->due_date) : null,
            ),
            iterator_to_array($this->stripe->invoices->all([
                'customer' => $customer,
                'limit' => 24,
            ])->autoPagingIterator())
        );
    }

    public function ingest(string $rawPayload, ?string $signature): array
    {
        $secret = (string) config('billing.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($rawPayload, (string) $signature, $secret);
        } catch (Throwable $e) {
            throw new InvalidWebhookException(previous: $e);
        }

        /** @var array{id: string, type: string, data: array<string, mixed>} $arr */
        $arr = json_decode($event->toJSON(), true);

        return $arr;
    }

    public function checkoutSession(string $sessionId): ?array
    {
        try {
            $session = $this->stripe->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);
        } catch (Throwable $e) {
            Log::error('Stripe gateway call failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            return null;
        }

        return [
            'id' => 'pulled_'.$sessionId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => json_decode((string) $session->toJSON(), true)],
        ];
    }

    /** @return array<int, array{id: string, type: string, data: array<string, mixed>}> */
    public function pullRecentEvents(): array
    {
        return array_map(
            fn ($event): array => [
                'id' => (string) $event->id,
                'type' => (string) $event->type,
                'data' => json_decode($event->toJSON(), true)['data'] ?? [],
            ],
            iterator_to_array($this->stripe->events->all(['limit' => 50])->autoPagingIterator())
        );
    }

    /* ───────────────────────────── internals ──────────────────────────── */

    private function checkout(Organization $organization, PlanPrice $price, int $trialDays): GatewayRedirectData
    {
        try {
            $customer = $this->ensureCustomer($organization);

            $remote = $this->stripe->checkout->sessions->create(array_filter([
                'mode' => 'subscription',
                'customer' => $customer,
                'line_items' => [['price' => $this->remotePrice($price), 'quantity' => 1]],
                'success_url' => config('billing.checkout.success_url'),
                'cancel_url' => config('billing.checkout.cancel_url'),
                'payment_method_collection' => 'always', // card required even on trial (AC-003.6)
                'subscription_data' => $trialDays > 0 ? ['trial_period_days' => $trialDays] : null,
                'metadata' => [
                    'org_id' => (string) $organization->id,
                    'plan_id' => (string) $price->plan_id,
                    'price_id' => (string) $price->id,
                    'interval' => $price->interval->value,
                    'trial_days' => (string) $trialDays,
                ],
            ]));

            return new GatewayRedirectData(url: (string) $remote->url, gatewayRef: (string) $remote->id);
        } catch (Throwable $e) {
            Log::error('Stripe gateway call failed', ['exception' => get_class($e), 'message' => $e->getMessage()]);

            throw new PaymentProviderException(previous: $e);
        }
    }

    public function mirrorPrice(PlanPrice $price): string
    {
        return $this->remotePrice($price);
    }

    private function remotePrice(PlanPrice $price): string
    {
        if ($price->gateway_price_id === null) {
            // lazily mirror this row into Stripe so mirror-step is optional
            $remote = $this->stripe->prices->create([
                'currency' => strtolower($price->currency),
                'unit_amount' => $price->amount,
                'recurring' => ['interval' => $price->interval->stripeInterval()],
                'product_data' => [
                    'name' => ($price->plan instanceof Plan ? $price->plan->name : 'Plan')." ({$price->currency}, {$price->interval->value})",
                ],
            ]);

            $price->forceFill(['gateway_price_id' => $remote->id])->save();

            return $remote->id;
        }

        return $price->gateway_price_id;
    }

    private function defaultMethodId(Organization $organization): ?string
    {
        $customer = $this->stripe->customers->retrieve($this->ensureCustomer($organization));

        $default = $customer->invoice_settings->default_payment_method ?? null;

        return is_string($default) ? $default : null;
    }

    private function currentItemId(?BillingSubscription $sub): ?string
    {
        if ($sub?->gateway_subscription_id === null) {
            return null;
        }

        $remote = $this->stripe->subscriptions->retrieve($sub->gateway_subscription_id);

        return isset($remote->items->data[0]) ? (string) $remote->items->data[0]->id : null;
    }
}
