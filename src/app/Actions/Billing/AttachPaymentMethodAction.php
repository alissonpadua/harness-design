<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Models\Organization;

final readonly class AttachPaymentMethodAction
{
    public function __construct(private PaymentGateway $gateway) {}

    public function handle(Organization $org, string $paymentMethodId): void
    {
        $this->gateway->attachPaymentMethod($org, $paymentMethodId);
    }
}
