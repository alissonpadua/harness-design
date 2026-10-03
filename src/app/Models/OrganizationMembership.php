<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $organization_id
 * @property int $user_id
 * @property OrgRole $role
 * @property MemberStatus $status
 */
class OrganizationMembership extends Pivot
{
    protected $table = 'organization_user';

    protected $fillable = ['organization_id', 'user_id', 'role', 'status', 'invited_by'];

    protected function casts(): array
    {
        return [
            'role' => OrgRole::class,
            'status' => MemberStatus::class,
        ];
    }
}
