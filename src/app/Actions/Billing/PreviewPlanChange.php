<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Data\Billing\ChangePreviewData;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PreviewPlanChange
{
    public function __construct(private PaymentGateway $gateway) {}

    public function handle(Organization $org, string $planCode, string $currency = 'USD'): ChangePreviewData
    {
        /** @var Plan|null $target */
        $target = Plan::query()->where('code', $planCode)->where('active', true)->first();

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        $sub = $org->subscription() ?? throw ValidationException::withMessages(['plan_code' => ['No active subscription.']]);
        $interval = $sub->interval->value;

        $from = $sub->plan?->price($currency, $interval)
                ?? throw ValidationException::withMessages(['currency' => ["Missing current price ({$currency})."]]);
        $to = $target->price($currency, $interval)
            ?? throw ValidationException::withMessages(['currency' => ["Missing target price ({$currency})."]]);

        return $this->gateway->previewChange($org, $from, $to, $sub->interval);
    }
}
