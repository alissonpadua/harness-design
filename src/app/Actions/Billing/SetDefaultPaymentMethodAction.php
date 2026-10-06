<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Models\Organization;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class SetDefaultPaymentMethodAction
{
    public function __construct(private PaymentGateway $gateway) {}

    public function handle(Organization $org, string $paymentMethodId): void
    {
        $methods = $this->gateway->paymentMethods($org);

        if (! in_array($paymentMethodId, array_map(fn ($m) => $m->id, $methods), true)) {
            throw new NotFoundHttpException;
        }

        $this->gateway->setDefaultPaymentMethod($org, $paymentMethodId);
    }
}
