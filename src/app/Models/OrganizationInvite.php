<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgRole;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasHashedTokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $email
 * @property OrgRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class OrganizationInvite extends Model
{
    use BelongsToOrganization;
    use HasHashedTokens;

    protected $fillable = ['organization_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'used_at'];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding($query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    protected function casts(): array
    {
        return [
            'role' => OrgRole::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
