<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $organization_id
 * @property string $gateway
 * @property string|null $gateway_customer_id
 */
class BillingCustomer extends Model
{
    protected $fillable = ['organization_id', 'gateway', 'gateway_customer_id'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
