<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property string $gateway_invoice_id
 * @property string $status
 * @property int $amount_due
 * @property string $currency
 * @property string|null $hosted_url
 * @property Carbon|null $paid_at
 * @property Carbon|null $due_at
 */
class BillingInvoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = [
        'organization_id', 'gateway_invoice_id', 'status', 'amount_due',
        'currency', 'hosted_url', 'paid_at', 'due_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'integer',
            'paid_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
