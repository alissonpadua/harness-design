<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\MemberStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Pivot-role permission seam (spec 002). Reads the explicit map in
 * config/org_roles.php; owner carries the '*' wildcard.
 */
final readonly class OrgAuthorizer
{
    public function membership(User $user, Organization $organization): ?OrganizationMembership
    {
        return $organization->membershipFor($user);
    }

    public function can(User $user, Organization $organization, string $permission): bool
    {
        $membership = $this->membership($user, $organization);

        if ($membership === null || $membership->status !== MemberStatus::Active) {
            return false;
        }

        $grants = config('org_roles.'.$membership->role->value, []);

        return in_array('*', $grants, true) || in_array($permission, $grants, true);
    }
}
