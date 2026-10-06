<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property string $gateway
 * @property string|null $gateway_subscription_id
 * @property BillingInterval $interval
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $past_due_since
 * @property Carbon|null $over_limit_until
 */
class BillingSubscription extends Model
{
    protected $table = 'subscriptions';

    protected $fillable = [
        'organization_id', 'plan_id', 'status', 'gateway', 'gateway_subscription_id',
        'interval', 'current_period_end', 'trial_end', 'cancel_at_period_end',
        'past_due_since', 'over_limit_until',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'interval' => BillingInterval::class,
            'current_period_end' => 'datetime',
            'trial_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'past_due_since' => 'datetime',
            'over_limit_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isOverLimit(): bool
    {
        return $this->over_limit_until !== null;
    }
}
