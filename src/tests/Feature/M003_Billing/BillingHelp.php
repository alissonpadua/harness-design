<?php

declare(strict_types=1);

namespace Tests\Feature\M003_Billing;

use App\Actions\Billing\EnsureOrgSubscription;
use App\Actions\Org\CreateOrganizationAction;
use App\Billing\FakeGateway;
use App\Contracts\Billing\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\User;
use Tests\Support\Tenancy;

/**
 * Shared helpers for M003 feature tests.
 */
final class BillingHelp
{
    /**
     * Fresh user with an HTTP-created team (free plan via action, then optionally pro).
     *
     * @return array{0: User, 1: string, 2: Organization}
     */
    public static function userOrg(string $planCode = 'pro'): array
    {
        [$ada, $token] = Tenancy::user();
        $org = app(CreateOrganizationAction::class)->handle($ada, 'PayCo');
        Tenancy::switchTo($ada, $org);

        if ($planCode !== 'free') {
            self::attachPlan($org, $planCode);
        }

        return [$ada, $token, $org];
    }

    public static function attachPlan(Organization $org, string $planCode): void
    {
        $sub = app(EnsureOrgSubscription::class)->handle($org);
        $sub->forceFill([
            'plan_id' => Plan::query()->where('code', $planCode)->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
        ])->save();
    }

    public static function gateway(): FakeGateway
    {
        \assert(app(PaymentGateway::class) instanceof FakeGateway);

        return app(PaymentGateway::class);
    }

    public static function price(string $code, string $currency = 'USD', string $interval = 'monthly'): PlanPrice
    {
        $price = Plan::query()->where('code', $code)->firstOrFail()->price($currency, $interval);

        \assert($price instanceof PlanPrice);

        return $price;
    }

    public static function as(string $token)
    {
        test()->flushHeaders();
        app('auth')->forgetGuards();

        return test()->withToken($token);
    }
}
