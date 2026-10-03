<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\MemberStatus;
use App\Events\Org\MemberJoined;
use App\Exceptions\OrgTokenException;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;

final readonly class AcceptInviteAction
{
    public function handle(User $user, string $token): Organization
    {
        $invite = OrganizationInvite::findByRawToken($token);

        if ($invite === null || ! $invite->isUsable() || $invite->email !== strtolower($user->email)) {
            throw new OrgTokenException;
        }

        /** @var Organization $org */
        $org = $invite->organization;

        $org->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['role' => $invite->role->value, 'status' => MemberStatus::Active->value, 'invited_by' => $invite->invited_by],
        );

        $invite->forceFill(['used_at' => now()])->save();

        event(new MemberJoined($org, $user));

        return $org;
    }
}
