<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DetachPaymentMethodAction
{
    public function __construct(private PaymentGateway $gateway) {}

    public function handle(Organization $org, string $paymentMethodId): void
    {
        $methods = $this->gateway->paymentMethods($org);
        $target = null;

        foreach ($methods as $method) {
            if ($method->id === $paymentMethodId) {
                $target = $method;
            }
        }

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        $sub = $org->subscription();
        $paidActive = $sub !== null
            && $sub->plan instanceof Plan
            && ! $sub->plan->isFree()
            && in_array($sub->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true);

        if ($target->isDefault && $paidActive) {
            throw ValidationException::withMessages([
                'payment_method' => ['Set another method as default before removing this one.'],
            ]);
        }

        $this->gateway->detachPaymentMethod($org, $paymentMethodId);
    }
}
