<?php

declare(strict_types=1);

namespace App\Contracts\Org;

use App\Models\Organization;
use App\Models\User;

final readonly class ConfigOrgEntitlements implements OrgEntitlements
{
    public function maxTeams(User $user): int
    {
        return (int) config('tenancy.limits.max_teams');
    }

    public function maxMembers(Organization $organization): int
    {
        return (int) config('tenancy.limits.max_members');
    }
}
