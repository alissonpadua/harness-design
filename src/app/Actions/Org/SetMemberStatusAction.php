<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\MemberStatus;
use App\Events\Org\MemberSuspended;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class SetMemberStatusAction
{
    public function handle(Organization $org, int $userId, MemberStatus $status): void
    {
        $membership = $org->memberships()->where('user_id', $userId)->first()
            ?? throw (new ModelNotFoundException)->setModel(User::class, $userId);

        $membership->forceFill(['status' => $status->value])->save();

        if ($status === MemberStatus::Suspended) {
            event(new MemberSuspended($org, User::query()->findOrFail($userId)));
        }
    }
}
