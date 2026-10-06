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
use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Str;

/**
 * Deterministic, zero-network gateway for tests/dev (spec 003 S6).
 * Emits Stripe-shaped, HMAC-signed payloads so the REAL webhook pipeline
 * (verification, idempotency, normalization) is exercised end-to-end.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var array<int, string> */
    public array $customers = [];

    /** @var array<int, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<int, array<int, PaymentMethodData>> */
    public array $methods = [];

    /** @var array<int, array<int, GatewayInvoiceData>> */
    public array $invoices = [];

    /** @var array<int, array{id: string, type: string, data: array<string, mixed>}> */
    public array $pulled = [];

    public function name(): string
    {
        return 'fake';
    }

    public function ensureCustomer(Organization $organization): string
    {
        if (! isset($this->customers[$organization->id])) {
            $this->customers[$organization->id] = 'fake_cus_'.$organization->id;
            BillingCustomer::updateOrCreate(
                ['organization_id' => $organization->id],
                ['gateway' => 'fake', 'gateway_customer_id' => $this->customers[$organization->id]]
            );
        }

        return $this->customers[$organization->id];
    }

    public function checkoutUrl(Organization $organization, PlanPrice $price): GatewayRedirectData
    {
        return new GatewayRedirectData(
            url: 'https://checkout.fake.test/'.Str::random(8),
            gatewayRef: 'fake_cs_'.Str::random(8),
        );
    }

    public function trialCheckoutUrl(Organization $organization, PlanPrice $price, int $trialDays): GatewayRedirectData
    {
        return $this->checkoutUrl($organization, $price);
    }

    public function applyChange(Organization $organization, PlanPrice $from, PlanPrice $to): GatewayRedirectData
    {
        $subId = 'fake_sub_'.Str::random(6);
        $this->subscriptions[$organization->id] = [
            'id' => $subId,
            'price' => $to->gateway_price_id ?? 'fake_price',
            'status' => 'active',
            'cancel_at_period_end' => false,
        ];

        return new GatewayRedirectData(url: '', gatewayRef: $subId);
    }

    public function cancel(Organization $organization): void
    {
        unset($this->subscriptions[$organization->id]);
    }

    public function previewChange(Organization $organization, PlanPrice $from, PlanPrice $to, BillingInterval $interval): ChangePreviewData
    {
        $remaining = 0.5; // deterministic: half the period left
        $credit = -(int) round($from->amount * $remaining);
        $charge = (int) round($to->amount * $remaining);
        $currency = strtoupper($to->currency);

        return new ChangePreviewData(
            lines: [
                new ProrationLineData('Unused time on '.($from->plan instanceof Plan ? $from->plan->name : 'current'), $credit, $currency),
                new ProrationLineData('Remaining time on '.($to->plan instanceof Plan ? $to->plan->name : 'target'), $charge, $currency),
            ],
            totalDue: (int) $credit + $charge,
            currency: $currency,
        );
    }

    public function setCancelAtPeriodEnd(Organization $organization, bool $cancel): void
    {
        if (isset($this->subscriptions[$organization->id])) {
            $this->subscriptions[$organization->id]['cancel_at_period_end'] = $cancel;
        }
    }

    /** @return array<int, PaymentMethodData> */
    public function paymentMethods(Organization $organization): array
    {
        return $this->methods[$organization->id] ??= [
            new PaymentMethodData('fake_pm_visa', 'visa', '4242', 12, 2030, true),
        ];
    }

    public function setupIntent(Organization $organization): SetupIntentData
    {
        return new SetupIntentData(
            clientSecret: 'fake_setup_seti_'.Str::random(8).'_secret',
            url: 'https://payments.fake.test/setup',
        );
    }

    public function attachPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $list = $this->paymentMethods($organization);
        $list[] = new PaymentMethodData($paymentMethodId, 'amex', '0005', 1, 2031, false);
        $this->methods[$organization->id] = $list;
    }

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $this->methods[$organization->id] = array_map(
            fn (PaymentMethodData $pm): PaymentMethodData => $pm->id === $paymentMethodId
                ? new PaymentMethodData($pm->id, $pm->brand, $pm->last4, $pm->expMonth, $pm->expYear, true)
                : new PaymentMethodData($pm->id, $pm->brand, $pm->last4, $pm->expMonth, $pm->expYear, false),
            $this->paymentMethods($organization)
        );
    }

    public function detachPaymentMethod(Organization $organization, string $paymentMethodId): void
    {
        $this->methods[$organization->id] = array_values(array_filter(
            $this->paymentMethods($organization),
            fn (PaymentMethodData $pm): bool => $pm->id !== $paymentMethodId
        ));
    }

    /** @return array<int, GatewayInvoiceData> */
    public function invoices(Organization $organization): array
    {
        return $this->invoices[$organization->id] ??= [];
    }

    public function ingest(string $rawPayload, ?string $signature): array
    {
        $secret = (string) config('billing.fake_webhook_secret');

        if ($signature === null || ! hash_equals(self::sign($rawPayload, $secret), $signature)) {
            throw new InvalidWebhookException('Invalid webhook signature.');
        }

        $event = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new InvalidWebhookException('Malformed webhook payload.');
        }

        return [
            'id' => (string) $event['id'],
            'type' => (string) $event['type'],
            'data' => (array) ($event['data'] ?? []),
        ];
    }

    /** @return array<int, array{id: string, type: string, data: array<string, mixed>}> */
    public function pullRecentEvents(): array
    {
        return $this->pulled;
    }

    /** @var array<string, array{id: string, type: string, data: array<string, mixed>}> */
    public array $sessions = [];

    public function checkoutSession(string $sessionId): ?array
    {
        return $this->sessions[$sessionId] ?? null;
    }

    /* ─────────────────────────── test helpers ─────────────────────────── */

    /**
     * Emit a Stripe-shaped event; returns [rawPayload, signature] ready for the HTTP route.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    public function emit(string $type, array $data): array
    {
        $event = [
            'id' => 'fake_evt_'.Str::random(8),
            'type' => $type,
            'data' => ['object' => $data],
        ];

        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $this->pulled[] = $event;

        return [$raw, self::sign($raw, (string) config('billing.fake_webhook_secret'))];
    }

    /** @return array{0: string, 1: string} */
    public function checkoutCompletedPayload(Organization $org, int $planId, int $priceId, string $interval, int $trialDays = 0): array
    {
        $subId = 'fake_sub_'.Str::random(6);
        $this->subscriptions[$org->id] = ['id' => $subId, 'price' => (string) $priceId, 'status' => 'active', 'cancel_at_period_end' => false];

        return $this->emit('checkout.session.completed', [
            'id' => 'fake_cs_done_'.Str::random(4),
            'object' => 'checkout.session',
            'customer' => $this->ensureCustomer($org),
            'subscription' => $subId,
            'metadata' => [
                'org_id' => (string) $org->id,
                'plan_id' => (string) $planId,
                'price_id' => (string) $priceId,
                'interval' => $interval,
                'trial_days' => (string) $trialDays,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: string, 1: string}
     */
    public function invoiceEvent(Organization $org, string $type, array $overrides = []): array
    {
        return $this->emit($type, array_merge([
            'id' => 'fake_in_'.Str::random(6),
            'object' => 'invoice',
            'customer' => $this->ensureCustomer($org),
            'subscription' => $this->subscriptions[$org->id]['id'] ?? 'fake_sub_x',
            'amount_due' => 1900,
            'currency' => 'usd',
            'status' => $type === 'invoice.paid' ? 'paid' : 'open',
            'hosted_invoice_url' => 'https://invoices.fake.test/'.Str::random(6),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: string, 1: string}
     */
    public function subscriptionUpdated(Organization $org, array $overrides = []): array
    {
        $state = $this->subscriptions[$org->id] ?? ['id' => 'fake_sub_x'];

        return $this->emit('customer.subscription.updated', array_merge([
            'id' => $state['id'],
            'object' => 'subscription',
            'customer' => $this->ensureCustomer($org),
            'status' => 'active',
            'current_period_end' => now()->addMonth()->getTimestamp(),
            'trial_end' => null,
            'cancel_at_period_end' => $state['cancel_at_period_end'] ?? false,
        ], $overrides));
    }

    public function mirrorPrice(PlanPrice $price): string
    {
        return 'fake_price_'.$price->id;
    }

    private static function sign(string $payload, string $secret): string
    {
        return 'v1='.hash_hmac('sha256', $payload, $secret);
    }
}
