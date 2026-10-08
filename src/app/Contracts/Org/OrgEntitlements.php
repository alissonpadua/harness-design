<?php

declare(strict_types=1);

namespace App\Contracts\Org;

use App\Models\Organization;
use App\Models\User;

/**
 * Tenancy entitlement seam. Config-backed until spec 003 rebinds it to DB
 * plans (interface intentionally minimal so the rebind is one line).
 */
interface OrgEntitlements
{
    public function maxTeams(User $user): int;

    public function maxMembers(Organization $organization): int;

    /**
     * Effective audit_retention_days (0 = org audit feed disabled, spec 005).
     */
    public function auditRetentionDays(Organization $organization): int;
}
