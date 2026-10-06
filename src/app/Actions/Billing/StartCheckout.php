<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class StartCheckout
{
    public function __construct(private PaymentGateway $gateway) {}

    /**
     * @return array{url: string, plan_code: string, interval: string}
     */
    public function handle(Organization $org, string $planCode, string $interval, string $currency = 'USD'): array
    {
        /** @var Plan|null $plan */
        $plan = Plan::query()->where('code', $planCode)->where('active', true)->first();

        if ($plan === null) {
            throw new NotFoundHttpException;
        }

        if ($plan->isFree()) {
            throw ValidationException::withMessages(['plan_code' => ['The free plan needs no checkout.']]);
        }

        $sub = $org->subscription();

        if ($sub !== null && $sub->status !== SubscriptionStatus::Canceled && ! $sub->plan?->isFree()) {
            throw ValidationException::withMessages(['plan_code' => ['Already subscribed — use plan change instead.']]);
        }

        $price = $plan->price(strtoupper($currency), $interval)
            ?? throw ValidationException::withMessages(['currency' => ["No {$currency}/{$interval} price for this plan."]]);

        $redirect = $plan->trial_days > 0
            ? $this->gateway->trialCheckoutUrl($org, $price, $plan->trial_days)
            : $this->gateway->checkoutUrl($org, $price);

        return [
            'url' => $redirect->url,
            'plan_code' => $plan->code,
            'interval' => BillingInterval::from($interval)->value,
        ];
    }
}
