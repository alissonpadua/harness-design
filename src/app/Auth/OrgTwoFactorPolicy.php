<?php

declare(strict_types=1);

namespace App\Auth;

use App\Contracts\TwoFactorPolicy;
use App\Enums\MemberStatus;
use App\Models\User;

/**
 * AC-002.18: 2FA is mandatory when the user's CURRENT organization has
 * require_2fa enabled and their membership is active.
 */
final readonly class OrgTwoFactorPolicy implements TwoFactorPolicy
{
    public function requires(User $user): bool
    {
        $org = $user->currentOrganization;

        if ($org === null || ! $org->require_2fa) {
            return false;
        }

        return $org->membershipFor($user)?->status === MemberStatus::Active;
    }
}
