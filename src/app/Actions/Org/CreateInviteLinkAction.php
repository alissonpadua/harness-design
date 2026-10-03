<?php

declare(strict_types=1);

namespace App\Actions\Org;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationInviteLink;
use App\Models\User;

final readonly class CreateInviteLinkAction
{
    public function handle(User $creator, Organization $org, OrgRole $role, ?int $expiresInDays, ?int $maxUses): OrganizationInviteLink
    {
        $link = OrganizationInviteLink::makeWithToken();
        $link->forceFill([
            'organization_id' => $org->id,
            'role' => $role,
            'created_by' => $creator->id,
            'expires_at' => $expiresInDays !== null
                ? now()->addDays($expiresInDays)
                : now()->addDays((int) config('tenancy.invites.link_max_ttl_days')),
            'max_uses' => $maxUses,
        ])->save();

        return $link;
    }
}
