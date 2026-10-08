<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $organization_id
 * @property array<string, mixed> $overrides
 */
class OrganizationEntitlementOverride extends Model
{
    protected $fillable = ['organization_id', 'overrides'];

    protected function casts(): array
    {
        return [
            'overrides' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
