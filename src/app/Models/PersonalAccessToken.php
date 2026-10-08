<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sanctum PAT subclass (spec 006): organization_id + kind mark integration
 * tokens (org-owned, long-lived, ability-scoped) vs device tokens (001).
 * Registered via Sanctum::usePersonalAccessTokenModel() in AppServiceProvider.
 */
class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'device_type', 'organization_id', 'kind', 'impersonator_id'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isIntegration(): bool
    {
        return $this->getAttribute('kind') === 'integration';
    }
}
