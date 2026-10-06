<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $plan_id
 * @property string $currency
 * @property BillingInterval $interval
 * @property int $amount
 * @property string|null $gateway_price_id
 */
class PlanPrice extends Model
{
    protected $fillable = ['plan_id', 'currency', 'interval', 'amount', 'gateway_price_id'];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
