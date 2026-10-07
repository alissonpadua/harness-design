<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\OrgRole;
use App\Enums\OrgType;
use App\Events\Org\MemberRemoved;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final readonly class RemoveMemberAction
{
    public function handle(Organization $org, int $userId): void
    {
        $membership = $org->memberships()->where('user_id', $userId)->first()
            ?? throw (new ModelNotFoundException)->setModel(User::class, $userId);

        // Any owner membership survives this endpoint regardless of status (audit T0: suspend->evict chain).
        if ($membership->role === OrgRole::Owner) {
            throw ValidationException::withMessages(['user' => ['The organization must keep an owner.']]);
        }

        $membership->delete();

        event(new MemberRemoved($org));

        $target = User::withTrashed()->find($userId);

        if ($target !== null && $target->current_organization_id === $org->id) {
            $personal = Organization::query()
                ->where('owner_id', $target->id)
                ->where('type', OrgType::Personal)
                ->first();

            $target->forceFill(['current_organization_id' => $personal?->id])->save();
        }
    }
}
