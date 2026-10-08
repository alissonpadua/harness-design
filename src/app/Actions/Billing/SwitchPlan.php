<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Billing\Events\SubscriptionPlanChanged;
use App\Contracts\Billing\PaymentGateway;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Immediate plan switch (AC-003.15) incl. free floor (AC-003.7).
 */
final readonly class SwitchPlan
{
    public function __construct(
        private PaymentGateway $gateway,
        private EnsureOrgSubscription $ensureSub,
        private EvaluateOverLimit $evaluate,
    ) {}

    public function handle(Organization $org, string $planCode, string $currency = 'USD'): void
    {
        /** @var Plan|null $target */
        $target = Plan::query()->where('code', $planCode)->where('active', true)->first();

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        $sub = $this->ensureSub->handle($org);

        if ($sub->plan_id === $target->id) {
            throw ValidationException::withMessages(['plan_code' => ['Already on this plan.']]);
        }

        $from = $sub->plan ?? throw ValidationException::withMessages(['plan_code' => ['Corrupt subscription.']]);

        if ($target->isFree()) {
            $this->gateway->cancel($org);

            $sub->forceFill([
                'plan_id' => $target->id,
                'status' => SubscriptionStatus::Active,
                'gateway_subscription_id' => null,
                'admin_locked' => false, // the org acting itself supersedes any admin grant
                'trial_end' => null,
                'cancel_at_period_end' => false,
                'past_due_since' => null,
            ])->save();
        } else {
            $fromPrice = $this->price($from, $currency, $sub->interval->value);
            $toPrice = $this->price($target, $currency, $sub->interval->value);
            $redirect = $this->gateway->applyChange($org, $fromPrice, $toPrice);

            $sub->forceFill([
                'plan_id' => $target->id,
                'status' => SubscriptionStatus::Active,
                'admin_locked' => false,
                'gateway_subscription_id' => $redirect->gatewayRef,
                'current_period_end' => $sub->interval === BillingInterval::Annual ? now()->addYear() : now()->addMonth(),
                'trial_end' => null,
                'cancel_at_period_end' => false,
            ])->save();
        }

        $this->evaluate->handle($sub->refresh());

        SubscriptionPlanChanged::dispatch($sub->refresh());
    }

    private function price(Plan $plan, string $currency, string $interval): PlanPrice
    {
        return $plan->price($currency, $interval)
            ?? throw ValidationException::withMessages(['currency' => ["Missing price: {$plan->code}/{$currency}/{$interval}."]]);
    }
}
