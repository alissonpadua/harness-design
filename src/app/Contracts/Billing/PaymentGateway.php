<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\Data\Billing\ChangePreviewData;
use App\Data\Billing\GatewayInvoiceData;
use App\Data\Billing\GatewayRedirectData;
use App\Data\Billing\PaymentMethodData;
use App\Data\Billing\SetupIntentData;
use App\Enums\BillingInterval;
use App\Exceptions\InvalidWebhookException;
use App\Models\Organization;
use App\Models\PlanPrice;

/**
 * The swappable money boundary (ADR-0003). App code NEVER touches a payment
 * SDK directly; Stripe/Fake live behind this contract only.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Ensure a remote customer exists; returns gateway customer id. */
    public function ensureCustomer(Organization $organization): string;

    public function checkoutUrl(Organization $organization, PlanPrice $price): GatewayRedirectData;

    /** Card-required trial checkout (spec AC-003.6). */
    public function trialCheckoutUrl(Organization $organization, PlanPrice $price, int $trialDays): GatewayRedirectData;

    /** Immediate paid switch (cancel old remote sub, create new). */
    public function applyChange(Organization $organization, PlanPrice $from, PlanPrice $to): GatewayRedirectData;

    /** Cancel current remote subscription (free floor). */
    public function cancel(Organization $organization): void;

    public function previewChange(Organization $organization, PlanPrice $from, PlanPrice $to, BillingInterval $interval): ChangePreviewData;

    public function setCancelAtPeriodEnd(Organization $organization, bool $cancel): void;

    /** @return array<int, PaymentMethodData> */
    public function paymentMethods(Organization $organization): array;

    public function setupIntent(Organization $organization): SetupIntentData;

    public function attachPaymentMethod(Organization $organization, string $paymentMethodId): void;

    public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void;

    public function detachPaymentMethod(Organization $organization, string $paymentMethodId): void;

    /** @return array<int, GatewayInvoiceData> */
    public function invoices(Organization $organization): array;

    /**
     * Verify + parse a raw webhook payload.
     *
     * @return array{id: string, type: string, data: array<string, mixed>}
     *
     * @throws InvalidWebhookException
     */
    public function ingest(string $rawPayload, ?string $signature): array;

    /**
     * Fetch a completed checkout session by id (tunnel-less return path).
     * Returns a normalized webhook-shaped event or null when unknown.
     *
     * @return null|array{id: string, type: string, data: array<string, mixed>}
     */
    public function checkoutSession(string $sessionId): ?array;

    /** Dev-only (no tunnel): recent events straight from the API, unsigned but trusted.
     *
     * @return array<int, array{id: string, type: string, data: array<string, mixed>}> */
    public function pullRecentEvents(): array;

    /** Ensure a remote price exists for the row (idempotent); returns gateway price id. */
    public function mirrorPrice(PlanPrice $price): string;
}
