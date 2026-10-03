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
 * @property OrgRole $role
 * @property Carbon|null $expires_at
 * @property int|null $max_uses
 * @property int $uses
 * @property Carbon|null $used_at
 */
class OrganizationInviteLink extends Model
{
    use BelongsToOrganization;
    use HasHashedTokens;

    protected $fillable = ['organization_id', 'role', 'token_hash', 'created_by', 'expires_at', 'max_uses', 'uses', 'used_at'];

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
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses < $this->max_uses);
    }
}
