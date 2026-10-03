<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Contracts\Org\OrgEntitlements;
use App\Enums\MemberStatus;
use App\Events\Org\MemberJoined;
use App\Exceptions\OrgTokenException;
use App\Exceptions\SubscriptionRequiredException;
use App\Models\Organization;
use App\Models\OrganizationInviteLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class JoinInviteLinkAction
{
    public function __construct(private OrgEntitlements $entitlements) {}

    public function handle(User $user, string $token): Organization
    {
        $found = OrganizationInviteLink::findByRawToken($token);

        if ($found === null) {
            throw new OrgTokenException;
        }

        /** @var Organization $org */
        $org = $found->organization;

        return DB::transaction(function () use ($user, $found, $org): Organization {
            /** @var OrganizationInviteLink $link */
            $link = OrganizationInviteLink::query()->withoutGlobalScopes()->whereKey($found->id)->lockForUpdate()->firstOrFail();

            if ($link->isUsable() === false) {
                throw new OrgTokenException;
            }

            // idempotent for existing members (no usage counter change)
            if ($org->membershipFor($user) !== null) {
                return $org;
            }

            if ($org->members()->count() >= $this->entitlements->maxMembers($org)) {
                throw new SubscriptionRequiredException('Member limit reached.');
            }

            $org->memberships()->create([
                'user_id' => $user->id,
                'role' => $link->role->value,
                'status' => MemberStatus::Active->value,
                'invited_by' => $link->created_by,
            ]);

            $link->increment('uses');

            event(new MemberJoined($org, $user));

            return $org;
        });
    }
}
