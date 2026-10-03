<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\OrgRole;
use App\Events\Org\MemberRoleChanged;
use App\Exceptions\LastOwnerException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class UpdateMemberRoleAction
{
    public function handle(Organization $org, int $userId, OrgRole $role): User
    {
        $membership = $org->membershipFor($target = User::query()->find($userId) ?? throw (new ModelNotFoundException)->setModel(User::class, $userId));

        if ($membership === null) {
            throw (new ModelNotFoundException)->setModel(User::class, $userId);
        }

        if ($membership->role === OrgRole::Owner && $role !== OrgRole::Owner) {
            if ($org->memberships()->where('role', OrgRole::Owner->value)->count() <= 1) {
                throw new LastOwnerException;
            }
        }

        $membership->forceFill(['role' => $role->value])->save();

        event(new MemberRoleChanged($org, $target, $role));

        $target->refresh()->setRelation('membership', $membership->refresh());

        return $target;
    }
}
