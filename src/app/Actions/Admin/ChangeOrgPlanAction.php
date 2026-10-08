<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Billing\EnsureOrgSubscription;
use App\Actions\Billing\EvaluateOverLimit;
use App\Audit\AuditSecurityEvent;
use App\Billing\Events\SubscriptionPlanChanged;
use App\Contracts\Billing\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Q6=B: admin grants swap the local plan AND cancel the remote subscription
 * (real billing stops); admin_locked shields the grant from late webhooks.
 */
final readonly class ChangeOrgPlanAction
{
    public function __construct(
        private PaymentGateway $gateway,
        private EnsureOrgSubscription $ensureSub,
        private EvaluateOverLimit $evaluate,
        private AuditSecurityEvent $audit,
    ) {}

    public function handle(User $actor, Organization $org, string $planCode): Plan
    {
        /** @var Plan|null $target */
        $target = Plan::query()->where('code', $planCode)->where('active', true)->first();

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        $sub = $this->ensureSub->handle($org);
        $previous = $sub->plan instanceof Plan ? $sub->plan->code : 'free';

        if ($previous === $target->code) {
            throw ValidationException::withMessages(['plan_code' => ['Organization is already on this plan.']]);
        }

        $detached = $sub->gateway_subscription_id;

        if (! ($sub->plan instanceof Plan && $sub->plan->isFree())) {
            $this->gateway->cancel($org); // Q6: remote dies immediately
        }

        $sub->forceFill([
            'plan_id' => $target->id,
            'status' => SubscriptionStatus::Active,
            'trial_end' => null,
            'cancel_at_period_end' => false,
            'past_due_since' => null,
            'admin_locked' => true,
        ])->save();

        $this->evaluate->handle($sub->refresh());

        $this->audit->log('org_plan_change', $actor, $org, [
            'from' => $previous, 'to' => $target->code, 'detached_gateway_subscription' => $detached,
        ]);

        SubscriptionPlanChanged::dispatch($sub->refresh());

        return $target;
    }
}
