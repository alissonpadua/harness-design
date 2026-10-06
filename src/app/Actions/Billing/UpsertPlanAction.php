<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Data\Plan\PlanEntitlementsData;
use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final readonly class UpsertPlanAction
{
    /**
     * @param  array<string, mixed>  $data  validated admin payload
     */
    public function create(array $data): Plan
    {
        $plan = Plan::create([
            'code' => (string) Arr::get($data, 'code'),
            'name' => (string) Arr::get($data, 'name'),
            'trial_days' => (int) Arr::get($data, 'trial_days', 0),
            'active' => (bool) Arr::get($data, 'active', true),
            'entitlements' => PlanEntitlementsData::from((array) Arr::get($data, 'entitlements')),
        ]);

        foreach ((array) Arr::get($data, 'prices', []) as $price) {
            $this->putPrice($plan, (array) $price);
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Plan $plan, array $data): Plan
    {
        $plan->forceFill(array_filter([
            'name' => Arr::has($data, 'name') ? (string) $data['name'] : null,
            'trial_days' => Arr::has($data, 'trial_days') ? (int) $data['trial_days'] : null,
            'active' => Arr::has($data, 'active') ? (bool) $data['active'] : null,
        ], static fn ($v): bool => $v !== null));

        if (Arr::has($data, 'entitlements')) {
            $plan->entitlements = PlanEntitlementsData::from((array) $data['entitlements']);
        }

        $plan->save();

        foreach ((array) Arr::get($data, 'prices', []) as $price) {
            $this->putPrice($plan, (array) $price);
        }

        return $plan->refresh();
    }

    /**
     * @param  array<string, mixed>  $price
     */
    private function putPrice(Plan $plan, array $price): void
    {
        $currency = strtoupper((string) Arr::get($price, 'currency'));
        $interval = (string) Arr::get($price, 'interval');

        if (! in_array($interval, array_column(BillingInterval::cases(), 'value'), true)) {
            throw ValidationException::withMessages(['prices' => ["Invalid interval: {$interval}"]]);
        }

        PlanPrice::updateOrCreate(
            ['plan_id' => $plan->id, 'currency' => $currency, 'interval' => $interval],
            [
                'amount' => (int) Arr::get($price, 'amount'),
                'gateway_price_id' => Arr::has($price, 'gateway_price_id')
                    ? (string) Arr::get($price, 'gateway_price_id')
                    : null,
            ]
        );
    }
}
