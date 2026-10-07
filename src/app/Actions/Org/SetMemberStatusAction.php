<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\MemberStatus;
use App\Enums\OrgRole;
use App\Events\Org\MemberSuspended;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class SetMemberStatusAction
{
    public function handle(Organization $org, int $userId, MemberStatus $status): void
    {
        $membership = $org->memberships()->where('user_id', $userId)->first()
            ?? throw (new ModelNotFoundException)->setModel(User::class, $userId);

        if ($membership->role === OrgRole::Owner && $status === MemberStatus::Suspended) {
            throw new HttpException(403, 'Owners cannot be suspended.');
        }

        $membership->forceFill(['status' => $status->value])->save();

        if ($status === MemberStatus::Suspended) {
            event(new MemberSuspended($org, User::query()->findOrFail($userId)));
        }
    }
}
