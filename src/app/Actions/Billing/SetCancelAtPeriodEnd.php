<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

final readonly class SetCancelAtPeriodEnd
{
    public function __construct(
        private PaymentGateway $gateway,
        private EnsureOrgSubscription $ensureSub,
    ) {}

    public function handle(Organization $org, bool $cancel): void
    {
        $sub = $this->ensureSub->handle($org);

        if ($sub->plan === null || $sub->plan->isFree()) {
            throw ValidationException::withMessages(['cancel' => ['The free plan cannot be cancelled.']]);
        }

        if ($sub->cancel_at_period_end === $cancel) {
            throw ValidationException::withMessages(['cancel' => [$cancel ? 'Already scheduled.' : 'Nothing to resume.']]);
        }

        $this->gateway->setCancelAtPeriodEnd($org, $cancel);

        $sub->forceFill(['cancel_at_period_end' => $cancel])->save();
    }
}
