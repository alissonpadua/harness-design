<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Billing\PaymentGateway;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Console\Command;

/**
 * Provisions Stripe products/prices for every seeded/admin plan price row and
 * writes gateway_price_id back (spec 003 verification step 1).
 */
final class BillingMirrorCommand extends Command
{
    protected $signature = 'billing:stripe-mirror';

    protected $description = 'Create/attach remote prices for all local plan_prices rows';

    public function handle(PaymentGateway $gateway): int
    {
        PlanPrice::query()->each(function (PlanPrice $price) use ($gateway): void {
            $remoteId = $gateway->mirrorPrice($price);
            $price->forceFill(['gateway_price_id' => $remoteId])->save();
            $this->line(sprintf('%s %s/%s → %s', $price->plan instanceof Plan ? $price->plan->code : '?', $price->currency, $price->interval->value, $remoteId));
        });

        return self::SUCCESS;
    }
}
